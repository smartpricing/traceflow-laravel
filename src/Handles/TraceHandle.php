<?php

namespace Smartness\TraceFlow\Handles;

use Ramsey\Uuid\Uuid;
use Smartness\TraceFlow\Context\TraceFlowContext;
use Smartness\TraceFlow\DTO\TraceEvent;
use Smartness\TraceFlow\Enums\LogLevel;
use Smartness\TraceFlow\Enums\TraceEventType;

class TraceHandle
{
    private bool $closed = false;

    /** @var StepHandle[] */
    private array $steps = [];

    public function __construct(
        public readonly string $traceId,
        private string $source,
        private \Closure $sendEvent,
        private bool $ownsLifecycle = false,
        private ?\Closure $onClose = null,
        private ?\Closure $flushEvents = null,
    ) {}

    public function __destruct()
    {
        if ($this->ownsLifecycle && ! $this->closed) {
            try {
                $this->fail('Trace not explicitly closed (auto-closed by destructor)');
            } catch (\Throwable) {}
        }
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function finish(mixed $result = null, ?array $metadata = null): void
    {
        if ($this->closed) {
            error_log("[TraceFlow] Trace {$this->traceId} already closed");

            return;
        }

        $this->closeOrphanedSteps('Parent trace finished');
        $this->flushPendingEvents();

        $this->closed = true;
        $this->notifyClosed();

        $event = new TraceEvent(
            eventId: Uuid::uuid4()->toString(),
            eventType: TraceEventType::TRACE_FINISHED,
            traceId: $this->traceId,
            timestamp: now('UTC')->format('Y-m-d\TH:i:s.v\Z'),
            source: $this->source,
            payload: array_filter([
                'result' => $result,
                'metadata' => $metadata,
            ], fn ($value) => $value !== null),
        );

        ($this->sendEvent)($event);
    }

    public function fail(string|\Throwable $error): void
    {
        if ($this->closed) {
            error_log("[TraceFlow] Trace {$this->traceId} already closed");

            return;
        }

        $errorMessage = $error instanceof \Throwable ? $error->getMessage() : $error;

        $this->closeOrphanedSteps($errorMessage);
        $this->flushPendingEvents();

        $this->closed = true;
        $this->notifyClosed();

        $errorStack = $error instanceof \Throwable ? $error->getTraceAsString() : null;

        $event = new TraceEvent(
            eventId: Uuid::uuid4()->toString(),
            eventType: TraceEventType::TRACE_FAILED,
            traceId: $this->traceId,
            timestamp: now('UTC')->format('Y-m-d\TH:i:s.v\Z'),
            source: $this->source,
            payload: array_filter([
                'error' => $errorMessage,
                'stack' => $errorStack,
            ], fn ($value) => $value !== null),
        );

        ($this->sendEvent)($event);
    }

    public function cancel(): void
    {
        if ($this->closed) {
            error_log("[TraceFlow] Trace {$this->traceId} already closed");

            return;
        }

        $this->closeOrphanedSteps('Parent trace cancelled');
        $this->flushPendingEvents();

        $this->closed = true;
        $this->notifyClosed();

        $event = new TraceEvent(
            eventId: Uuid::uuid4()->toString(),
            eventType: TraceEventType::TRACE_CANCELLED,
            traceId: $this->traceId,
            timestamp: now('UTC')->format('Y-m-d\TH:i:s.v\Z'),
            source: $this->source,
            payload: [],
        );

        ($this->sendEvent)($event);
    }

    public function startStep(?string $name = null, ?string $stepType = null, mixed $input = null, ?array $metadata = null): StepHandle
    {
        $stepId = Uuid::uuid4()->toString();

        $event = new TraceEvent(
            eventId: Uuid::uuid4()->toString(),
            eventType: TraceEventType::STEP_STARTED,
            traceId: $this->traceId,
            timestamp: now('UTC')->format('Y-m-d\TH:i:s.v\Z'),
            source: $this->source,
            payload: array_filter([
                'name' => $name,
                'step_type' => $stepType,
                'input' => $input,
                'metadata' => $metadata,
            ], fn ($value) => $value !== null),
            stepId: $stepId,
        );

        ($this->sendEvent)($event);

        $step = new StepHandle(
            stepId: $stepId,
            traceId: $this->traceId,
            source: $this->source,
            sendEvent: $this->sendEvent,
        );

        $this->steps[] = $step;

        return $step;
    }

    /**
     * Execute a callback within a step, guaranteeing the step is closed.
     */
    public function withStep(
        callable $fn,
        ?string $name = null,
        ?string $stepType = null,
        mixed $input = null,
        ?array $metadata = null,
    ): mixed {
        $step = $this->startStep($name, $stepType, $input, $metadata);

        try {
            $result = $fn($step);
            $step->finish($result);

            return $result;
        } catch (\Throwable $e) {
            $step->fail($e);
            throw $e;
        }
    }

    public function log(string $message, LogLevel|string $level = LogLevel::INFO, ?string $eventType = null, mixed $details = null): void
    {
        $event = new TraceEvent(
            eventId: Uuid::uuid4()->toString(),
            eventType: TraceEventType::LOG_EMITTED,
            traceId: $this->traceId,
            timestamp: now('UTC')->format('Y-m-d\TH:i:s.v\Z'),
            source: $this->source,
            payload: array_filter([
                'message' => $message,
                'level' => $level instanceof LogLevel ? $level->value : $level,
                'event_type' => $eventType,
                'details' => $details,
            ], fn ($value) => $value !== null),
        );

        ($this->sendEvent)($event);
    }

    private function notifyClosed(): void
    {
        if ($this->onClose !== null) {
            ($this->onClose)();
        }

        // Clear static context if this trace is still the active one
        if (TraceFlowContext::currentTraceId() === $this->traceId) {
            TraceFlowContext::clear();
        }
    }

    /**
     * Flush all pending async events so that step closure events reach the
     * backend before the trace completion event is dispatched.
     */
    private function flushPendingEvents(): void
    {
        if ($this->flushEvents !== null) {
            try {
                ($this->flushEvents)();
            } catch (\Throwable) {}
        }
    }

    private function closeOrphanedSteps(string $reason): void
    {
        foreach ($this->steps as $step) {
            if (! $step->isClosed()) {
                try {
                    $step->fail($reason);
                } catch (\Throwable) {}
            }
        }

        $this->steps = [];
    }
}
