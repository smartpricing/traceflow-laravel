<?php

namespace Smartness\TraceFlow\Tests\Feature;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Smartness\TraceFlow\DTO\TraceEvent;
use Smartness\TraceFlow\Enums\TraceEventType;
use Smartness\TraceFlow\Transport\HttpTransport;

/**
 * Verifies the transport retry policy:
 *  - 4xx client errors are not retried (deterministic — retrying wastes attempts)
 *  - 5xx server errors are retried
 *  - 409 Conflict is benign (the entity already exists, e.g. a trace_id shared
 *    across services in distributed tracing) and must not surface as an error
 */
class RetryPolicyTest extends TestCase
{
    private function traceEvent(): TraceEvent
    {
        return new TraceEvent(
            eventId: 'e1',
            eventType: TraceEventType::TRACE_STARTED,
            traceId: '11111111-1111-1111-1111-111111111111',
            timestamp: '2026-01-01T00:00:00.000Z',
            source: 'test',
            payload: ['title' => 't'],
        );
    }

    public function test_4xx_is_not_retried(): void
    {
        $mock = new MockHandler(array_fill(0, 5, new Response(404)));
        $transport = $this->makeTransport($mock);

        $transport->send($this->traceEvent());

        // One request issued, four responses left unconsumed: no retries on 4xx.
        $this->assertSame(4, $mock->count());
    }

    public function test_5xx_is_retried(): void
    {
        $mock = new MockHandler(array_fill(0, 5, new Response(503)));
        $transport = $this->makeTransport($mock, ['max_retries' => 2]);

        $transport->send($this->traceEvent());

        // One initial attempt + two retries = three consumed, two left.
        $this->assertSame(2, $mock->count());
    }

    public function test_409_conflict_is_benign(): void
    {
        $mock = new MockHandler([new Response(409)]);
        // silent_errors = false: a genuine error would be thrown from send().
        // A benign 409 must be swallowed without retry or exception.
        $transport = $this->makeTransport($mock, ['silent_errors' => false]);

        $transport->send($this->traceEvent());

        $this->assertSame(0, $mock->count());
    }

    private function makeTransport(MockHandler $mock, array $overrides = []): HttpTransport
    {
        $config = array_merge([
            'endpoint' => 'http://traceflow.test',
            'silent_errors' => true,
            'timeout' => 5.0,
            'max_retries' => 3,
            'retry_delay' => 1,
        ], $overrides);

        return new class($config, $mock) extends HttpTransport
        {
            private MockHandler $mock;

            public function __construct(array $config, MockHandler $mock)
            {
                $this->mock = $mock;
                parent::__construct($config);
            }

            protected function buildClient(array $options): Client
            {
                // HandlerStack::create adds the default middleware (incl. http_errors)
                // so mocked 4xx/5xx responses throw exactly as in production.
                $options['handler'] = HandlerStack::create($this->mock);

                return new Client($options);
            }
        };
    }
}
