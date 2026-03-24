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
use Smartness\TraceFlow\Transport\HttpTransport;

class HttpTransportTest extends TestCase
{
    private const ENDPOINT  = 'http://localhost:3009';
    private const TIMESTAMP = '2026-03-24T12:00:00.000Z';

    // -------------------------------------------------------------------------
    // Factory helpers
    // -------------------------------------------------------------------------

    /**
     * Creates an HttpTransport backed by a Guzzle MockHandler.
     *
     * The Guzzle history middleware appends each transaction to the array that
     * was passed (by reference) to Middleware::history(). We wrap that array in
     * a stdClass so the reference survives being returned from this method.
     *
     * @param  array<Response|RequestException>  $responses
     * @return array{0: HttpTransport, 1: \stdClass}  [$transport, $history] where $history->log is the request log
     */
    private function makeTransport(array $responses, array $config = []): array
    {
        // Wrap the history array in an object so the reference is stable.
        $history = new \stdClass();
        $history->log = [];

        $mock = new MockHandler($responses);
        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push(Middleware::history($history->log));

        $defaults = [
            'endpoint'    => self::ENDPOINT,
            'timeout'     => 5.0,
            'max_retries' => 0,
            'retry_delay' => 0,
            'silent_errors' => false,
        ];

        $transport = new HttpTransport(array_merge($defaults, $config));

        $ref = new \ReflectionClass($transport);
        $ref->getProperty('client')->setValue($transport, new Client([
            'handler'  => $handlerStack,
            'base_uri' => self::ENDPOINT,
        ]));

        return [$transport, $history];
    }

    private function makeEvent(
        TraceEventType $type,
        string $traceId  = 'trace-abc',
        array  $payload  = [],
        ?string $stepId  = null,
        string $eventId  = 'event-1',
    ): TraceEvent {
        return new TraceEvent(
            eventId: $eventId,
            eventType: $type,
            traceId: $traceId,
            timestamp: self::TIMESTAMP,
            source: 'test-service',
            payload: $payload,
            stepId: $stepId,
        );
    }

    // -------------------------------------------------------------------------
    // Trace lifecycle
    // -------------------------------------------------------------------------

    public function test_trace_started_posts_to_correct_endpoint(): void
    {
        [$transport, $history] = $this->makeTransport([new Response(201)]);

        $transport->send($this->makeEvent(TraceEventType::TRACE_STARTED, 'trace-abc'));

        $this->assertCount(1, $history->log);

        /** @var Request $request */
        $request = $history->log[0]['request'];
        $body    = json_decode($request->getBody()->getContents(), true);

        $this->assertSame('POST', $request->getMethod());
        $this->assertStringContainsString('/api/v1/traces', (string) $request->getUri());
        $this->assertSame('trace-abc', $body['trace_id']);
        $this->assertSame('PENDING', $body['status']);
        $this->assertSame('test-service', $body['source']);
        $this->assertSame(self::TIMESTAMP, $body['created_at']);
    }

    public function test_trace_finished_patches_with_success_status(): void
    {
        [$transport, $history] = $this->makeTransport([new Response(200)]);

        $transport->send($this->makeEvent(TraceEventType::TRACE_FINISHED, 'trace-abc'));

        $request = $history->log[0]['request'];
        $body    = json_decode($request->getBody()->getContents(), true);

        $this->assertSame('PATCH', $request->getMethod());
        $this->assertSame(self::ENDPOINT . '/api/v1/traces/trace-abc', (string) $request->getUri());
        $this->assertSame('SUCCESS', $body['status']);
        $this->assertSame(self::TIMESTAMP, $body['finished_at']);
    }

    public function test_trace_failed_patches_with_error_and_stack(): void
    {
        [$transport, $history] = $this->makeTransport([new Response(200)]);

        $transport->send($this->makeEvent(TraceEventType::TRACE_FAILED, 'trace-abc', [
            'error' => 'Something exploded',
            'stack' => 'stack trace here',
        ]));

        $body = json_decode($history->log[0]['request']->getBody()->getContents(), true);

        $this->assertSame('PATCH', $history->log[0]['request']->getMethod());
        $this->assertSame('FAILED', $body['status']);
        $this->assertSame('Something exploded', $body['error']);
        $this->assertSame('stack trace here', $body['stack']);
    }

    public function test_trace_cancelled_patches_with_cancelled_status(): void
    {
        [$transport, $history] = $this->makeTransport([new Response(200)]);

        $transport->send($this->makeEvent(TraceEventType::TRACE_CANCELLED, 'trace-abc'));

        $body = json_decode($history->log[0]['request']->getBody()->getContents(), true);

        $this->assertSame('PATCH', $history->log[0]['request']->getMethod());
        $this->assertSame('CANCELLED', $body['status']);
    }

    // -------------------------------------------------------------------------
    // Step lifecycle
    // -------------------------------------------------------------------------

    public function test_step_started_posts_to_steps_endpoint(): void
    {
        [$transport, $history] = $this->makeTransport([new Response(201)]);

        $transport->send($this->makeEvent(TraceEventType::STEP_STARTED, 'trace-abc', [], 'step-1'));

        $request = $history->log[0]['request'];
        $body    = json_decode($request->getBody()->getContents(), true);

        $this->assertSame('POST', $request->getMethod());
        $this->assertStringContainsString('/api/v1/steps', (string) $request->getUri());
        $this->assertSame('step-1', $body['step_id']);
        $this->assertSame('trace-abc', $body['trace_id']);
        $this->assertSame('STARTED', $body['status']);
    }

    public function test_step_finished_patches_with_completed_status(): void
    {
        [$transport, $history] = $this->makeTransport([new Response(200)]);

        $transport->send($this->makeEvent(TraceEventType::STEP_FINISHED, 'trace-abc', [], 'step-1'));

        $request = $history->log[0]['request'];
        $body    = json_decode($request->getBody()->getContents(), true);

        $this->assertSame('PATCH', $request->getMethod());
        $this->assertSame(self::ENDPOINT . '/api/v1/steps/trace-abc/step-1', (string) $request->getUri());
        $this->assertSame('COMPLETED', $body['status']);
    }

    public function test_step_failed_patches_with_failed_status(): void
    {
        [$transport, $history] = $this->makeTransport([new Response(200)]);

        $transport->send($this->makeEvent(
            TraceEventType::STEP_FAILED,
            'trace-abc',
            ['error' => 'step blew up'],
            'step-1',
        ));

        $body = json_decode($history->log[0]['request']->getBody()->getContents(), true);

        $this->assertSame('PATCH', $history->log[0]['request']->getMethod());
        $this->assertSame('FAILED', $body['status']);
        $this->assertSame('step blew up', $body['error']);
    }

    // -------------------------------------------------------------------------
    // Log
    // -------------------------------------------------------------------------

    public function test_log_emitted_posts_to_logs_endpoint(): void
    {
        [$transport, $history] = $this->makeTransport([new Response(201)]);

        $transport->send($this->makeEvent(
            TraceEventType::LOG_EMITTED,
            'trace-abc',
            ['message' => 'User logged in', 'level' => 'INFO'],
        ));

        $request = $history->log[0]['request'];
        $body    = json_decode($request->getBody()->getContents(), true);

        $this->assertSame('POST', $request->getMethod());
        $this->assertStringContainsString('/api/v1/logs', (string) $request->getUri());
        $this->assertSame('User logged in', $body['message']);
        $this->assertSame('INFO', $body['level']);
        $this->assertSame('trace-abc', $body['trace_id']);
        $this->assertSame('event-1', $body['log_id']);
    }

    // -------------------------------------------------------------------------
    // Authentication
    // -------------------------------------------------------------------------

    public function test_api_key_sent_in_header(): void
    {
        // For authentication tests we need the real buildClient to pick up the api_key
        // header, so we rebuild the transport without overriding the client. Instead,
        // we inject a client that keeps the default headers from the constructor.
        $history = new \stdClass();
        $history->log = [];

        $mock = new MockHandler([new Response(201)]);
        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push(Middleware::history($history->log));

        // Build transport; the constructor wires the api_key header into the Guzzle client.
        $transport = new HttpTransport([
            'endpoint'    => self::ENDPOINT,
            'timeout'     => 5.0,
            'max_retries' => 0,
            'retry_delay' => 0,
            'silent_errors' => false,
            'api_key'     => 'my-key',
        ]);

        // We must re-inject to capture history, but we need to preserve the headers.
        // Build a client with the same headers plus the handler stack.
        $ref = new \ReflectionClass($transport);
        $ref->getProperty('client')->setValue($transport, new Client([
            'handler'  => $handlerStack,
            'base_uri' => self::ENDPOINT,
            'headers'  => [
                'Content-Type' => 'application/json',
                'X-API-Key'    => 'my-key',
            ],
        ]));

        $transport->send($this->makeEvent(TraceEventType::TRACE_STARTED));

        $request = $history->log[0]['request'];
        $this->assertSame('my-key', $request->getHeaderLine('X-API-Key'));
    }

    // -------------------------------------------------------------------------
    // Retry logic
    // -------------------------------------------------------------------------

    public function test_retry_on_failure_succeeds_on_third_attempt(): void
    {
        $failure = new RequestException('timeout', new Request('POST', '/api/v1/traces'));

        [$transport, $history] = $this->makeTransport(
            [$failure, $failure, new Response(201)],
            ['max_retries' => 3, 'retry_delay' => 0, 'silent_errors' => false],
        );

        $transport->send($this->makeEvent(TraceEventType::TRACE_STARTED));

        $this->assertCount(3, $history->log, 'Should have made exactly 3 requests (2 failures + 1 success)');
    }

    // -------------------------------------------------------------------------
    // Flush / shutdown — tests rely on circuit-breaker to populate the queue
    // -------------------------------------------------------------------------

    /**
     * Helper: open the circuit breaker and queue two events, then expire the
     * circuit timeout so flush()/shutdown() will drain the queue.
     *
     * Returns [$transport, $history, $queueProp].
     */
    private function prepareQueuedTransport(array $extraResponses = []): array
    {
        $failure = new RequestException('err', new Request('POST', '/api/v1/traces'));

        [$transport, $history] = $this->makeTransport(
            array_merge([$failure], $extraResponses),
            [
                'max_retries'               => 0,
                'retry_delay'               => 0,
                'silent_errors'             => true,
                'circuit_breaker_threshold' => 1,
            ],
        );

        // First send fails, which opens the circuit (threshold=1).
        $transport->send($this->makeEvent(TraceEventType::TRACE_STARTED, 'trace-1', [], null, 'e1'));

        // Next two are queued because the circuit is open.
        $transport->send($this->makeEvent(TraceEventType::TRACE_STARTED, 'trace-2', [], null, 'e2'));
        $transport->send($this->makeEvent(TraceEventType::TRACE_STARTED, 'trace-3', [], null, 'e3'));

        // Expire the circuit so drainQueue() will send events on the next call.
        $parentRef = new \ReflectionClass(\Smartness\TraceFlow\Transport\AbstractHttpTransport::class);
        $parentRef->getProperty('circuitOpenUntil')->setValue($transport, (int)(microtime(true) * 1000) - 1);

        $queueProp = $parentRef->getProperty('eventQueue');

        return [$transport, $history, $queueProp];
    }

    public function test_flush_drains_event_queue(): void
    {
        [$transport, , $queueProp] = $this->prepareQueuedTransport([new Response(201), new Response(201)]);

        $this->assertCount(2, $queueProp->getValue($transport), 'Two events should be queued before flush');

        $transport->flush();

        $this->assertEmpty($queueProp->getValue($transport), 'Queue should be empty after flush()');
    }

    public function test_shutdown_calls_drain_queue(): void
    {
        [$transport, , $queueProp] = $this->prepareQueuedTransport([new Response(201), new Response(201)]);

        $this->assertCount(2, $queueProp->getValue($transport), 'Two events should be queued before shutdown');

        $transport->shutdown();

        $this->assertEmpty($queueProp->getValue($transport), 'Queue should be empty after shutdown()');
    }

    // -------------------------------------------------------------------------
    // Error propagation
    // -------------------------------------------------------------------------

    public function test_silent_errors_false_throws_on_failure(): void
    {
        $this->expectException(\GuzzleHttp\Exception\GuzzleException::class);

        [$transport] = $this->makeTransport(
            [new RequestException('server down', new Request('POST', '/api/v1/traces'))],
            ['max_retries' => 0, 'retry_delay' => 0, 'silent_errors' => false],
        );

        $transport->send($this->makeEvent(TraceEventType::TRACE_STARTED));
    }

    // -------------------------------------------------------------------------
    // Payload hygiene
    // -------------------------------------------------------------------------

    public function test_null_values_filtered_from_payload(): void
    {
        [$transport, $history] = $this->makeTransport([new Response(201)]);

        // Provide only mandatory fields; all optional payload keys are absent.
        $event = new TraceEvent(
            eventId: 'event-1',
            eventType: TraceEventType::TRACE_STARTED,
            traceId: 'trace-abc',
            timestamp: self::TIMESTAMP,
            source: 'test-service',
            payload: [],
        );

        $transport->send($event);

        $body = json_decode($history->log[0]['request']->getBody()->getContents(), true);

        // Optional fields must not appear in the serialised body.
        foreach (['title', 'description', 'owner', 'tags', 'metadata', 'params', 'trace_type', 'trace_timeout_ms', 'step_timeout_ms'] as $key) {
            $this->assertArrayNotHasKey($key, $body, "Key '$key' should not be present when null");
        }

        // Mandatory fields must still be present.
        $this->assertArrayHasKey('trace_id', $body);
        $this->assertArrayHasKey('status', $body);
        $this->assertArrayHasKey('source', $body);
        $this->assertArrayHasKey('created_at', $body);
    }
}
