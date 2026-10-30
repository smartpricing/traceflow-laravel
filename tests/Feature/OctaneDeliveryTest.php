<?php

namespace Smartness\TraceFlow\Tests\Feature;

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Laravel\Octane\ApplicationFactory;
use Laravel\Octane\Events\RequestTerminated;
use Laravel\Octane\OctaneResponse;
use Laravel\Octane\OctaneServiceProvider;
use Laravel\Octane\RequestContext;
use Laravel\Octane\Testing\Fakes\FakeClient;
use Laravel\Octane\Testing\Fakes\FakeWorker;
use Orchestra\Testbench\TestCase;
use Smartness\TraceFlow\Context\TraceFlowContext;
use Smartness\TraceFlow\Middleware\TraceFlowMiddleware;
use Smartness\TraceFlow\Tests\Support\LocalServer;
use Smartness\TraceFlow\TraceFlowSDK;
use Smartness\TraceFlow\TraceFlowServiceProvider;

/**
 * Under Octane the worker answers the client, then terminates the request:
 * trace events must be sent after the response, within flush_timeout, and
 * each request must get its own SDK (no state kept across requests).
 *
 * Requests go through Octane's own worker (FakeWorker / FakeClient), so the
 * order is Octane's: handle, respond, terminate.
 */
class OctaneDeliveryTest extends TestCase
{
    private const FLUSH_TIMEOUT = 0.5;

    private const SLACK = 0.3;

    private ?LocalServer $server = null;

    protected function setUp(): void
    {
        if (! class_exists(FakeWorker::class)) {
            $this->markTestSkipped('laravel/octane is not installed');
        }

        parent::setUp();
    }

    protected function tearDown(): void
    {
        $this->server?->stop();
        TraceFlowContext::clear();
        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [OctaneServiceProvider::class, TraceFlowServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('traceflow.source', 'octane-test');
        $app['config']->set('traceflow.flush_timeout', self::FLUSH_TIMEOUT);
        $app['config']->set('traceflow.middleware.enabled', true);
        $app['config']->set('traceflow.circuit_breaker.shared', false);
    }

    protected function defineRoutes($router): void
    {
        $router->get('/orders', function () {
            $sdk = app(TraceFlowSDK::class);
            $step = $sdk->startStep(name: 'load order');
            $step?->log('loaded');
            $step?->finish(['id' => 1]);

            return response('OK');
        })->middleware(TraceFlowMiddleware::class);
    }

    /**
     * Runs the requests through an Octane worker. Returns, per request, the
     * time from the start of handling to the response being sent, and to the
     * end of the request (terminate included).
     *
     * @return list<array{respond: float, done: float, status: int}>
     */
    private function serve(string $endpoint, int $count): array
    {
        $this->app['config']->set('traceflow.endpoint', $endpoint);

        $client = new class(array_fill(0, $count, Request::create('/orders', 'GET'))) extends FakeClient
        {
            /** @var list<float> */
            public array $respondedAt = [];

            public function respond(RequestContext $context, OctaneResponse $octaneResponse): void
            {
                $this->respondedAt[] = microtime(true);
                parent::respond($context, $octaneResponse);
            }
        };

        $app = $this->app;
        $factory = new class($app) extends ApplicationFactory
        {
            public function __construct(private Application $app)
            {
                parent::__construct(sys_get_temp_dir());
            }

            public function createApplication(array $initialInstances = []): Application
            {
                foreach ($initialInstances as $abstract => $instance) {
                    $this->app->instance($abstract, $instance);
                }

                return $this->app;
            }
        };

        $worker = new FakeWorker($factory, $client);
        $worker->boot();

        $timings = [];
        foreach ($client->requests as $i => $request) {
            [$request, $context] = $client->marshalRequest(new RequestContext(['request' => $request]));
            $start = microtime(true);
            $worker->handle($request, $context);
            $timings[] = [
                'respond' => $client->respondedAt[$i] - $start,
                'done' => microtime(true) - $start,
                'status' => $client->responses[$i]->getStatusCode(),
            ];
        }

        return $timings;
    }

    public function test_events_are_sent_after_the_response_and_every_request_is_delivered(): void
    {
        $this->server = LocalServer::start();

        $timings = $this->serve($this->server->url, 3);

        foreach ($timings as $timing) {
            $this->assertSame(200, $timing['status']);
        }

        // Each request: create trace, create step, log, finish step, finish trace.
        $paths = array_map(fn (array $r) => $r['method'].' '.preg_replace('/[0-9a-f-]{36}/', '{id}', $r['path']), $this->server->requests());
        $this->assertCount(15, $paths);
        $this->assertSame(3, count(array_keys($paths, 'POST /api/v1/traces')));
        $this->assertSame(3, count(array_keys($paths, 'PATCH /api/v1/traces/{id}')));
    }

    public function test_a_hanging_server_delays_neither_the_response_nor_the_worker_beyond_flush_timeout(): void
    {
        $this->server = LocalServer::hanging();

        $timings = $this->serve($this->server->url, 3);

        foreach ($timings as $timing) {
            $this->assertSame(200, $timing['status']);
            $this->assertLessThan(0.1, $timing['respond'], 'the response waited for Traceflow');
            $this->assertLessThan(self::FLUSH_TIMEOUT + self::SLACK, $timing['done'], 'terminate exceeded flush_timeout');
        }
    }

    public function test_each_request_gets_its_own_sdk(): void
    {
        $this->server = LocalServer::start();
        $seen = [];
        $this->app['events']->listen(RequestTerminated::class, function ($event) use (&$seen) {
            $seen[] = spl_object_id($event->sandbox->make(TraceFlowSDK::class));
        });

        $this->serve($this->server->url, 2);

        $this->assertCount(2, $seen);
        $this->assertFalse($this->app->resolved(TraceFlowSDK::class) && in_array(spl_object_id($this->app->make(TraceFlowSDK::class)), $seen, true),
            'the SDK resolved during a request leaked into the base application');
    }
}
