<?php

namespace Smartness\TraceFlow\Tests\Unit\Queue;

use PHPUnit\Framework\TestCase;
use Smartness\TraceFlow\Context\TraceFlowContext;
use Smartness\TraceFlow\Queue\RestoreTraceContext;
use Smartness\TraceFlow\Queue\TracedJob;

class TracedJobTest extends TestCase
{
    protected function tearDown(): void
    {
        TraceFlowContext::clear();
        parent::tearDown();
    }

    public function test_trait_captures_active_trace_context(): void
    {
        TraceFlowContext::set('trace-capture', 'step-capture', ['key' => 'value']);

        $job = new FakeTracedJob;
        $job->initializeTracedJob();

        $this->assertNotNull($job->traceFlowContext);
        $this->assertEquals('trace-capture', $job->traceFlowContext['trace_id']);
        $this->assertEquals('step-capture', $job->traceFlowContext['step_id']);
        $this->assertEquals(['key' => 'value'], $job->traceFlowContext['metadata']);
    }

    public function test_trait_does_not_capture_when_no_active_trace(): void
    {
        $job = new FakeTracedJob;
        $job->initializeTracedJob();

        $this->assertNull($job->traceFlowContext);
    }

    public function test_trait_provides_restore_middleware(): void
    {
        $job = new FakeTracedJob;
        $middleware = $job->middleware();

        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(RestoreTraceContext::class, $middleware[0]);
    }

    public function test_restore_middleware_restores_context(): void
    {
        $job = new FakeTracedJob;
        $job->traceFlowContext = [
            'trace_id' => 'trace-queue',
            'step_id' => 'step-queue',
            'metadata' => ['from' => 'queue'],
        ];

        $middleware = new RestoreTraceContext;
        $capturedTraceId = null;

        $middleware->handle($job, function () use (&$capturedTraceId) {
            $capturedTraceId = TraceFlowContext::currentTraceId();
        });

        // Context was available during execution
        $this->assertEquals('trace-queue', $capturedTraceId);

        // Context is cleared after execution
        $this->assertFalse(TraceFlowContext::hasActiveTrace());
    }

    public function test_restore_middleware_clears_context_on_exception(): void
    {
        $job = new FakeTracedJob;
        $job->traceFlowContext = [
            'trace_id' => 'trace-fail',
            'step_id' => null,
            'metadata' => [],
        ];

        $middleware = new RestoreTraceContext;

        try {
            $middleware->handle($job, function () {
                throw new \RuntimeException('Job failed');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertFalse(TraceFlowContext::hasActiveTrace());
    }

    public function test_restore_middleware_skips_when_no_context(): void
    {
        $job = new FakeTracedJob;

        $middleware = new RestoreTraceContext;
        $executed = false;

        $middleware->handle($job, function () use (&$executed) {
            $executed = true;
            $this->assertFalse(TraceFlowContext::hasActiveTrace());
        });

        $this->assertTrue($executed);
    }

    public function test_chained_job_dispatch_propagates_context(): void
    {
        // Simulate: HTTP request sets context -> Job A captures it -> Job A restores it -> Job B captures it
        TraceFlowContext::set('trace-chain', 'step-chain', ['depth' => 0]);

        // Job A captures context at dispatch time
        $jobA = new FakeTracedJob;
        $jobA->initializeTracedJob();

        // Clear context (simulates queue serialization boundary)
        TraceFlowContext::clear();
        $this->assertFalse(TraceFlowContext::hasActiveTrace());

        // Queue worker picks up Job A, middleware restores context
        $middleware = new RestoreTraceContext;
        $jobBContext = null;

        $middleware->handle($jobA, function () use (&$jobBContext) {
            // Inside Job A's handle(), context is restored
            $this->assertEquals('trace-chain', TraceFlowContext::currentTraceId());

            // Job A dispatches Job B — trait captures the restored context
            $jobB = new FakeTracedJob;
            $jobB->initializeTracedJob();

            $jobBContext = $jobB->traceFlowContext;
        });

        // Job B captured the same trace context
        $this->assertNotNull($jobBContext);
        $this->assertEquals('trace-chain', $jobBContext['trace_id']);
    }
}

/**
 * Fake job class that uses the TracedJob trait for testing.
 */
class FakeTracedJob
{
    use TracedJob;

    public function handle(): void
    {
        // no-op
    }
}
