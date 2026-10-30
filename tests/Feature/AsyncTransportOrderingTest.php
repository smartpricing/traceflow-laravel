<?php

namespace Smartness\TraceFlow\Tests\Feature;

use GuzzleHttp\Client;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\Utils as PromiseUtils;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Smartness\TraceFlow\DTO\TraceEvent;
use Smartness\TraceFlow\Enums\TraceEventType;
use Smartness\TraceFlow\Transport\AsyncHttpTransport;

/**
 * Per-trace request ordering in the async transport.
 *
 * A step's update (PATCH) must not be transferred until its create (POST) has
 * completed, or the server can 404 on a step it has not persisted yet. A
 * trace's create goes before its steps and logs, and its terminal update
 * after them (this used to need a blocking flush inside TraceHandle::finish()).
 *
 * The handler answers each request a few event-loop turns later and records
 * an interleaved log of when requests are issued and answered.
 */
class AsyncTransportOrderingTest extends TestCase
{
    private const TRACE_ID = '11111111-1111-1111-1111-111111111111';

    private const STEP_ID = '22222222-2222-2222-2222-222222222222';

    private const STEP_2_ID = '33333333-3333-3333-3333-333333333333';

    /** @var list<string> */
    private array $log = [];

    private int $inFlight = 0;

    private int $maxInFlight = 0;

    public function test_step_update_is_not_issued_before_its_create_resolves(): void
    {
        $transport = $this->makeTransport();

        $transport->send($this->event('e1', TraceEventType::STEP_STARTED, self::STEP_ID));
        $transport->send($this->event('e2', TraceEventType::STEP_FINISHED, self::STEP_ID));

        $transport->flush();

        $this->assertBefore('resolve POST /api/v1/steps', 'issue PATCH /api/v1/steps/'.self::TRACE_ID.'/'.self::STEP_ID);
    }

    public function test_trace_create_first_and_terminal_update_last(): void
    {
        $transport = $this->makeTransport(concurrency: 4);

        $transport->send($this->event('e1', TraceEventType::TRACE_STARTED));
        $transport->send($this->event('e2', TraceEventType::STEP_STARTED, self::STEP_ID));
        $transport->send($this->event('e3', TraceEventType::STEP_STARTED, self::STEP_2_ID));
        $transport->send($this->event('e4', TraceEventType::LOG_EMITTED));
        $transport->send($this->event('e5', TraceEventType::STEP_FINISHED, self::STEP_ID));
        $transport->send($this->event('e6', TraceEventType::STEP_FAILED, self::STEP_2_ID));
        $transport->send($this->event('e7', TraceEventType::TRACE_FINISHED));

        $transport->flush();

        $issued = array_values(array_filter($this->log, fn ($line) => str_starts_with($line, 'issue ')));
        $this->assertCount(7, $issued, implode(' | ', $this->log));

        // The trace create goes alone and first.
        $this->assertSame('issue POST /api/v1/traces', $issued[0]);
        $this->assertBefore('resolve POST /api/v1/traces', 'issue POST /api/v1/steps');
        $this->assertBefore('resolve POST /api/v1/traces', 'issue POST /api/v1/logs');

        // Each step update after its own create.
        $this->assertBefore('resolve POST /api/v1/steps', 'issue PATCH /api/v1/steps/'.self::TRACE_ID.'/'.self::STEP_ID);

        // The terminal update after everything else of the trace was answered.
        $this->assertSame('issue PATCH /api/v1/traces/'.self::TRACE_ID, $issued[6]);
        $terminal = array_search('issue PATCH /api/v1/traces/'.self::TRACE_ID, $this->log, true);
        $this->assertCount(6, array_filter(
            array_slice($this->log, 0, $terminal),
            fn ($line) => str_starts_with($line, 'resolve '),
        ), 'all six earlier requests must be answered before the terminal update is issued');

        // Independent requests ran in parallel: two step creates and the log together.
        $this->assertGreaterThanOrEqual(3, $this->maxInFlight);
    }

    public function test_concurrency_is_bounded(): void
    {
        $transport = $this->makeTransport(concurrency: 2);

        for ($i = 0; $i < 10; $i++) {
            $transport->send(new TraceEvent(
                eventId: "e{$i}",
                eventType: TraceEventType::TRACE_STARTED,
                traceId: sprintf('00000000-0000-0000-0000-%012d', $i),
                timestamp: '2026-01-01T00:00:00.000Z',
                source: 'test',
                payload: [],
            ));
        }

        $transport->flush();

        $this->assertSame(10, count(array_filter($this->log, fn ($line) => str_starts_with($line, 'issue '))));
        $this->assertSame(2, $this->maxInFlight);
    }

    private function event(string $eventId, TraceEventType $type, ?string $stepId = null): TraceEvent
    {
        return new TraceEvent(
            eventId: $eventId,
            eventType: $type,
            traceId: self::TRACE_ID,
            timestamp: '2026-01-01T00:00:00.000Z',
            source: 'test',
            payload: $type === TraceEventType::LOG_EMITTED ? ['message' => 'hello'] : ['name' => 'step'],
            stepId: $stepId,
        );
    }

    private function assertBefore(string $first, string $then): void
    {
        $firstAt = array_search($first, $this->log, true);
        $thenAt = array_search($then, $this->log, true);

        $this->assertNotFalse($firstAt, "'{$first}' never happened; log: ".implode(' | ', $this->log));
        $this->assertNotFalse($thenAt, "'{$then}' never happened; log: ".implode(' | ', $this->log));
        $this->assertLessThan($thenAt, $firstAt, "'{$first}' must happen before '{$then}'; log: ".implode(' | ', $this->log));
    }

    /**
     * Build an AsyncHttpTransport whose Guzzle client answers each request
     * three event-loop turns after it was issued.
     */
    private function makeTransport(int $concurrency = 4): AsyncHttpTransport
    {
        $handler = function (RequestInterface $request) {
            $key = $request->getMethod().' '.$request->getUri()->getPath();
            $this->log[] = "issue {$key}";
            $this->maxInFlight = max($this->maxInFlight, ++$this->inFlight);

            $promise = new Promise;
            $turns = 3;
            $answer = function () use (&$answer, &$turns, $promise, $key) {
                if (--$turns > 0) {
                    PromiseUtils::queue()->add($answer);

                    return;
                }
                $this->inFlight--;
                $this->log[] = "resolve {$key}";
                $promise->resolve(new Response(200, [], '{}'));
            };
            PromiseUtils::queue()->add($answer);

            return $promise;
        };

        $config = [
            'endpoint' => 'http://traceflow.test',
            'silent_errors' => true,
            'timeout' => 5.0,
            'max_retries' => 0,
            'concurrency' => $concurrency,
        ];

        return new class($config, $handler) extends AsyncHttpTransport
        {
            /** @var callable */
            private $testHandler;

            public function __construct(array $config, callable $handler)
            {
                // Assigned before parent constructor, which calls buildClient().
                $this->testHandler = $handler;
                parent::__construct($config);
            }

            protected function buildClient(array $options): Client
            {
                $options['handler'] = $this->testHandler;

                return new Client($options);
            }
        };
    }
}
