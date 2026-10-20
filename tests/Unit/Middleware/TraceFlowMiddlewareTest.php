<?php

namespace Smartness\TraceFlow\Tests\Unit\Middleware;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Orchestra\Testbench\TestCase;
use Smartness\TraceFlow\Context\TraceFlowContext;
use Smartness\TraceFlow\Handles\TraceHandle;
use Smartness\TraceFlow\Middleware\TraceFlowMiddleware;
use Smartness\TraceFlow\TraceFlowSDK;

class TraceFlowMiddlewareTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('traceflow.middleware.enabled', true);
        $app['config']->set('traceflow.middleware.header_name', 'X-Trace-Id');
        $app['config']->set('traceflow.source', 'test');
    }

    protected function tearDown(): void
    {
        TraceFlowContext::clear();
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a middleware instance with the given SDK mock.
     */
    private function makeMiddleware(TraceFlowSDK $sdk): TraceFlowMiddleware
    {
        return new TraceFlowMiddleware($sdk);
    }

    /**
     * Create an SDK mock that, when startTrace() or getTrace() is called,
     * returns the provided $mockTrace.
     *
     * @return TraceFlowSDK&\PHPUnit\Framework\MockObject\MockObject
     */
    private function makeSDK(TraceHandle $mockTrace): TraceFlowSDK
    {
        $sdk = $this->createMock(TraceFlowSDK::class);

        $sdk->method('startTrace')->willReturn($mockTrace);
        $sdk->method('getTrace')->willReturn($mockTrace);

        return $sdk;
    }

    /**
     * Build a dummy TraceHandle mock whose traceId is readable.
     *
     * All five constructor parameters must be supplied so that PHP does not
     * encounter an uninitialized typed property when __destruct() fires at the
     * end of the test.
     *
     * @return TraceHandle&\PHPUnit\Framework\MockObject\MockObject
     */
    private function makeTrace(string $traceId = 'trace-test-id'): TraceHandle
    {
        $mock = $this->getMockBuilder(TraceHandle::class)
            ->setConstructorArgs([
                $traceId,              // traceId
                'test',                // source
                static function () {}, // sendEvent
                false,                 // ownsLifecycle (default)
                null,                  // onClose (default)
            ])
            ->onlyMethods(['finish', 'fail'])
            ->getMock();

        return $mock;
    }

    /**
     * Simulate passing a request through the middleware and return the response.
     *
     * @param int $statusCode The HTTP status the "next" handler returns
     */
    private function runMiddleware(
        TraceFlowMiddleware $middleware,
        Request $request,
        int $statusCode = 200,
    ): Response {
        $next = fn (Request $req) => new Response('OK', $statusCode);

        /** @var Response $response */
        $response = $middleware->handle($request, $next);

        return $response;
    }

    // -------------------------------------------------------------------------
    // 1. No X-Trace-Id header → startTrace() called, getTrace() never called
    // -------------------------------------------------------------------------

    public function test_creates_new_trace_when_no_trace_id_header(): void
    {
        $mockTrace = $this->makeTrace();
        $mockSDK = $this->makeSDK($mockTrace);

        $mockSDK->expects($this->once())->method('startTrace')->willReturn($mockTrace);
        $mockSDK->expects($this->never())->method('getTrace');

        $request = Request::create('/test', 'GET');
        $middleware = $this->makeMiddleware($mockSDK);

        $this->runMiddleware($middleware, $request);
    }

    // -------------------------------------------------------------------------
    // 2. X-Trace-Id header present → getTrace() called, startTrace() never called
    // -------------------------------------------------------------------------

    public function test_resumes_trace_when_trace_id_header_present(): void
    {
        $traceId = 'existing-trace-id';
        $mockTrace = $this->makeTrace($traceId);
        $mockSDK = $this->makeSDK($mockTrace);

        $mockSDK->expects($this->once())->method('getTrace')->with($traceId)->willReturn($mockTrace);
        $mockSDK->expects($this->never())->method('startTrace');

        $request = Request::create('/test', 'GET', [], [], [], ['HTTP_X_TRACE_ID' => $traceId]);
        $middleware = $this->makeMiddleware($mockSDK);

        $this->runMiddleware($middleware, $request);
    }

    // -------------------------------------------------------------------------
    // 3. 200 response → finish() called, fail() never called
    // -------------------------------------------------------------------------

    public function test_calls_finish_on_200_response(): void
    {
        $mockTrace = $this->makeTrace();
        $mockSDK = $this->makeSDK($mockTrace);

        $mockTrace->expects($this->once())->method('finish');
        $mockTrace->expects($this->never())->method('fail');

        $request = Request::create('/test', 'GET');
        $middleware = $this->makeMiddleware($mockSDK);

        $this->runMiddleware($middleware, $request, 200);
    }

    // -------------------------------------------------------------------------
    // 4. 404 response (client error, not server error) → finish() called, fail() never called
    // -------------------------------------------------------------------------

    public function test_calls_finish_on_404_response(): void
    {
        $mockTrace = $this->makeTrace();
        $mockSDK = $this->makeSDK($mockTrace);

        $mockTrace->expects($this->once())->method('finish');
        $mockTrace->expects($this->never())->method('fail');

        $request = Request::create('/not-found', 'GET');
        $middleware = $this->makeMiddleware($mockSDK);

        $this->runMiddleware($middleware, $request, 404);
    }

    // -------------------------------------------------------------------------
    // 5. 500 response → fail() called, finish() never called
    // -------------------------------------------------------------------------

    public function test_calls_fail_on_500_response(): void
    {
        $mockTrace = $this->makeTrace();
        $mockSDK = $this->makeSDK($mockTrace);

        $mockTrace->expects($this->once())->method('fail');
        $mockTrace->expects($this->never())->method('finish');

        $request = Request::create('/error', 'GET');
        $middleware = $this->makeMiddleware($mockSDK);

        $this->runMiddleware($middleware, $request, 500);
    }

    // -------------------------------------------------------------------------
    // 6. 503 response → fail() called
    // -------------------------------------------------------------------------

    public function test_calls_fail_on_503_response(): void
    {
        $mockTrace = $this->makeTrace();
        $mockSDK = $this->makeSDK($mockTrace);

        $mockTrace->expects($this->once())->method('fail');

        $request = Request::create('/unavailable', 'GET');
        $middleware = $this->makeMiddleware($mockSDK);

        $this->runMiddleware($middleware, $request, 503);
    }

    // -------------------------------------------------------------------------
    // 7. Response has X-Trace-Id header set to the trace ID
    // -------------------------------------------------------------------------

    public function test_response_has_trace_id_header(): void
    {
        $traceId = 'my-trace-id-123';
        $mockTrace = $this->makeTrace($traceId);
        $mockSDK = $this->makeSDK($mockTrace);

        // Pass the trace ID in the request header so the middleware uses it
        // directly instead of generating a random UUID, allowing us to assert
        // the exact value echoed back in the response.
        $request = Request::create('/test', 'GET', [], [], [], ['HTTP_X_TRACE_ID' => $traceId]);
        $middleware = $this->makeMiddleware($mockSDK);

        $response = $this->runMiddleware($middleware, $request);

        $this->assertSame($traceId, $response->headers->get('X-Trace-Id'));
    }

    // -------------------------------------------------------------------------
    // 8. TraceFlowContext is cleared after the request completes
    // -------------------------------------------------------------------------

    public function test_context_cleared_after_request(): void
    {
        TraceFlowContext::set('trace-xyz');
        $this->assertSame('trace-xyz', TraceFlowContext::currentTraceId());

        $mockTrace = $this->makeTrace('trace-xyz');
        $mockSDK = $this->makeSDK($mockTrace);

        $request = Request::create('/test', 'GET');
        $middleware = $this->makeMiddleware($mockSDK);

        $this->runMiddleware($middleware, $request);

        $this->assertNull(TraceFlowContext::currentTraceId(), 'Context should be cleared after the request');
    }

    // -------------------------------------------------------------------------
    // 9. Middleware disabled → startTrace() and getTrace() never called
    // -------------------------------------------------------------------------

    public function test_middleware_disabled_skips_tracing(): void
    {
        // Override the config for this single test
        $this->app['config']->set('traceflow.middleware.enabled', false);

        $mockSDK = $this->createMock(TraceFlowSDK::class);
        $mockSDK->expects($this->never())->method('startTrace');
        $mockSDK->expects($this->never())->method('getTrace');

        $request = Request::create('/test', 'GET');
        $middleware = $this->makeMiddleware($mockSDK);

        $next = fn (Request $req) => new Response('OK', 200);
        $middleware->handle($request, $next);
    }
}
