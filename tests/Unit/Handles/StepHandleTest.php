<?php

namespace Smartness\TraceFlow\Tests\Unit\Handles;

use PHPUnit\Framework\TestCase;
use Smartness\TraceFlow\DTO\TraceEvent;
use Smartness\TraceFlow\Enums\TraceEventType;
use Smartness\TraceFlow\Handles\StepHandle;

class StepHandleTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeStep(
        string $stepId = 'step-xyz',
        string $traceId = 'trace-abc',
        array &$events = [],
    ): StepHandle {
        $sendEvent = function (TraceEvent $event) use (&$events): void {
            $events[] = $event;
        };

        return new StepHandle(
            stepId: $stepId,
            traceId: $traceId,
            source: 'test',
            sendEvent: $sendEvent,
        );
    }

    // -------------------------------------------------------------------------
    // finish()
    // -------------------------------------------------------------------------

    public function test_finish_sends_step_finished_event(): void
    {
        $events = [];
        $step = $this->makeStep(events: $events);

        $step->finish('output-value', ['m' => 1]);

        $this->assertCount(1, $events);
        $this->assertSame(TraceEventType::STEP_FINISHED, $events[0]->eventType);
        $this->assertSame('output-value', $events[0]->payload['output']);
        $this->assertSame(['m' => 1], $events[0]->payload['metadata']);
        $this->assertSame('step-xyz', $events[0]->stepId);
    }

    public function test_finish_is_idempotent(): void
    {
        $events = [];
        $step = $this->makeStep(events: $events);

        $step->finish();
        $step->finish();

        $this->assertCount(1, $events);
    }

    // -------------------------------------------------------------------------
    // fail()
    // -------------------------------------------------------------------------

    public function test_fail_with_string(): void
    {
        $events = [];
        $step = $this->makeStep(events: $events);

        $step->fail('error msg');

        $this->assertCount(1, $events);
        $this->assertSame(TraceEventType::STEP_FAILED, $events[0]->eventType);
        $this->assertSame('error msg', $events[0]->payload['error']);
    }

    public function test_fail_with_throwable_captures_stack(): void
    {
        $events = [];
        $step = $this->makeStep(events: $events);

        $exception = new \RuntimeException('something went wrong');
        $step->fail($exception);

        $this->assertCount(1, $events);
        $this->assertSame(TraceEventType::STEP_FAILED, $events[0]->eventType);
        $this->assertSame('something went wrong', $events[0]->payload['error']);
        $this->assertNotEmpty($events[0]->payload['stack']);
    }

    // -------------------------------------------------------------------------
    // isClosed()
    // -------------------------------------------------------------------------

    public function test_is_closed_initially_false(): void
    {
        $events = [];
        $step = $this->makeStep(events: $events);

        $this->assertFalse($step->isClosed());
    }

    public function test_is_closed_true_after_finish(): void
    {
        $events = [];
        $step = $this->makeStep(events: $events);

        $step->finish();

        $this->assertTrue($step->isClosed());
    }

    public function test_is_closed_true_after_fail(): void
    {
        $events = [];
        $step = $this->makeStep(events: $events);

        $step->fail('x');

        $this->assertTrue($step->isClosed());
    }

    // -------------------------------------------------------------------------
    // log()
    // -------------------------------------------------------------------------

    public function test_log_sends_log_emitted_with_step_id(): void
    {
        $events = [];
        $step = $this->makeStep(stepId: 'step-xyz', events: $events);

        $step->log('msg', 'DEBUG', null, null);

        $this->assertCount(1, $events);
        $this->assertSame(TraceEventType::LOG_EMITTED, $events[0]->eventType);
        $this->assertSame('step-xyz', $events[0]->stepId);
    }

    // -------------------------------------------------------------------------
    // Destructor
    // -------------------------------------------------------------------------

    public function test_destructor_auto_closes_unclosed_step(): void
    {
        $events = [];
        $sendEvent = function (TraceEvent $event) use (&$events): void {
            $events[] = $event;
        };

        $step = new StepHandle(
            stepId: 'step-auto',
            traceId: 'trace-abc',
            source: 'test',
            sendEvent: $sendEvent,
        );

        // Destroy without calling finish/fail
        unset($step);

        $this->assertNotEmpty($events);
        $this->assertSame(TraceEventType::STEP_FAILED, $events[0]->eventType);
    }

    public function test_destructor_skips_already_closed_step(): void
    {
        $events = [];
        $sendEvent = function (TraceEvent $event) use (&$events): void {
            $events[] = $event;
        };

        $step = new StepHandle(
            stepId: 'step-closed',
            traceId: 'trace-abc',
            source: 'test',
            sendEvent: $sendEvent,
        );

        $step->finish('done');
        $countAfterFinish = count($events);

        // Destructor must not fire a second event
        unset($step);

        $this->assertCount($countAfterFinish, $events);
    }
}
