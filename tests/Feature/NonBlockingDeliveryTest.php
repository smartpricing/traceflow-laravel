<?php

namespace Smartness\TraceFlow\Tests\Feature;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Smartness\TraceFlow\Context\TraceFlowContext;
use Smartness\TraceFlow\Tests\Support\LocalServer;
use Smartness\TraceFlow\TraceFlowSDK;
use Smartness\TraceFlow\Transport\CacheCircuitBreakerStore;
use Smartness\TraceFlow\Transport\CircuitBreaker;
use Smartness\TraceFlow\Transport\DeliveryException;

/**
 * Tracing must never make the application wait for the Traceflow server.
 * These tests run against real local sockets: a server that accepts
 * connections and never answers, one answering 503, and a closed port.
 */
class NonBlockingDeliveryTest extends TestCase
{
    /** Allowed overshoot of a flush past flush_timeout. */
    private const SLACK = 0.25;

    /** @var list<LocalServer> */
    private array $servers = [];

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            $server->stop();
        }
        TraceFlowContext::clear();
        parent::tearDown();
    }

    private function sdk(string $endpoint, array $config = []): TraceFlowSDK
    {
        return new TraceFlowSDK($config + [
            'source' => 'delivery-test',
            'endpoint' => $endpoint,
            'silent_errors' => true,
        ]);
    }

    /**
     * A full request's worth of tracing calls.
     */
    private function traceOneRequest(TraceFlowSDK $sdk): void
    {
        $trace = $sdk->startTrace(traceType: 'http_request', title: 'GET /orders');
        $step = $trace->startStep(name: 'load orders', input: ['page' => 1]);
        $step->log('loaded 20 orders');
        $step->finish(['count' => 20]);
        $trace->finish(['status_code' => 200]);
    }

    private function elapsed(callable $callback): float
    {
        $start = hrtime(true);
        $callback();

        return (hrtime(true) - $start) / 1e9;
    }

    public static function brokenServers(): array
    {
        return [
            'accepts connections, never answers' => ['hang'],
            'answers 503' => ['503'],
            'connection refused' => ['refused'],
        ];
    }

    private function brokenEndpoint(string $kind): string
    {
        return match ($kind) {
            'hang' => ($this->servers[] = LocalServer::hanging())->url,
            '503' => ($this->servers[] = LocalServer::start('503'))->url,
            'refused' => LocalServer::refusedUrl(),
        };
    }

    // -------------------------------------------------------------------------
    // Tracing calls never wait; flush/shutdown are bounded
    // -------------------------------------------------------------------------

    #[DataProvider('brokenServers')]
    public function test_tracing_calls_return_at_once_when_the_server_is_broken(string $kind): void
    {
        $sdk = $this->sdk($this->brokenEndpoint($kind), ['flush_timeout' => 0.5]);
        $this->traceOneRequest($sdk); // warm up autoloading

        $elapsed = $this->elapsed(fn () => $this->traceOneRequest($sdk));

        $this->assertLessThan(0.1, $elapsed, "tracing calls took {$elapsed}s with a {$kind} server");
    }

    #[DataProvider('brokenServers')]
    public function test_flush_and_shutdown_are_bounded_when_the_server_is_broken(string $kind): void
    {
        $sdk = $this->sdk($this->brokenEndpoint($kind), ['flush_timeout' => 0.5]);

        $this->traceOneRequest($sdk);
        $flush = $this->elapsed(fn () => $sdk->flush());
        $this->traceOneRequest($sdk);
        $shutdown = $this->elapsed(fn () => $sdk->shutdown());

        $this->assertLessThan(0.5 + self::SLACK, $flush, "flush took {$flush}s");
        $this->assertLessThan(0.5 + self::SLACK, $shutdown, "shutdown took {$shutdown}s");
        $this->assertSame(0, $sdk->getStats()['queued'] + $sdk->getStats()['in_flight'], 'shutdown leaves nothing queued');
    }

    public function test_default_flush_timeout_bounds_a_hanging_server(): void
    {
        $sdk = $this->sdk($this->brokenEndpoint('hang')); // defaults: flush_timeout 2 s, timeout 5 s, 3 retries

        $this->traceOneRequest($sdk);
        $shutdown = $this->elapsed(fn () => $sdk->shutdown());

        $this->assertGreaterThan(1.9, $shutdown, 'waits for the server up to flush_timeout');
        $this->assertLessThan(2.0 + self::SLACK, $shutdown);
        $this->assertSame(5, $sdk->getStats()['dropped_flush_timeout']);
    }

    // -------------------------------------------------------------------------
    // Delivery and ordering
    // -------------------------------------------------------------------------

    public function test_events_are_delivered_in_order_per_trace_under_concurrency(): void
    {
        $server = $this->servers[] = LocalServer::start('jitter', workers: 8);
        $sdk = $this->sdk($server->url, ['concurrency' => 4, 'flush_timeout' => 10]);

        $traceIds = [];
        for ($i = 0; $i < 12; $i++) {
            $trace = $sdk->startTrace(title: "trace {$i}");
            $traceIds[] = $trace->traceId;
            $a = $trace->startStep(name: 'a');
            $b = $trace->startStep(name: 'b');
            $a->log('in a');
            $a->finish();
            $b->fail('b failed');
            $trace->finish();
        }
        $sdk->flush();

        $this->assertSame(12 * 7, $sdk->getStats()['delivered']);
        $requests = $server->requests();
        $this->assertCount(12 * 7, $requests);

        // Requests of a trace, keyed by what they are.
        $byTrace = [];
        foreach ($requests as $request) {
            foreach ($traceIds as $traceId) {
                if (str_contains($request['path'], $traceId)) {
                    $byTrace[$traceId][$request['method'].' '.$request['path']] = $request;
                }
            }
        }

        $concurrent = 0;
        $events = [];
        foreach ($requests as $request) {
            $events[] = [$request['received'], 1];
            $events[] = [$request['answered'], -1];
        }
        usort($events, fn ($x, $y) => $x[0] <=> $y[0] ?: $x[1] <=> $y[1]);
        $maxConcurrent = 0;
        foreach ($events as [, $delta]) {
            $maxConcurrent = max($maxConcurrent, $concurrent += $delta);
        }
        $this->assertLessThanOrEqual(4, $maxConcurrent, 'at most `concurrency` requests in flight');
        $this->assertGreaterThan(1, $maxConcurrent, 'independent traces are delivered in parallel');

        // Creates are POSTs without the ID in the path: match them through the
        // updates' timing instead. Every update must arrive after its create was
        // answered; the trace's terminal update after all its step updates.
        foreach ($traceIds as $traceId) {
            $traceUpdate = $byTrace[$traceId]['PATCH /api/v1/traces/'.$traceId];
            $stepUpdates = array_filter($byTrace[$traceId], fn ($r) => str_starts_with($r['path'], '/api/v1/steps/'));
            $this->assertCount(2, $stepUpdates);

            foreach ($stepUpdates as $stepUpdate) {
                $this->assertGreaterThanOrEqual($stepUpdate['answered'], $traceUpdate['received'], 'trace update after its step updates');
            }
        }

        // Global check on creates: no step update is received before at least as
        // many step creates have been answered (an update overtaking its create
        // would break this under jitter).
        $answeredCreates = 0;
        $timeline = [];
        foreach ($requests as $request) {
            if ($request['method'] === 'POST' && $request['path'] === '/api/v1/steps') {
                $timeline[] = [$request['answered'], 'create'];
            } elseif ($request['method'] === 'PATCH' && str_starts_with($request['path'], '/api/v1/steps/')) {
                $timeline[] = [$request['received'], 'update'];
            }
        }
        usort($timeline, fn ($x, $y) => $x[0] <=> $y[0]);
        $updates = 0;
        foreach ($timeline as [, $kind]) {
            $kind === 'create' ? $answeredCreates++ : $updates++;
            $this->assertLessThanOrEqual($answeredCreates, $updates);
        }
    }

    public function test_queue_bound_drops_new_events_and_memory_stays_flat(): void
    {
        $sdk = $this->sdk($this->brokenEndpoint('hang'), ['max_queue_size' => 100, 'flush_timeout' => 0.2]);
        $trace = $sdk->startTrace(title: 'busy');

        for ($i = 0; $i < 199; $i++) {
            $trace->log("event {$i}", details: ['i' => $i, 'padding' => str_repeat('x', 200)]);
        }
        $this->assertSame(100, $sdk->getStats()['queued']);
        $this->assertSame(100, $sdk->getStats()['dropped_queue_full']);

        $before = memory_get_usage();
        for ($i = 0; $i < 10_000; $i++) {
            $trace->log("event {$i}", details: ['i' => $i, 'padding' => str_repeat('x', 200)]);
        }
        $growth = memory_get_usage() - $before;

        $this->assertSame(10_100, $sdk->getStats()['dropped_queue_full']);
        $this->assertLessThan(256 * 1024, $growth, "memory grew by {$growth} bytes");

        $trace->finish();
        $sdk->shutdown();
    }

    // -------------------------------------------------------------------------
    // Circuit breaker
    // -------------------------------------------------------------------------

    public function test_breaker_opens_sends_nothing_then_probes_once_and_recovers(): void
    {
        $server = $this->servers[] = LocalServer::start('503');
        $sdk = $this->sdk($server->url, [
            'max_retries' => 0,
            'concurrency' => 1, // one attempt at a time: exact request counts
            'circuit_breaker_threshold' => 3,
            'circuit_breaker_timeout_ms' => 300,
            'flush_timeout' => 1.0,
        ]);

        // Three failed attempts open it; the other queued events wait.
        foreach (range(1, 3) as $_) {
            $sdk->startTrace(title: 'fails')->finish();
        }
        $sdk->flush();
        $this->assertSame(3, $server->requestCount());
        $this->assertSame(CircuitBreaker::OPEN, $sdk->getStats()['circuit']);
        $this->assertSame(3, $sdk->getStats()['queued']);

        // While open no request is sent and new events are dropped at once.
        $elapsed = $this->elapsed(function () use ($sdk) {
            $this->traceOneRequest($sdk);
            $sdk->flush();
        });
        $this->assertLessThan(0.05, $elapsed);
        $this->assertSame(3, $server->requestCount());
        $this->assertSame(5, $sdk->getStats()['dropped_breaker_open']);

        // After the timeout one probe is sent; it fails and re-opens at once.
        usleep(350_000);
        $sdk->startTrace(title: 'after timeout')->finish();
        $sdk->flush();
        $this->assertSame(4, $server->requestCount(), 'exactly one probe');
        $this->assertSame(CircuitBreaker::OPEN, $sdk->getStats()['circuit']);

        // After the next timeout the probe succeeds: closed, queued events delivered.
        $server->setMode('ok');
        usleep(350_000);
        $sdk->startTrace(title: 'recovered')->finish();
        $queued = $sdk->getStats()['queued'];
        $sdk->flush();

        $this->assertSame(CircuitBreaker::CLOSED, $sdk->getStats()['circuit']);
        $this->assertSame(0, $sdk->getStats()['queued']);
        $this->assertSame($queued, $sdk->getStats()['delivered']);
        $this->assertSame(4 + $queued, $server->requestCount());
    }

    public function test_breaker_state_is_shared_by_requests_through_the_cache(): void
    {
        $server = $this->servers[] = LocalServer::start('503');
        $cache = new Repository(new ArrayStore);

        // Each simulated PHP-FPM request builds a fresh SDK, sharing only the cache.
        $request = function () use ($server, $cache): float {
            $sdk = $this->sdk($server->url, [
                'max_retries' => 0,
                'circuit_breaker_threshold' => 2,
                'circuit_breaker_store' => new CacheCircuitBreakerStore($cache, 'tf:test'),
                'flush_timeout' => 1.0,
            ]);
            $sdk->startTrace(title: 'request')->finish();

            return $this->elapsed(fn () => $sdk->shutdown());
        };

        $request();
        $request();
        $this->assertSame(2, $server->requestCount(), 'one failed attempt per request');

        $third = $request();
        $this->assertSame(2, $server->requestCount(), 'the third request sees the open breaker');
        $this->assertLessThan(0.05, $third);
    }

    // -------------------------------------------------------------------------
    // Errors
    // -------------------------------------------------------------------------

    public function test_silent_errors_false_tracing_calls_do_not_throw_flush_does(): void
    {
        $server = $this->servers[] = LocalServer::start('503');
        $sdk = $this->sdk($server->url, ['silent_errors' => false, 'max_retries' => 0, 'flush_timeout' => 1.0]);

        $this->traceOneRequest($sdk); // must not throw

        $this->expectException(DeliveryException::class);
        $sdk->flush();
    }

    public function test_get_trace_is_bounded_and_skipped_while_the_breaker_is_open(): void
    {
        $sdk = $this->sdk($this->brokenEndpoint('hang'), ['timeout' => 0.3, 'circuit_breaker_threshold' => 1]);
        $traceId = '9b2f1a51-6a43-4e0c-9a51-8f3c1d1f2a10';

        $first = $this->elapsed(fn () => $sdk->getTrace($traceId));
        $this->assertLessThan(0.3 + self::SLACK, $first, 'bounded by timeout');
        $this->assertSame(CircuitBreaker::OPEN, $sdk->getStats()['circuit']);

        $second = $this->elapsed(fn () => $sdk->getTrace($traceId));
        $this->assertLessThan(0.01, $second, 'no request while the breaker is open');

        $heartbeat = $this->elapsed(fn () => $sdk->heartbeat($traceId));
        $this->assertLessThan(0.01, $heartbeat);
    }
}
