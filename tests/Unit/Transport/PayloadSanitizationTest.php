<?php

namespace Smartness\TraceFlow\Tests\Unit\Transport;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Smartness\TraceFlow\DTO\TraceEvent;
use Smartness\TraceFlow\Enums\TraceEventType;
use Smartness\TraceFlow\Transport\HttpTransport;

class PayloadSanitizationTest extends TestCase
{
    private const ENDPOINT  = 'http://localhost:3009';
    private const TIMESTAMP = '2026-03-24T12:00:00.000Z';

    private function makeTransport(array $responses): array
    {
        $history = new \stdClass();
        $history->log = [];

        $mock = new MockHandler($responses);
        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push(Middleware::history($history->log));

        $transport = new HttpTransport([
            'endpoint'      => self::ENDPOINT,
            'timeout'       => 5.0,
            'max_retries'   => 0,
            'retry_delay'   => 0,
            'silent_errors' => false,
        ]);

        $ref = new \ReflectionClass($transport);
        $ref->getProperty('client')->setValue($transport, new Client([
            'handler'  => $handlerStack,
            'base_uri' => self::ENDPOINT,
        ]));

        return [$transport, $history];
    }

    private function sendAndGetBody(TraceEventType $type, array $payload, ?string $stepId = null): array
    {
        [$transport, $history] = $this->makeTransport([new Response(201)]);

        $event = new TraceEvent(
            eventId: 'event-1',
            eventType: $type,
            traceId: 'trace-abc',
            timestamp: self::TIMESTAMP,
            source: 'test-service',
            payload: $payload,
            stepId: $stepId,
        );

        $transport->send($event);

        return json_decode($history->log[0]['request']->getBody()->getContents(), true);
    }

    // -------------------------------------------------------------------------
    // Closure / callable
    // -------------------------------------------------------------------------

    public function test_closure_in_params_is_sanitized(): void
    {
        $body = $this->sendAndGetBody(TraceEventType::TRACE_STARTED, [
            'params' => function () { return 'hello'; },
        ]);

        $this->assertSame('[Closure]', $body['params']);
    }

    public function test_closure_in_step_input_is_sanitized(): void
    {
        $body = $this->sendAndGetBody(TraceEventType::STEP_STARTED, [
            'input' => fn () => 42,
        ], 'step-1');

        $this->assertSame('[Closure]', $body['input']);
    }

    public function test_closure_in_step_output_is_sanitized(): void
    {
        $body = $this->sendAndGetBody(TraceEventType::STEP_FINISHED, [
            'output' => fn () => 'nope',
        ], 'step-1');

        $this->assertSame('[Closure]', $body['output']);
    }

    public function test_closure_in_trace_result_is_sanitized(): void
    {
        $body = $this->sendAndGetBody(TraceEventType::TRACE_FINISHED, [
            'result' => fn () => null,
        ]);

        $this->assertSame('[Closure]', $body['result']);
    }

    public function test_closure_in_log_details_is_sanitized(): void
    {
        $body = $this->sendAndGetBody(TraceEventType::LOG_EMITTED, [
            'message' => 'test',
            'level' => 'INFO',
            'details' => fn () => 'debug',
        ]);

        $this->assertSame('[Closure]', $body['details']);
    }

    // -------------------------------------------------------------------------
    // Nested closures
    // -------------------------------------------------------------------------

    public function test_closure_nested_in_array_is_sanitized(): void
    {
        $body = $this->sendAndGetBody(TraceEventType::TRACE_STARTED, [
            'params' => [
                'name' => 'John',
                'callback' => fn () => true,
                'nested' => [
                    'deep' => fn () => 'value',
                ],
            ],
        ]);

        $this->assertSame('John', $body['params']['name']);
        $this->assertSame('[Closure]', $body['params']['callback']);
        $this->assertSame('[Closure]', $body['params']['nested']['deep']);
    }

    // -------------------------------------------------------------------------
    // Other non-serializable types
    // -------------------------------------------------------------------------

    public function test_resource_is_sanitized(): void
    {
        $resource = fopen('php://memory', 'r');

        $body = $this->sendAndGetBody(TraceEventType::TRACE_STARTED, [
            'params' => $resource,
        ]);

        fclose($resource);

        $this->assertStringContainsString('[resource:', $body['params']);
    }

    public function test_json_serializable_object_is_handled(): void
    {
        $obj = new class implements \JsonSerializable {
            public function jsonSerialize(): mixed
            {
                return ['key' => 'value'];
            }
        };

        $body = $this->sendAndGetBody(TraceEventType::TRACE_STARTED, [
            'params' => $obj,
        ]);

        $this->assertSame('value', $body['params']['key']);
    }

    public function test_stringable_object_is_handled(): void
    {
        $obj = new class implements \Stringable {
            public function __toString(): string
            {
                return 'my-string-repr';
            }
        };

        $body = $this->sendAndGetBody(TraceEventType::TRACE_STARTED, [
            'params' => $obj,
        ]);

        $this->assertSame('my-string-repr', $body['params']);
    }

    public function test_backed_enum_is_sanitized(): void
    {
        $body = $this->sendAndGetBody(TraceEventType::TRACE_STARTED, [
            'params' => TraceEventType::TRACE_STARTED,
        ]);

        $this->assertSame('trace_started', $body['params']);
    }

    // -------------------------------------------------------------------------
    // Normal values pass through unchanged
    // -------------------------------------------------------------------------

    public function test_scalar_values_pass_through(): void
    {
        $body = $this->sendAndGetBody(TraceEventType::TRACE_STARTED, [
            'params' => [
                'string' => 'hello',
                'int' => 42,
                'float' => 3.14,
                'bool' => true,
                'null_val' => null,
            ],
        ]);

        $this->assertSame('hello', $body['params']['string']);
        $this->assertSame(42, $body['params']['int']);
        $this->assertSame(3.14, $body['params']['float']);
        $this->assertTrue($body['params']['bool']);
        $this->assertNull($body['params']['null_val']);
    }

    // -------------------------------------------------------------------------
    // Does not crash the transport
    // -------------------------------------------------------------------------

    public function test_mixed_payload_with_closures_does_not_crash(): void
    {
        [$transport, $history] = $this->makeTransport([new Response(201)]);

        $event = new TraceEvent(
            eventId: 'event-1',
            eventType: TraceEventType::TRACE_STARTED,
            traceId: 'trace-abc',
            timestamp: self::TIMESTAMP,
            source: 'test-service',
            payload: [
                'title' => 'My Trace',
                'params' => [
                    'valid' => 'data',
                    'fn' => function () { return 'bad'; },
                    'nested' => [
                        'another_fn' => fn () => null,
                        'ok' => 123,
                    ],
                ],
            ],
        );

        // Must not throw
        $transport->send($event);

        $body = json_decode($history->log[0]['request']->getBody()->getContents(), true);

        $this->assertSame('My Trace', $body['title']);
        $this->assertSame('data', $body['params']['valid']);
        $this->assertSame('[Closure]', $body['params']['fn']);
        $this->assertSame('[Closure]', $body['params']['nested']['another_fn']);
        $this->assertSame(123, $body['params']['nested']['ok']);
    }
}
