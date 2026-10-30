<?php

namespace Smartness\TraceFlow\Tests\Feature;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Jobs\SyncJob;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Smartness\TraceFlow\Context\TraceFlowContext;
use Smartness\TraceFlow\Middleware\TraceFlowMiddleware;
use Smartness\TraceFlow\Tests\Support\LocalServer;
use Smartness\TraceFlow\TraceFlowSDK;
use Smartness\TraceFlow\TraceFlowServiceProvider;
use Smartness\TraceFlow\Transport\AbstractHttpTransport;
use Smartness\TraceFlow\Transport\CacheCircuitBreakerStore;
use Smartness\TraceFlow\Transport\CircuitBreaker;

/**
 * The Laravel integration: the middleware never waits for the Traceflow
 * server, events are sent when the app terminates (after the response under
 * PHP-FPM) and after queue jobs, each flush within flush_timeout.
 */
class LaravelDeliveryTest extends TestCase
{
    private const FLUSH_TIMEOUT = 0.5;

    private const SLACK = 0.25;

    private ?LocalServer $server = null;

    protected function getPackageProviders($app): array
    {
        return [TraceFlowServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('traceflow.source', 'laravel-test');
        $app['config']->set('traceflow.flush_timeout', self::FLUSH_TIMEOUT);
        $app['config']->set('traceflow.middleware.enabled', true);
    }

    protected function tearDown(): void
    {
        $this->server?->stop();
        TraceFlowContext::clear();
        parent::tearDown();
    }

    private function useServer(LocalServer|string $server): void
    {
        if ($server instanceof LocalServer) {
            $this->server = $server;
            $server = $server->url;
        }

        config(['traceflow.endpoint' => $server]);
    }

    private function sdk(): TraceFlowSDK
    {
        return $this->app->make(TraceFlowSDK::class);
    }

    /**
     * Run a request through the middleware, with a step in the controller.
     */
    private function handle(array $server = []): float
    {
        $middleware = $this->app->make(TraceFlowMiddleware::class);
        $request = Request::create('/orders', 'GET', server: $server);

        $start = hrtime(true);
        $middleware->handle($request, function () {
            $step = $this->sdk()->startStep(name: 'load orders');
            $step?->log('loaded');
            $step?->finish(['count' => 20]);

            return new Response('OK');
        });

        return (hrtime(true) - $start) / 1e9;
    }

    private function terminate(): float
    {
        $start = hrtime(true);
        $this->app->terminate();

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

    #[DataProvider('brokenServers')]
    public function test_middleware_never_waits_and_terminate_flush_is_bounded(string $kind): void
    {
        $this->useServer(match ($kind) {
            'hang' => LocalServer::hanging(),
            '503' => LocalServer::start('503'),
            'refused' => LocalServer::refusedUrl(),
        });
        $this->handle(); // warm up autoloading and the SDK singleton

        $handle = $this->handle();
        $continued = $this->handle(['HTTP_X_TRACE_ID' => '0b6f6a40-1f5e-4d43-9d0c-3f3c5f1e7a21']);
        $terminate = $this->terminate();

        $this->assertLessThan(0.1, $handle, "handle() took {$handle}s");
        $this->assertLessThan(0.1, $continued, "handle() with X-Trace-Id took {$continued}s");
        $this->assertLessThan(self::FLUSH_TIMEOUT + self::SLACK, $terminate, "terminate flush took {$terminate}s");
        $this->assertSame(0, $this->sdk()->getStats()['queued'], 'terminate leaves nothing queued');
    }

    public function test_events_are_delivered_at_terminate(): void
    {
        $this->useServer(LocalServer::start());

        $this->handle();
        $this->assertSame(0, $this->server->requestCount(), 'nothing is sent during the request');

        $this->terminate();

        $paths = array_map(fn ($r) => $r['method'].' '.preg_replace('/[0-9a-f-]{36}/', '{id}', $r['path']), $this->server->requests());
        $this->assertSame('POST /api/v1/traces', $paths[0]);
        $this->assertSame('PATCH /api/v1/traces/{id}', $paths[4]);
        $this->assertEqualsCanonicalizing(
            ['POST /api/v1/traces', 'POST /api/v1/steps', 'POST /api/v1/logs', 'PATCH /api/v1/steps/{id}/{id}', 'PATCH /api/v1/traces/{id}'],
            $paths,
        );
    }

    public function test_continued_trace_makes_no_request_in_the_middleware(): void
    {
        $this->useServer(LocalServer::start());
        $traceId = '0b6f6a40-1f5e-4d43-9d0c-3f3c5f1e7a21';

        $this->handle(['HTTP_X_TRACE_ID' => $traceId]);
        $this->assertSame(0, $this->server->requestCount(), 'no getTrace() round trip');

        $this->terminate();
        $methods = array_column($this->server->requests(), 'method');
        $this->assertNotContains('GET', $methods);
        $this->assertContains("/api/v1/traces/{$traceId}", array_column($this->server->requests(), 'path'));
    }

    public function test_breaker_uses_the_cache_and_is_shared_between_sdk_instances(): void
    {
        $this->useServer(LocalServer::start('503'));
        config(['traceflow.max_retries' => 0, 'traceflow.circuit_breaker.threshold' => 2]);

        // Two "requests": each gets a fresh SDK, as under PHP-FPM.
        foreach (range(1, 2) as $_) {
            $this->app->forgetInstance(TraceFlowSDK::class);
            $this->sdk()->startTrace(title: 'fails')->finish();
            $this->sdk()->shutdown();
        }

        $this->app->forgetInstance(TraceFlowSDK::class);
        $transport = (new \ReflectionProperty(TraceFlowSDK::class, 'transport'))->getValue($this->sdk());
        $this->assertInstanceOf(AbstractHttpTransport::class, $transport);
        $this->assertInstanceOf(CacheCircuitBreakerStore::class, $transport->circuitBreaker()->store());
        $this->assertSame(CircuitBreaker::OPEN, $transport->circuitBreaker()->state());

        $requests = $this->server->requestCount();
        $this->sdk()->startTrace(title: 'dropped')->finish();
        $this->sdk()->shutdown();
        $this->assertSame($requests, $this->server->requestCount());
    }

    public function test_queue_jobs_flush_after_processing_but_sync_jobs_do_not(): void
    {
        $this->useServer(LocalServer::start());
        $events = $this->app['events'];

        $this->sdk()->startTrace(title: 'job')->finish();
        $events->dispatch(new JobProcessed('sync', new SyncJob($this->app, '{}', 'sync', 'default')));
        $this->assertSame(0, $this->server->requestCount(), 'a sync job runs inside the request: no flush');

        $events->dispatch(new JobProcessed('redis', $this->createStub(Job::class)));
        $this->assertSame(2, $this->server->requestCount());

        $this->sdk()->startTrace(title: 'failing job')->fail('boom');
        $events->dispatch(new JobExceptionOccurred('redis', $this->createStub(Job::class), new \RuntimeException('boom')));
        $this->assertSame(4, $this->server->requestCount());
    }

    public function test_config_options_reach_the_transport(): void
    {
        config([
            'traceflow.endpoint' => 'http://127.0.0.1:9',
            'traceflow.flush_timeout' => '1.5',
            'traceflow.max_queue_size' => '7',
            'traceflow.concurrency' => '2',
            'traceflow.circuit_breaker.shared' => false,
        ]);

        $transport = (new \ReflectionProperty(TraceFlowSDK::class, 'transport'))->getValue($this->sdk());
        $read = fn (string $property) => (new \ReflectionProperty($transport, $property))->getValue($transport);

        $this->assertSame(1.5, $read('flushTimeout'));
        $this->assertSame(7, $read('maxQueueSize'));
        $this->assertSame(2, $read('concurrency'));
        $this->assertNotInstanceOf(CacheCircuitBreakerStore::class, $transport->circuitBreaker()->store());
    }
}
