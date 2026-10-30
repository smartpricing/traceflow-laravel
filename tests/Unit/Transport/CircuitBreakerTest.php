<?php

namespace Smartness\TraceFlow\Tests\Unit\Transport;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Repository as RepositoryContract;
use PHPUnit\Framework\TestCase;
use Smartness\TraceFlow\DTO\TraceEvent;
use Smartness\TraceFlow\Enums\TraceEventType;
use Smartness\TraceFlow\Transport\AbstractHttpTransport;
use Smartness\TraceFlow\Transport\CacheCircuitBreakerStore;
use Smartness\TraceFlow\Transport\CircuitBreaker;
use Smartness\TraceFlow\Transport\CircuitBreakerStore;
use Smartness\TraceFlow\Transport\HttpTransport;
use Smartness\TraceFlow\Transport\InMemoryCircuitBreakerStore;

/**
 * Circuit breaker: closed → open after N consecutive failed attempts → half-open
 * after the timeout, where a single probe closes it (success) or re-opens it
 * for the full timeout (failure). State lives in a store shared by every
 * request/process, so failures add up under PHP-FPM.
 */
class CircuitBreakerTest extends TestCase
{
    /**
     * A breaker that re-reads the store on every call, like a fresh request would.
     */
    private function breaker(CircuitBreakerStore $store, int $threshold = 3, float $timeout = 60.0): CircuitBreaker
    {
        return new CircuitBreaker($store, $threshold, $timeout, probeTtlSeconds: 5.0, logPrefix: '[test]', maxSnapshotAge: 0.0);
    }

    private function openAndExpire(CircuitBreaker $breaker, int $threshold = 1): void
    {
        for ($i = 0; $i < $threshold; $i++) {
            $breaker->recordFailure();
        }
        $this->assertSame(CircuitBreaker::OPEN, $breaker->state());
        usleep(80_000);
    }

    // -------------------------------------------------------------------------
    // State machine
    // -------------------------------------------------------------------------

    public function test_stays_closed_below_threshold(): void
    {
        $breaker = $this->breaker(new InMemoryCircuitBreakerStore, threshold: 3);

        $breaker->recordFailure();
        $breaker->recordFailure();

        $this->assertSame(CircuitBreaker::CLOSED, $breaker->state());
        $this->assertTrue($breaker->allowRequest());
    }

    public function test_opens_after_threshold_consecutive_failed_attempts(): void
    {
        $breaker = $this->breaker(new InMemoryCircuitBreakerStore, threshold: 3);

        $breaker->recordFailure();
        $breaker->recordFailure();
        $breaker->recordFailure();

        $this->assertSame(CircuitBreaker::OPEN, $breaker->state());
        $this->assertFalse($breaker->allowRequest());
    }

    public function test_success_resets_consecutive_failures(): void
    {
        $store = new InMemoryCircuitBreakerStore;
        $breaker = $this->breaker($store, threshold: 3);

        $breaker->recordFailure();
        $breaker->recordFailure();
        $breaker->refresh(force: true);
        $breaker->recordSuccess();
        $breaker->recordFailure();
        $breaker->recordFailure();

        $this->assertSame(CircuitBreaker::CLOSED, $breaker->state());
        $this->assertSame(2, $store->read()['failures']);
    }

    public function test_rejections_do_not_count(): void
    {
        $store = new InMemoryCircuitBreakerStore;
        $breaker = $this->breaker($store, threshold: 1);

        $breaker->recordRejection();
        $breaker->recordRejection();

        $this->assertSame(CircuitBreaker::CLOSED, $breaker->state());
        $this->assertSame(0, $store->read()['failures']);
    }

    public function test_half_open_allows_exactly_one_probe_across_processes(): void
    {
        $store = new InMemoryCircuitBreakerStore;
        $first = $this->breaker($store, threshold: 1, timeout: 0.05);
        $second = $this->breaker($store, threshold: 1, timeout: 0.05);

        $this->openAndExpire($first);

        $this->assertSame(CircuitBreaker::HALF_OPEN, $first->state());
        $this->assertTrue($first->allowRequest(), 'the first request after the timeout is the probe');
        $this->assertFalse($first->allowRequest(), 'no second request while the probe is in flight');
        $this->assertFalse($second->allowRequest(), 'another process does not probe at the same time');
    }

    public function test_probe_success_closes_the_breaker_for_everyone(): void
    {
        $store = new InMemoryCircuitBreakerStore;
        $first = $this->breaker($store, threshold: 1, timeout: 0.05);
        $second = $this->breaker($store, threshold: 1, timeout: 0.05);

        $this->openAndExpire($first);
        $this->assertTrue($first->allowRequest());
        $first->recordSuccess();

        $this->assertSame(CircuitBreaker::CLOSED, $first->state());
        $this->assertSame(CircuitBreaker::CLOSED, $second->state());
        $this->assertTrue($second->allowRequest());
        $this->assertSame(['failures' => 0, 'open_until' => null], $store->read());
    }

    public function test_probe_failure_reopens_for_the_full_timeout_at_once(): void
    {
        $store = new InMemoryCircuitBreakerStore;
        $breaker = $this->breaker($store, threshold: 5, timeout: 0.05);

        $this->openAndExpire($breaker, threshold: 5);
        $this->assertTrue($breaker->allowRequest());

        $before = microtime(true);
        $breaker->recordFailure(); // one failure is enough, not five more

        $this->assertSame(CircuitBreaker::OPEN, $breaker->state());
        $this->assertGreaterThanOrEqual($before + 0.05, $store->read()['open_until']);
        $this->assertFalse($breaker->allowRequest());
    }

    public function test_failures_from_different_requests_add_up(): void
    {
        $store = new InMemoryCircuitBreakerStore;

        // Three "requests", each with its own breaker object (as under PHP-FPM).
        foreach (range(1, 3) as $_) {
            $this->breaker($store, threshold: 3)->recordFailure();
        }

        $this->assertSame(CircuitBreaker::OPEN, $this->breaker($store, threshold: 3)->state());
    }

    // -------------------------------------------------------------------------
    // Cache-backed store
    // -------------------------------------------------------------------------

    public function test_cache_store_shares_state_between_instances(): void
    {
        $cache = new Repository(new ArrayStore);
        $a = new CacheCircuitBreakerStore($cache, 'tf:test');
        $b = new CacheCircuitBreakerStore(fn () => $cache, 'tf:test');
        $other = new CacheCircuitBreakerStore($cache, 'tf:other-endpoint');

        $this->breaker($a, threshold: 2)->recordFailure();
        $this->breaker($b, threshold: 2)->recordFailure();

        $this->assertSame(CircuitBreaker::OPEN, $this->breaker($a, threshold: 2)->state());
        $this->assertSame(CircuitBreaker::OPEN, $this->breaker($b, threshold: 2)->state());
        $this->assertSame(CircuitBreaker::CLOSED, $this->breaker($other, threshold: 2)->state());
    }

    public function test_cache_store_probe_lease_is_exclusive(): void
    {
        $cache = new Repository(new ArrayStore);
        $a = new CacheCircuitBreakerStore($cache, 'tf:test');
        $b = new CacheCircuitBreakerStore($cache, 'tf:test');

        $this->assertTrue($a->acquireProbe(5));
        $this->assertFalse($b->acquireProbe(5));

        $a->close();
        $this->assertTrue($b->acquireProbe(5));
    }

    public function test_cache_errors_fall_back_to_memory_and_never_throw(): void
    {
        $cache = $this->createStub(RepositoryContract::class);
        foreach (['getMultiple', 'increment', 'add', 'put', 'forget', 'get'] as $method) {
            $cache->method($method)->willThrowException(new \RuntimeException('redis down'));
        }

        $store = new CacheCircuitBreakerStore($cache, 'tf:test');
        $breaker = $this->breaker($store, threshold: 2);

        $breaker->recordFailure();
        $breaker->recordFailure();

        $this->assertSame(CircuitBreaker::OPEN, $breaker->state());
    }

    public function test_unresolvable_cache_store_falls_back_to_memory(): void
    {
        $store = new CacheCircuitBreakerStore(fn () => throw new \InvalidArgumentException('Cache store [nope] is not defined.'));
        $breaker = $this->breaker($store, threshold: 1);

        $this->assertTrue($breaker->allowRequest());
        $breaker->recordFailure();
        $this->assertSame(CircuitBreaker::OPEN, $breaker->state());
    }

    // -------------------------------------------------------------------------
    // Synchronous transport honours the breaker
    // -------------------------------------------------------------------------

    /**
     * @param  array<Response|RequestException>  $responses
     * @return array{0: HttpTransport, 1: \ArrayObject}
     */
    private function syncTransport(array $responses, array $config = []): array
    {
        $history = new \ArrayObject;
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));

        $transport = new HttpTransport(array_merge([
            'endpoint' => 'http://localhost:3009',
            'max_retries' => 0,
            'retry_delay' => 0,
            'silent_errors' => true,
            'circuit_breaker_threshold' => 1,
            'circuit_breaker_timeout_ms' => 60000,
        ], $config));

        (new \ReflectionClass(AbstractHttpTransport::class))->getProperty('client')
            ->setValue($transport, new Client(['handler' => $stack, 'base_uri' => 'http://localhost:3009']));

        return [$transport, $history];
    }

    private function event(string $traceId): TraceEvent
    {
        return new TraceEvent(
            eventId: 'e-'.$traceId,
            eventType: TraceEventType::TRACE_STARTED,
            traceId: $traceId,
            timestamp: '2026-03-24T12:00:00.000Z',
            source: 'test-service',
            payload: [],
        );
    }

    private function failure(): RequestException
    {
        return new RequestException('connection error', new Request('POST', '/api/v1/traces'));
    }

    public function test_sync_transport_drops_events_without_a_request_while_open(): void
    {
        [$transport, $history] = $this->syncTransport([$this->failure()]);

        $transport->send($this->event('t1')); // fails, opens the breaker
        $start = microtime(true);
        $transport->send($this->event('t2'));
        $transport->send($this->event('t3'));

        $this->assertLessThan(0.05, microtime(true) - $start);
        $this->assertCount(1, $history, 'no request while the breaker is open');
        $this->assertSame(2, $transport->getStats()['dropped_breaker_open']);
        $this->assertSame(CircuitBreaker::OPEN, $transport->getStats()['circuit']);
    }

    public function test_sync_transport_probe_success_closes_and_resumes(): void
    {
        [$transport, $history] = $this->syncTransport(
            [$this->failure(), new Response(201), new Response(201)],
            ['circuit_breaker_timeout_ms' => 50],
        );

        $transport->send($this->event('t1'));
        usleep(80_000);
        $transport->send($this->event('t2')); // probe
        $transport->send($this->event('t3'));

        $this->assertCount(3, $history);
        $this->assertSame(2, $transport->getStats()['delivered']);
        $this->assertSame(CircuitBreaker::CLOSED, $transport->getStats()['circuit']);
    }

    public function test_sync_transport_probe_is_a_single_attempt(): void
    {
        [$transport, $history] = $this->syncTransport(
            [$this->failure(), $this->failure(), new Response(201)],
            ['circuit_breaker_timeout_ms' => 50, 'max_retries' => 3],
        );

        $transport->send($this->event('t1'));   // 1st attempt fails, breaker opens, no retry
        usleep(80_000);
        $transport->send($this->event('t2'));   // probe fails: no retries, re-opened
        $transport->send($this->event('t3'));   // dropped

        $this->assertCount(2, $history);
        $this->assertSame(CircuitBreaker::OPEN, $transport->getStats()['circuit']);
        $this->assertSame(1, $transport->getStats()['dropped_breaker_open']);
    }

    public function test_sync_transport_does_not_open_on_4xx(): void
    {
        [$transport, $history] = $this->syncTransport([new Response(422), new Response(404), new Response(201)]);

        $transport->send($this->event('t1'));
        $transport->send($this->event('t2'));
        $transport->send($this->event('t3'));

        $this->assertCount(3, $history);
        $this->assertSame(CircuitBreaker::CLOSED, $transport->getStats()['circuit']);
        $this->assertSame(2, $transport->getStats()['rejected']);
    }
}
