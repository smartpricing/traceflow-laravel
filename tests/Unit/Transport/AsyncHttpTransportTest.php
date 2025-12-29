<?php

namespace Smartness\TraceFlow\Tests\Unit\Transport;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Smartness\TraceFlow\DTO\TraceEvent;
use Smartness\TraceFlow\Enums\TraceEventType;
use Smartness\TraceFlow\Transport\AsyncHttpTransport;

class AsyncHttpTransportTest extends TestCase
{
    private function createTransport(MockHandler $mockHandler, array $config = []): AsyncHttpTransport
    {
        $handlerStack = HandlerStack::create($mockHandler);

        $defaultConfig = [
            'endpoint' => 'http://localhost:3009',
            'timeout' => 5.0,
            'max_retries' => 3,
            'retry_delay' => 100, // Shorter for tests
            'silent_errors' => true,
        ];

        $config = array_merge($defaultConfig, $config);

        // Use reflection to inject custom client
        $transport = new AsyncHttpTransport($config);

        $reflection = new \ReflectionClass($transport);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);

        $client = new Client([
            'handler' => $handlerStack,
            'base_uri' => $config['endpoint'],
            'timeout' => $config['timeout'],
        ]);

        $clientProperty->setValue($transport, $client);

        return $transport;
    }

    public function test_send_trace_started_event_async(): void
    {
        // Mock successful HTTP response
        $mock = new MockHandler([
            new Response(201, [], '{"trace_id": "test-123"}'),
        ]);

        $transport = $this->createTransport($mock);

        $event = new TraceEvent(
            eventId: 'event-1',
            eventType: TraceEventType::TRACE_STARTED,
            traceId: 'trace-123',
            timestamp: now()->toIso8601String(),
            source: 'test-source',
            payload: [
                'title' => 'Test Trace',
                'trace_type' => 'test',
            ]
        );

        // Send should return immediately (non-blocking)
        $start = microtime(true);
        $transport->send($event);
        $duration = microtime(true) - $start;

        // Should return in less than 10ms (async)
        $this->assertLessThan(0.01, $duration, 'send() should be non-blocking');

        // Flush to settle promises
        $transport->flush();

        // Verify request was made
        $this->assertCount(0, $mock, 'All requests should be consumed');
    }

    public function test_send_multiple_events_async(): void
    {
        // Mock responses for multiple requests
        $mock = new MockHandler([
            new Response(201), // TRACE_STARTED
            new Response(201), // STEP_STARTED
            new Response(200), // STEP_FINISHED
            new Response(200), // TRACE_FINISHED
        ]);

        $transport = $this->createTransport($mock);

        // Send multiple events
        $events = [
            new TraceEvent('e1', TraceEventType::TRACE_STARTED, 'trace-1', now()->toIso8601String(), 'test', []),
            new TraceEvent('e2', TraceEventType::STEP_STARTED, 'trace-1', now()->toIso8601String(), 'test', [], 'step-1'),
            new TraceEvent('e3', TraceEventType::STEP_FINISHED, 'trace-1', now()->toIso8601String(), 'test', [], 'step-1'),
            new TraceEvent('e4', TraceEventType::TRACE_FINISHED, 'trace-1', now()->toIso8601String(), 'test', []),
        ];

        $start = microtime(true);

        foreach ($events as $event) {
            $transport->send($event);
        }

        $duration = microtime(true) - $start;

        // All 4 sends should complete quickly (async)
        $this->assertLessThan(0.02, $duration, 'Multiple async sends should be fast');

        // Flush all promises
        $transport->flush();

        $this->assertCount(0, $mock, 'All requests should be processed');
    }

    public function test_async_retry_on_failure(): void
    {
        // Mock: fail twice, then succeed
        $mock = new MockHandler([
            new RequestException('Connection timeout', new Request('POST', '/api/v1/traces')),
            new RequestException('Connection timeout', new Request('POST', '/api/v1/traces')),
            new Response(201), // Success on third attempt
        ]);

        $transport = $this->createTransport($mock, ['max_retries' => 3]);

        $event = new TraceEvent(
            eventId: 'event-1',
            eventType: TraceEventType::TRACE_STARTED,
            traceId: 'trace-123',
            timestamp: now()->toIso8601String(),
            source: 'test',
            payload: []
        );

        $transport->send($event);

        // Flush should retry and eventually succeed
        $transport->flush();

        // All mocked responses consumed (2 failures + 1 success)
        $this->assertCount(0, $mock, 'Should retry and succeed');
    }

    public function test_silent_errors_suppresses_exceptions(): void
    {
        // Mock: all requests fail
        $mock = new MockHandler([
            new RequestException('Error', new Request('POST', '/api/v1/traces')),
            new RequestException('Error', new Request('POST', '/api/v1/traces')),
            new RequestException('Error', new Request('POST', '/api/v1/traces')),
            new RequestException('Error', new Request('POST', '/api/v1/traces')),
        ]);

        $transport = $this->createTransport($mock, [
            'silent_errors' => true,
            'max_retries' => 3,
        ]);

        $event = new TraceEvent(
            eventId: 'event-1',
            eventType: TraceEventType::TRACE_STARTED,
            traceId: 'trace-123',
            timestamp: now()->toIso8601String(),
            source: 'test',
            payload: []
        );

        // Should not throw exception
        $transport->send($event);
        $transport->flush();

        // No exception = test passes
        $this->assertTrue(true);
    }

    public function test_flush_settles_all_promises(): void
    {
        $requestCount = 0;

        $mock = new MockHandler([
            function () use (&$requestCount) {
                $requestCount++;

                return new Response(201);
            },
            function () use (&$requestCount) {
                $requestCount++;

                return new Response(201);
            },
            function () use (&$requestCount) {
                $requestCount++;

                return new Response(201);
            },
        ]);

        $transport = $this->createTransport($mock);

        // Send 3 events
        for ($i = 0; $i < 3; $i++) {
            $event = new TraceEvent(
                eventId: "event-$i",
                eventType: TraceEventType::TRACE_STARTED,
                traceId: "trace-$i",
                timestamp: now()->toIso8601String(),
                source: 'test',
                payload: []
            );
            $transport->send($event);
        }

        // At this point, requests may not have executed yet
        // Flush should settle all promises
        $transport->flush();

        // All 3 requests should have been made
        $this->assertEquals(3, $requestCount, 'flush() should settle all promises');
    }

    public function test_shutdown_calls_flush(): void
    {
        $mock = new MockHandler([
            new Response(201),
        ]);

        $transport = $this->createTransport($mock);

        $event = new TraceEvent(
            eventId: 'event-1',
            eventType: TraceEventType::TRACE_STARTED,
            traceId: 'trace-123',
            timestamp: now()->toIso8601String(),
            source: 'test',
            payload: []
        );

        $transport->send($event);

        // shutdown() should call flush()
        $transport->shutdown();

        $this->assertCount(0, $mock, 'shutdown() should flush pending requests');
    }

    public function test_trace_finished_event(): void
    {
        $mock = new MockHandler([
            new Response(200),
        ]);

        $transport = $this->createTransport($mock);

        $event = new TraceEvent(
            eventId: 'event-1',
            eventType: TraceEventType::TRACE_FINISHED,
            traceId: 'trace-123',
            timestamp: now()->toIso8601String(),
            source: 'test',
            payload: [
                'result' => ['success' => true],
                'metadata' => ['duration' => 1234],
            ]
        );

        $transport->send($event);
        $transport->flush();

        $this->assertCount(0, $mock);
    }

    public function test_trace_failed_event(): void
    {
        $mock = new MockHandler([
            new Response(200),
        ]);

        $transport = $this->createTransport($mock);

        $event = new TraceEvent(
            eventId: 'event-1',
            eventType: TraceEventType::TRACE_FAILED,
            traceId: 'trace-123',
            timestamp: now()->toIso8601String(),
            source: 'test',
            payload: [
                'error' => 'Something went wrong',
                'stack' => 'Stack trace...',
            ]
        );

        $transport->send($event);
        $transport->flush();

        $this->assertCount(0, $mock);
    }

    public function test_step_started_event(): void
    {
        $mock = new MockHandler([
            new Response(201),
        ]);

        $transport = $this->createTransport($mock);

        $event = new TraceEvent(
            eventId: 'event-1',
            eventType: TraceEventType::STEP_STARTED,
            traceId: 'trace-123',
            timestamp: now()->toIso8601String(),
            source: 'test',
            payload: [
                'name' => 'Database Query',
                'step_type' => 'database',
                'input' => ['query' => 'SELECT * FROM users'],
            ],
            stepId: 'step-456'
        );

        $transport->send($event);
        $transport->flush();

        $this->assertCount(0, $mock);
    }

    public function test_log_emitted_event(): void
    {
        $mock = new MockHandler([
            new Response(201),
        ]);

        $transport = $this->createTransport($mock);

        $event = new TraceEvent(
            eventId: 'event-1',
            eventType: TraceEventType::LOG_EMITTED,
            traceId: 'trace-123',
            timestamp: now()->toIso8601String(),
            source: 'test',
            payload: [
                'message' => 'Processing started',
                'level' => 'INFO',
                'details' => ['user_id' => 42],
            ]
        );

        $transport->send($event);
        $transport->flush();

        $this->assertCount(0, $mock);
    }

    public function test_with_api_key_authentication(): void
    {
        $mock = new MockHandler([
            new Response(201),
        ]);

        $transport = $this->createTransport($mock, [
            'api_key' => 'test-api-key-123',
        ]);

        $event = new TraceEvent(
            eventId: 'event-1',
            eventType: TraceEventType::TRACE_STARTED,
            traceId: 'trace-123',
            timestamp: now()->toIso8601String(),
            source: 'test',
            payload: []
        );

        $transport->send($event);
        $transport->flush();

        $this->assertCount(0, $mock);
    }

    public function test_with_basic_authentication(): void
    {
        $mock = new MockHandler([
            new Response(201),
        ]);

        $transport = $this->createTransport($mock, [
            'username' => 'testuser',
            'password' => 'testpass',
        ]);

        $event = new TraceEvent(
            eventId: 'event-1',
            eventType: TraceEventType::TRACE_STARTED,
            traceId: 'trace-123',
            timestamp: now()->toIso8601String(),
            source: 'test',
            payload: []
        );

        $transport->send($event);
        $transport->flush();

        $this->assertCount(0, $mock);
    }

    public function test_performance_overhead_is_minimal(): void
    {
        $mock = new MockHandler(
            array_fill(0, 100, new Response(201))
        );

        $transport = $this->createTransport($mock);

        $start = microtime(true);

        // Send 100 events
        for ($i = 0; $i < 100; $i++) {
            $event = new TraceEvent(
                eventId: "event-$i",
                eventType: TraceEventType::TRACE_STARTED,
                traceId: "trace-$i",
                timestamp: now()->toIso8601String(),
                source: 'test',
                payload: []
            );
            $transport->send($event);
        }

        $sendDuration = microtime(true) - $start;

        // Average per event should be very low (async)
        $avgPerEvent = $sendDuration / 100;

        $this->assertLessThan(0.001, $avgPerEvent, 'Async send should take <1ms per event on average');

        // Flush all
        $transport->flush();
    }
}
