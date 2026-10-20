<?php

namespace Smartness\TraceFlow\Tests\Unit\Transport;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Smartness\TraceFlow\DTO\TraceEvent;
use Smartness\TraceFlow\Enums\TraceEventType;
use Smartness\TraceFlow\Transport\AbstractHttpTransport;
use Smartness\TraceFlow\Transport\HttpTransport;

/**
 * Tests for the circuit-breaker logic embedded in AbstractHttpTransport.
 *
 * Design choices that keep these tests deterministic and fast:
 *   - max_retries=0  → each failing send() consumes exactly ONE mock response
 *                      and records exactly ONE failure, so threshold arithmetic
 *                      is straightforward.
 *   - circuit_breaker_threshold=1 or 2  → short mock-response arrays.
 *   - retry_delay=0  → no real sleeping.
 *   - silent_errors=true  → exceptions from send() are swallowed, so the
 *                           circuit-breaker failure counter advances normally
 *                           without tests having to catch.
 *
 * Private circuit-breaker state lives in AbstractHttpTransport, so reflection
 * must target that class — not HttpTransport.
 */
class CircuitBreakerTest extends TestCase
{
    private const ENDPOINT  = 'http://localhost:3009';
    private const TIMESTAMP = '2026-03-24T12:00:00.000Z';

    /** Reflects the parent class where the private CB properties are declared. */
    private ReflectionClass $parentRef;

    protected function setUp(): void
    {
        $this->parentRef = new ReflectionClass(AbstractHttpTransport::class);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param  array<Response|RequestException>  $responses
     * @return array{0: HttpTransport, 1: \stdClass}  [$transport, $history]
     */
    private function makeTransport(array $responses, array $config = []): array
    {
        $history = new \stdClass();
        $history->log = [];

        $mock = new MockHandler($responses);
        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push(Middleware::history($history->log));

        $defaults = [
            'endpoint'                   => self::ENDPOINT,
            'timeout'                    => 5.0,
            'max_retries'                => 0,
            'retry_delay'                => 0,
            'silent_errors'              => true,
            'circuit_breaker_threshold'  => 2,
            'circuit_breaker_timeout_ms' => 60000,
        ];

        $transport = new HttpTransport(array_merge($defaults, $config));

        // Inject the mock-backed client via reflection on the parent class.
        $this->parentRef->getProperty('client')->setValue($transport, new Client([
            'handler'  => $handlerStack,
            'base_uri' => self::ENDPOINT,
        ]));

        return [$transport, $history];
    }

    private function makeEvent(string $traceId = 'trace-1', string $eventId = 'e1'): TraceEvent
    {
        return new TraceEvent(
            eventId: $eventId,
            eventType: TraceEventType::TRACE_STARTED,
            traceId: $traceId,
            timestamp: self::TIMESTAMP,
            source: 'test-service',
            payload: [],
        );
    }

    private function failure(): RequestException
    {
        return new RequestException('connection error', new Request('POST', '/api/v1/traces'));
    }

    /** Read a property from AbstractHttpTransport (handles private visibility). */
    private function getProp(HttpTransport $transport, string $name): mixed
    {
        return $this->parentRef->getProperty($name)->getValue($transport);
    }

    /** Set a property on AbstractHttpTransport (handles private visibility). */
    private function setProp(HttpTransport $transport, string $name, mixed $value): void
    {
        $this->parentRef->getProperty($name)->setValue($transport, $value);
    }

    /** Expire the circuit-breaker timeout so the next isCircuitOpen() call closes it. */
    private function expireCircuit(HttpTransport $transport): void
    {
        $this->setProp($transport, 'circuitOpenUntil', (int)(microtime(true) * 1000) - 1);
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    public function test_circuit_stays_closed_below_threshold(): void
    {
        // threshold=3, two failures — circuit must remain closed.
        [$transport] = $this->makeTransport(
            [$this->failure(), $this->failure()],
            ['circuit_breaker_threshold' => 3],
        );

        $transport->send($this->makeEvent('t1', 'e1'));
        $transport->send($this->makeEvent('t2', 'e2'));

        $this->assertFalse(
            $this->getProp($transport, 'circuitOpen'),
            'Circuit must remain closed when failure count is below threshold',
        );
        $this->assertSame(2, $this->getProp($transport, 'failureCount'));
    }

    public function test_circuit_opens_after_threshold_failures(): void
    {
        // threshold=3, three failures — circuit must open.
        [$transport] = $this->makeTransport(
            [$this->failure(), $this->failure(), $this->failure()],
            ['circuit_breaker_threshold' => 3],
        );

        $transport->send($this->makeEvent('t1', 'e1'));
        $transport->send($this->makeEvent('t2', 'e2'));
        $transport->send($this->makeEvent('t3', 'e3'));

        $this->assertTrue(
            $this->getProp($transport, 'circuitOpen'),
            'Circuit must open once failure count reaches threshold',
        );
    }

    public function test_events_queued_while_circuit_open(): void
    {
        // threshold=1 → one failure opens the circuit immediately.
        [$transport] = $this->makeTransport(
            [$this->failure()],   // only one mock response; no more HTTP calls expected
            ['circuit_breaker_threshold' => 1],
        );

        // First send fails and opens the circuit.
        $transport->send($this->makeEvent('t1', 'e1'));
        $this->assertTrue($this->getProp($transport, 'circuitOpen'));

        // These two must be queued, not sent (MockHandler has no more responses).
        $transport->send($this->makeEvent('t2', 'e2'));
        $transport->send($this->makeEvent('t3', 'e3'));

        $queue = $this->getProp($transport, 'eventQueue');
        $this->assertCount(2, $queue, 'Two events should be queued while circuit is open');
    }

    public function test_circuit_closes_after_timeout(): void
    {
        // Open the circuit with one failure (threshold=1).
        [$transport] = $this->makeTransport(
            [$this->failure(), new Response(201)],
            ['circuit_breaker_threshold' => 1],
        );

        // Open the circuit.
        $transport->send($this->makeEvent('t1', 'e1'));
        $this->assertTrue($this->getProp($transport, 'circuitOpen'));

        // Expire the timeout.
        $this->expireCircuit($transport);

        // The next send() calls isCircuitOpen(), which detects the expiry,
        // closes the circuit (and drains any queue), then processes normally.
        $transport->send($this->makeEvent('t2', 'e2'));

        $this->assertFalse(
            $this->getProp($transport, 'circuitOpen'),
            'Circuit must close after timeout expires',
        );
    }

    public function test_queue_drained_when_circuit_closes(): void
    {
        // Provide: 1 failure (opens circuit) + 2 successes (drain queued events).
        [$transport, $history] = $this->makeTransport(
            [$this->failure(), new Response(201), new Response(201)],
            ['circuit_breaker_threshold' => 1],
        );

        // Open the circuit.
        $transport->send($this->makeEvent('t1', 'e1'));

        // Queue two events while open.
        $transport->send($this->makeEvent('t2', 'e2'));
        $transport->send($this->makeEvent('t3', 'e3'));

        $this->assertCount(2, $this->getProp($transport, 'eventQueue'));

        // Expire the circuit timeout.
        $this->expireCircuit($transport);

        // flush() calls drainQueue(), which calls isCircuitOpen() first.
        // isCircuitOpen() sees the timeout expired, closes the circuit, then
        // drainQueue processes each queued event.
        $transport->flush();

        $this->assertEmpty(
            $this->getProp($transport, 'eventQueue'),
            'Queue must be empty after circuit closes and drain runs',
        );

        // Total HTTP requests: 1 (failed) + 2 (drained).
        $this->assertCount(3, $history->log, 'All three requests (1 fail + 2 drained) should have been made');
    }

    public function test_success_resets_failure_count(): void
    {
        // threshold=3, two failures then one success — failureCount should reset to 0.
        [$transport] = $this->makeTransport(
            [$this->failure(), $this->failure(), new Response(201)],
            ['circuit_breaker_threshold' => 3],
        );

        $transport->send($this->makeEvent('t1', 'e1'));
        $transport->send($this->makeEvent('t2', 'e2'));

        $this->assertSame(2, $this->getProp($transport, 'failureCount'));

        $transport->send($this->makeEvent('t3', 'e3'));

        $this->assertSame(
            0,
            $this->getProp($transport, 'failureCount'),
            'failureCount must reset to 0 after a successful request',
        );
        $this->assertFalse($this->getProp($transport, 'circuitOpen'));
    }

    public function test_flush_drains_queue_after_circuit_closes(): void
    {
        // 1 failure opens circuit; 2 successes consumed when flush drains the queue.
        [$transport, $history] = $this->makeTransport(
            [$this->failure(), new Response(201), new Response(201)],
            ['circuit_breaker_threshold' => 1],
        );

        // Open the circuit.
        $transport->send($this->makeEvent('t1', 'e1'));

        // Queue two events.
        $transport->send($this->makeEvent('t2', 'e2'));
        $transport->send($this->makeEvent('t3', 'e3'));

        $this->assertCount(2, $this->getProp($transport, 'eventQueue'));

        // Expire the circuit so drainQueue can send.
        $this->expireCircuit($transport);

        $transport->flush();

        $this->assertEmpty($this->getProp($transport, 'eventQueue'), 'Queue must be empty after flush');
        // 3 total HTTP requests: 1 initial failure + 2 drained.
        $this->assertCount(3, $history->log);
    }
}
