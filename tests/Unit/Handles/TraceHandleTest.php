<?php

namespace Smartness\TraceFlow\Tests\Unit\Handles;

use PHPUnit\Framework\TestCase;
use Smartness\TraceFlow\Context\TraceFlowContext;
use Smartness\TraceFlow\DTO\TraceEvent;
use Smartness\TraceFlow\Enums\TraceEventType;
use Smartness\TraceFlow\Handles\StepHandle;
use Smartness\TraceFlow\Handles\TraceHandle;

class TraceHandleTest extends TestCase
{
    protected function tearDown(): void
    {
        TraceFlowContext::clear();
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeHandle(
        string $traceId = 'trace-abc',
        bool $ownsLifecycle = true,
        ?\Closure $onClose = null,
        array &$events = [],
    ): TraceHandle {
        $sendEvent = function (TraceEvent $event) use (&$events): void {
            $events[] = $event;
        };

        return new TraceHandle(
            traceId: $traceId,
            source: 'test',
            sendEvent: $sendEvent,
            ownsLifecycle: $ownsLifecycle,
            onClose: $onClose,
        );
    }

    // -------------------------------------------------------------------------
    // finish()
    // -------------------------------------------------------------------------

    public function test_finish_sends_trace_finished_event(): void
    {
        $events = [];
        $handle = $this->makeHandle(events: $events);

        $handle->finish('my-result', ['key' => 'val']);

        $this->assertCount(1, $events);
        $this->assertSame(TraceEventType::TRACE_FINISHED, $events[0]->eventType);
        $this->assertSame('my-result', $events[0]->payload['result']);
        $this->assertSame(['key' => 'val'], $events[0]->payload['metadata']);
    }

    public function test_finish_is_idempotent(): void
    {
        $events = [];
        $handle = $this->makeHandle(events: $events);

        $handle->finish();
        $handle->finish();

        $this->assertCount(1, $events);
    }

    // -------------------------------------------------------------------------
    // fail()
    // -------------------------------------------------------------------------

    public function test_fail_with_string_sends_error_in_payload(): void
    {
        $events = [];
        $handle = $this->makeHandle(events: $events);

        $handle->fail('Something broke');

        $this->assertCount(1, $events);
        $this->assertSame(TraceEventType::TRACE_FAILED, $events[0]->eventType);
        $this->assertSame('Something broke', $events[0]->payload['error']);
    }

    public function test_fail_with_throwable_captures_stack(): void
    {
        $events = [];
        $handle = $this->makeHandle(events: $events);

        $exception = new \RuntimeException('Throwable error');
        $handle->fail($exception);

        $this->assertCount(1, $events);
        $this->assertSame(TraceEventType::TRACE_FAILED, $events[0]->eventType);
        $this->assertSame('Throwable error', $events[0]->payload['error']);
        $this->assertNotEmpty($events[0]->payload['stack']);
    }

    // -------------------------------------------------------------------------
    // cancel()
    // -------------------------------------------------------------------------

    public function test_cancel_sends_trace_cancelled(): void
    {
        $events = [];
        $handle = $this->makeHandle(events: $events);

        $handle->cancel();

        $this->assertCount(1, $events);
        $this->assertSame(TraceEventType::TRACE_CANCELLED, $events[0]->eventType);
    }

    public function test_cancel_is_idempotent(): void
    {
        $events = [];
        $handle = $this->makeHandle(events: $events);

        $handle->cancel();
        $handle->cancel();

        $this->assertCount(1, $events);
    }

    // -------------------------------------------------------------------------
    // isClosed()
    // -------------------------------------------------------------------------

    public function test_is_closed_reflects_state(): void
    {
        $events = [];
        $handle = $this->makeHandle(events: $events);

        $this->assertFalse($handle->isClosed());

        $handle->finish();

        $this->assertTrue($handle->isClosed());
    }

    public function test_is_closed_true_after_fail(): void
    {
        $events = [];
        $handle = $this->makeHandle(events: $events);

        $handle->fail('x');

        $this->assertTrue($handle->isClosed());
    }

    public function test_is_closed_true_after_cancel(): void
    {
        $events = [];
        $handle = $this->makeHandle(events: $events);

        $handle->cancel();

        $this->assertTrue($handle->isClosed());
    }

    // -------------------------------------------------------------------------
    // startStep()
    // -------------------------------------------------------------------------

    public function test_start_step_sends_step_started_and_returns_handle(): void
    {
        $events = [];
        $handle = $this->makeHandle(events: $events);

        $step = $handle->startStep('my step', 'db', ['q' => 1], ['m' => 2]);

        // One STEP_STARTED event must have been sent
        $this->assertCount(1, $events);
        $this->assertSame(TraceEventType::STEP_STARTED, $events[0]->eventType);
        $this->assertSame('my step', $events[0]->payload['name']);
        $this->assertSame('db', $events[0]->payload['step_type']);
        $this->assertSame(['q' => 1], $events[0]->payload['input']);
        $this->assertSame(['m' => 2], $events[0]->payload['metadata']);

        // Returned handle must be a StepHandle for the same trace
        $this->assertInstanceOf(StepHandle::class, $step);
        $this->assertSame('trace-abc', $step->traceId);
    }

    // -------------------------------------------------------------------------
    // withStep()
    // -------------------------------------------------------------------------

    public function test_with_step_closes_step_on_success(): void
    {
        $events = [];
        $handle = $this->makeHandle(events: $events);

        $capturedStep = null;
        $result = $handle->withStep(function (StepHandle $step) use (&$capturedStep): string {
            $capturedStep = $step;

            return 'done';
        });

        $this->assertNotNull($capturedStep);
        $this->assertTrue($capturedStep->isClosed());
        $this->assertSame('done', $result);
    }

    public function test_with_step_closes_step_on_exception(): void
    {
        $events = [];
        $handle = $this->makeHandle(events: $events);

        $capturedStep = null;

        try {
            $handle->withStep(function (StepHandle $step) use (&$capturedStep): void {
                $capturedStep = $step;
                throw new \Exception('boom');
            });
        } catch (\Exception) {
            // expected
        }

        $this->assertNotNull($capturedStep);
        $this->assertTrue($capturedStep->isClosed());
    }

    public function test_with_step_rethrows_exception(): void
    {
        $events = [];
        $handle = $this->makeHandle(events: $events);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('propagated');

        $handle->withStep(function (): void {
            throw new \RuntimeException('propagated');
        });
    }

    // -------------------------------------------------------------------------
    // Orphan step cleanup
    // -------------------------------------------------------------------------

    public function test_orphaned_steps_closed_when_trace_finishes(): void
    {
        $events = [];
        $handle = $this->makeHandle(events: $events);

        $step = $handle->startStep('orphan');

        // Do NOT close the step; close the trace
        $handle->finish();

        $this->assertTrue($step->isClosed());
    }

    public function test_orphaned_steps_closed_when_trace_fails(): void
    {
        $events = [];
        $handle = $this->makeHandle(events: $events);

        $step = $handle->startStep('orphan');

        $handle->fail('trace error');

        $this->assertTrue($step->isClosed());
    }

    public function test_orphaned_steps_closed_when_trace_cancelled(): void
    {
        $events = [];
        $handle = $this->makeHandle(events: $events);

        $step = $handle->startStep('orphan');

        $handle->cancel();

        $this->assertTrue($step->isClosed());
    }

    public function test_already_closed_steps_skipped_in_orphan_cleanup(): void
    {
        $events = [];
        $handle = $this->makeHandle(events: $events);

        $step = $handle->startStep('my-step');
        $step->finish('done');

        // Events so far: 1 STEP_STARTED + 1 STEP_FINISHED = 2
        $countBeforeClose = count($events);

        $handle->finish();

        // Closing the trace must add exactly 1 more event (TRACE_FINISHED)
        // and must NOT add a second STEP_FAILED for the already-closed step
        $this->assertCount($countBeforeClose + 1, $events);
        $this->assertSame(TraceEventType::TRACE_FINISHED, $events[array_key_last($events)]->eventType);
    }

    // -------------------------------------------------------------------------
    // onClose callback
    // -------------------------------------------------------------------------

    public function test_on_close_callback_invoked(): void
    {
        $events = [];
        $called = false;
        $onClose = function () use (&$called): void {
            $called = true;
        };

        $handle = $this->makeHandle(onClose: $onClose, events: $events);
        $handle->finish();

        $this->assertTrue($called);
    }

    // -------------------------------------------------------------------------
    // TraceFlowContext interaction
    // -------------------------------------------------------------------------

    public function test_notifies_closed_clears_context_when_matching(): void
    {
        $events = [];
        TraceFlowContext::set('trace-abc');

        $handle = $this->makeHandle(traceId: 'trace-abc', events: $events);
        $handle->finish();

        $this->assertNull(TraceFlowContext::currentTraceId());
    }

    public function test_notifies_closed_does_not_clear_different_trace_context(): void
    {
        $events = [];
        TraceFlowContext::set('other-trace');

        $handle = $this->makeHandle(traceId: 'trace-abc', events: $events);
        $handle->finish();

        $this->assertSame('other-trace', TraceFlowContext::currentTraceId());
    }

    // -------------------------------------------------------------------------
    // Destructor / ownsLifecycle
    // -------------------------------------------------------------------------

    public function test_destructor_auto_closes_when_owns_lifecycle(): void
    {
        $events = [];
        $sendEvent = function (TraceEvent $event) use (&$events): void {
            $events[] = $event;
        };

        $handle = new TraceHandle(
            traceId: 'trace-auto',
            source: 'test',
            sendEvent: $sendEvent,
            ownsLifecycle: true,
            onClose: null,
        );

        // Destroy without calling finish/fail
        unset($handle);

        $this->assertNotEmpty($events);

        $lastEvent = $events[array_key_last($events)];
        $this->assertSame(TraceEventType::TRACE_FAILED, $lastEvent->eventType);
        $this->assertStringContainsString('auto-closed', $lastEvent->payload['error']);
    }

    public function test_destructor_skips_when_not_owns_lifecycle(): void
    {
        $events = [];
        $sendEvent = function (TraceEvent $event) use (&$events): void {
            $events[] = $event;
        };

        $handle = new TraceHandle(
            traceId: 'trace-no-lifecycle',
            source: 'test',
            sendEvent: $sendEvent,
            ownsLifecycle: false,
            onClose: null,
        );

        unset($handle);

        $this->assertEmpty($events);
    }

    // -------------------------------------------------------------------------
    // log()
    // -------------------------------------------------------------------------

    public function test_log_sends_log_emitted_event(): void
    {
        $events = [];
        $handle = $this->makeHandle(events: $events);

        $handle->log('hello', 'WARN', 'my_type', ['d' => 1]);

        $this->assertCount(1, $events);
        $this->assertSame(TraceEventType::LOG_EMITTED, $events[0]->eventType);
        $this->assertSame('hello', $events[0]->payload['message']);
        $this->assertSame('WARN', $events[0]->payload['level']);
        $this->assertSame('my_type', $events[0]->payload['event_type']);
        $this->assertSame(['d' => 1], $events[0]->payload['details']);
    }
}
