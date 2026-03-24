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
use Smartness\TraceFlow\DTO\TraceEvent;
use Smartness\TraceFlow\Enums\TraceEventType;
use Smartness\TraceFlow\Transport\AsyncHttpTransport;

class AsyncHttpTransportTest extends TestCase
{
    /**
     * Creates a transport with a MockHandler + history middleware.
     *
     * Returns [$transport, $history] where $history is an ArrayObject that the
     * Guzzle history middleware appends every transaction into.  Because it is
     * an object it is shared by reference, so callers see all entries populated
     * by flush() even though the method returns before any I/O happens.
     */
    private function createTransport(array $responses, array $config = []): array
    {
        // ArrayObject is passed by object identity so the history middleware and
        // the caller share the exact same container — no reference-alias needed.
        $history = new \ArrayObject();
        $historyMiddleware = Middleware::history($history);
        $mock = new MockHandler($responses);
        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push($historyMiddleware);

        $defaultConfig = [
            'endpoint'      => 'http://localhost:3009',
            'timeout'       => 5.0,
            'max_retries'   => 3,
            'retry_delay'   => 100,
            'silent_errors' => true,
        ];
        $config = array_merge($defaultConfig, $config);

        $transport = new AsyncHttpTransport($config);

        // Rebuild the same headers that AbstractHttpTransport sets in its constructor
        // so that api_key and Content-Type are preserved in the replacement client.
        $headers = ['Content-Type' => 'application/json'];
        if (isset($config['api_key'])) {
            $headers['X-API-Key'] = $config['api_key'];
        }

        // Inject a client that uses our handler stack instead of real HTTP
        $reflection = new \ReflectionClass($transport);
        $clientProp = $reflection->getProperty('client');
        $clientProp->setValue($transport, new Client([
            'handler'  => $handlerStack,
            'base_uri' => $config['endpoint'],
            'headers'  => $headers,
        ]));

        return [$transport, $history];
    }

    private function makeEvent(
        string $eventId,
        TraceEventType $type,
        string $traceId = 'trace-123',
        array $payload = [],
        ?string $stepId = null,
    ): TraceEvent {
        return new TraceEvent(
            eventId: $eventId,
            eventType: $type,
            traceId: $traceId,
            timestamp: now('UTC')->format('Y-m-d\TH:i:s.v\Z'),
            source: 'test-source',
            payload: $payload,
            stepId: $stepId,
        );
    }

    // -------------------------------------------------------------------------
    // 1. Non-blocking: promise is queued before flush, consumed after
    // -------------------------------------------------------------------------

    public function test_send_is_non_blocking(): void
    {
        [$transport, $container] = $this->createTransport([
            new Response(201),
        ]);

        $event = $this->makeEvent('e1', TraceEventType::TRACE_STARTED);
        $transport->send($event);

        // The mock still has 0 consumed items — the promise has been queued
        // but the HTTP call has not been awaited yet.
        $this->assertCount(0, $container, 'Request should not be sent before flush()');

        $transport->flush();

        $this->assertCount(1, $container, 'Request should be sent after flush()');
    }

    // -------------------------------------------------------------------------
    // 2. Multiple events all consumed after flush
    // -------------------------------------------------------------------------

    public function test_send_multiple_events(): void
    {
        [$transport, $container] = $this->createTransport([
            new Response(201), // TRACE_STARTED
            new Response(201), // STEP_STARTED
            new Response(200), // STEP_FINISHED
            new Response(200), // TRACE_FINISHED
        ]);

        $events = [
            $this->makeEvent('e1', TraceEventType::TRACE_STARTED, 'trace-1'),
            $this->makeEvent('e2', TraceEventType::STEP_STARTED, 'trace-1', [], 'step-1'),
            $this->makeEvent('e3', TraceEventType::STEP_FINISHED, 'trace-1', [], 'step-1'),
            $this->makeEvent('e4', TraceEventType::TRACE_FINISHED, 'trace-1'),
        ];

        foreach ($events as $event) {
            $transport->send($event);
        }

        $transport->flush();

        $this->assertCount(4, $container, 'All 4 events should be consumed after flush()');
    }

    // -------------------------------------------------------------------------
    // 3. Retry: 2 failures + 1 success — all 3 handler slots consumed
    // -------------------------------------------------------------------------

    public function test_async_retry_on_failure(): void
    {
        [$transport, $container] = $this->createTransport([
            new RequestException('Connection timeout', new Request('POST', '/api/v1/traces')),
            new RequestException('Connection timeout', new Request('POST', '/api/v1/traces')),
            new Response(201),
        ], ['max_retries' => 3]);

        $event = $this->makeEvent('e1', TraceEventType::TRACE_STARTED);
        $transport->send($event);
        $transport->flush();

        $this->assertCount(3, $container, 'Should retry twice then succeed (3 attempts total)');
    }

    // -------------------------------------------------------------------------
    // 4. Silent errors: exhausted retries must not throw
    // -------------------------------------------------------------------------

    public function test_silent_errors_suppresses_exception(): void
    {
        // 1 original attempt + 3 retries = 4 failures needed to exhaust max_retries=3
        [$transport] = $this->createTransport([
            new RequestException('Error', new Request('POST', '/api/v1/traces')),
            new RequestException('Error', new Request('POST', '/api/v1/traces')),
            new RequestException('Error', new Request('POST', '/api/v1/traces')),
            new RequestException('Error', new Request('POST', '/api/v1/traces')),
        ], [
            'silent_errors' => true,
            'max_retries'   => 3,
        ]);

        $event = $this->makeEvent('e1', TraceEventType::TRACE_STARTED);
        $transport->send($event);
        $transport->flush(); // Must not throw

        $this->assertTrue(true, 'No exception should be thrown when silent_errors=true');
    }

    // -------------------------------------------------------------------------
    // 5. flush() settles all pending promises
    // -------------------------------------------------------------------------

    public function test_flush_settles_all_promises(): void
    {
        $requestCount = 0;

        [$transport, $container] = $this->createTransport([
            function () use (&$requestCount) { $requestCount++; return new Response(201); },
            function () use (&$requestCount) { $requestCount++; return new Response(201); },
            function () use (&$requestCount) { $requestCount++; return new Response(201); },
        ]);

        for ($i = 0; $i < 3; $i++) {
            $transport->send($this->makeEvent("e{$i}", TraceEventType::TRACE_STARTED, "trace-{$i}"));
        }

        $transport->flush();

        $this->assertEquals(3, $requestCount, 'flush() should settle all 3 promises');
        $this->assertCount(3, $container);
    }

    // -------------------------------------------------------------------------
    // 6. shutdown() triggers flush
    // -------------------------------------------------------------------------

    public function test_shutdown_calls_flush(): void
    {
        [$transport, $container] = $this->createTransport([
            new Response(201),
        ]);

        $event = $this->makeEvent('e1', TraceEventType::TRACE_STARTED);
        $transport->send($event);
        $transport->shutdown();

        $this->assertCount(1, $container, 'shutdown() should flush pending requests');
    }

    // -------------------------------------------------------------------------
    // 7. TRACE_STARTED payload structure
    // -------------------------------------------------------------------------

    public function test_trace_started_payload_structure(): void
    {
        [$transport, $container] = $this->createTransport([
            new Response(201),
        ]);

        $event = $this->makeEvent(
            'e1',
            TraceEventType::TRACE_STARTED,
            'trace-abc',
            ['trace_type' => 'job', 'title' => 'My Trace'],
        );

        $transport->send($event);
        $transport->flush();

        $this->assertCount(1, $container);

        $body = json_decode((string) $container[0]['request']->getBody(), true);

        $this->assertArrayHasKey('trace_id', $body);
        $this->assertSame('trace-abc', $body['trace_id']);
        $this->assertArrayHasKey('status', $body);
        $this->assertSame('PENDING', $body['status']);
        $this->assertArrayHasKey('source', $body);
        $this->assertSame('test-source', $body['source']);
    }

    // -------------------------------------------------------------------------
    // 8. TRACE_FAILED payload includes stack field
    // -------------------------------------------------------------------------

    public function test_trace_failed_payload_includes_stack(): void
    {
        [$transport, $container] = $this->createTransport([
            new Response(200),
        ]);

        $event = $this->makeEvent(
            'e1',
            TraceEventType::TRACE_FAILED,
            'trace-xyz',
            ['error' => 'something went wrong', 'stack' => 'stack here'],
        );

        $transport->send($event);
        $transport->flush();

        $this->assertCount(1, $container);

        $body = json_decode((string) $container[0]['request']->getBody(), true);

        $this->assertArrayHasKey('error', $body);
        $this->assertSame('something went wrong', $body['error']);
        $this->assertArrayHasKey('stack', $body);
        $this->assertSame('stack here', $body['stack']);
        $this->assertSame('FAILED', $body['status']);
    }

    // -------------------------------------------------------------------------
    // 9. API key is sent in the X-API-Key request header
    // -------------------------------------------------------------------------

    public function test_api_key_sent_in_header(): void
    {
        [$transport, $container] = $this->createTransport([
            new Response(201),
        ], ['api_key' => 'key-123']);

        $event = $this->makeEvent('e1', TraceEventType::TRACE_STARTED);
        $transport->send($event);
        $transport->flush();

        $this->assertCount(1, $container);

        $request = $container[0]['request'];
        $this->assertSame('key-123', $request->getHeaderLine('X-API-Key'));
    }

    // -------------------------------------------------------------------------
    // 10. STEP_STARTED payload structure
    // -------------------------------------------------------------------------

    public function test_step_started_payload_structure(): void
    {
        [$transport, $container] = $this->createTransport([
            new Response(201),
        ]);

        $event = $this->makeEvent(
            'e1',
            TraceEventType::STEP_STARTED,
            'trace-abc',
            ['name' => 'My Step', 'step_type' => 'db'],
            'step-999',
        );

        $transport->send($event);
        $transport->flush();

        $this->assertCount(1, $container);

        $body = json_decode((string) $container[0]['request']->getBody(), true);

        $this->assertArrayHasKey('step_id', $body);
        $this->assertSame('step-999', $body['step_id']);
        $this->assertArrayHasKey('trace_id', $body);
        $this->assertSame('trace-abc', $body['trace_id']);
        $this->assertArrayHasKey('status', $body);
        $this->assertSame('STARTED', $body['status']);
    }

    // -------------------------------------------------------------------------
    // 11. LOG_EMITTED posts to /api/v1/logs with correct body fields
    // -------------------------------------------------------------------------

    public function test_log_emitted_payload_structure(): void
    {
        [$transport, $container] = $this->createTransport([
            new Response(201),
        ]);

        $event = $this->makeEvent(
            'e1',
            TraceEventType::LOG_EMITTED,
            'trace-abc',
            ['message' => 'Hello log', 'level' => 'DEBUG'],
        );

        $transport->send($event);
        $transport->flush();

        $this->assertCount(1, $container);

        /** @var \GuzzleHttp\Psr7\Request $request */
        $request = $container[0]['request'];

        // Must POST to the logs endpoint
        $this->assertSame('POST', $request->getMethod());
        $this->assertStringContainsString('/api/v1/logs', (string) $request->getUri());

        $body = json_decode((string) $request->getBody(), true);

        $this->assertArrayHasKey('message', $body);
        $this->assertSame('Hello log', $body['message']);
        $this->assertArrayHasKey('level', $body);
        $this->assertSame('DEBUG', $body['level']);
    }
}
