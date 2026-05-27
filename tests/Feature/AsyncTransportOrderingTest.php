<?php

namespace Smartness\TraceFlow\Tests\Feature;

use GuzzleHttp\Client;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Smartness\TraceFlow\DTO\TraceEvent;
use Smartness\TraceFlow\Enums\TraceEventType;
use Smartness\TraceFlow\Transport\AsyncHttpTransport;

/**
 * Regression test for per-entity request ordering in the async transport.
 *
 * A step's update (PATCH) must not be transferred until its create (POST) has
 * completed; otherwise the server can receive the update for a step it has not
 * yet persisted and return 404. The previous implementation fired every queued
 * request concurrently at flush time, so the update could overtake its create.
 *
 * The test injects a Guzzle handler that defers resolution of the step-create
 * response and records an interleaved log of when each request is issued vs
 * resolved. With correct ordering, the create resolves before the update is
 * even issued.
 */
class AsyncTransportOrderingTest extends TestCase
{
    private const TRACE_ID = '11111111-1111-1111-1111-111111111111';
    private const STEP_ID = '22222222-2222-2222-2222-222222222222';

    public function test_step_update_is_not_issued_before_its_create_resolves(): void
    {
        $log = [];

        // Records when each request is issued (handler invoked) and when it
        // resolves. The step-create response is deferred until the promise is
        // waited on, so anything issued before it resolves is observable.
        $handler = function (RequestInterface $request, array $options) use (&$log) {
            $key = $request->getMethod().' '.$request->getUri()->getPath();
            $log[] = "issue {$key}";
            $response = new Response(200, [], '{}');

            if ($request->getMethod() === 'POST' && $request->getUri()->getPath() === '/api/v1/steps') {
                $promise = new Promise(function () use (&$promise, $response, $key, &$log) {
                    $log[] = "resolve {$key}";
                    $promise->resolve($response);
                });

                return $promise;
            }

            $log[] = "resolve {$key}";

            return new FulfilledPromise($response);
        };

        $transport = $this->makeTransport($handler);

        $transport->send(new TraceEvent(
            eventId: 'e1',
            eventType: TraceEventType::STEP_STARTED,
            traceId: self::TRACE_ID,
            timestamp: '2026-01-01T00:00:00.000Z',
            source: 'test',
            payload: ['name' => 'step'],
            stepId: self::STEP_ID,
        ));

        $transport->send(new TraceEvent(
            eventId: 'e2',
            eventType: TraceEventType::STEP_FINISHED,
            traceId: self::TRACE_ID,
            timestamp: '2026-01-01T00:00:00.001Z',
            source: 'test',
            payload: ['output' => 'ok'],
            stepId: self::STEP_ID,
        ));

        $transport->flush();

        $createResolved = array_search('resolve POST /api/v1/steps', $log, true);
        $updateIssued = array_search('issue PATCH /api/v1/steps/'.self::TRACE_ID.'/'.self::STEP_ID, $log, true);

        $this->assertNotFalse($createResolved, 'step create was never resolved');
        $this->assertNotFalse($updateIssued, 'step update was never issued');
        $this->assertLessThan(
            $updateIssued,
            $createResolved,
            'step update must not be issued until its create has resolved; log: '.implode(' | ', $log)
        );
    }

    /**
     * Build an AsyncHttpTransport whose Guzzle client uses the given handler.
     */
    private function makeTransport(callable $handler): AsyncHttpTransport
    {
        $config = [
            'endpoint' => 'http://traceflow.test',
            'silent_errors' => true,
            'timeout' => 5.0,
            'max_retries' => 0,
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
