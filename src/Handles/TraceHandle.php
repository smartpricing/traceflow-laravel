<?php

namespace Smartness\TraceFlow\Handles;

use Ramsey\Uuid\Uuid;
use Smartness\TraceFlow\DTO\TraceEvent;
use Smartness\TraceFlow\Enums\LogLevel;
use Smartness\TraceFlow\Enums\TraceEventType;

class TraceHandle
{
    private bool $closed = false;

    public function __construct(
        public readonly string $traceId,
        private string $source,
        private \Closure $sendEvent,
    ) {}

    public function finish(?array $result = null, ?array $metadata = null): void
    {
        if ($this->closed) {
            error_log("[TraceFlow] Trace {$this->traceId} already closed");

            return;
        }

        $this->closed = true;

        $event = new TraceEvent(
            eventId: Uuid::uuid4()->toString(),
            eventType: TraceEventType::TRACE_FINISHED,
            traceId: $this->traceId,
            timestamp: now()->toIso8601String(),
            source: $this->source,
            payload: array_filter([
                'result' => $result,
                'metadata' => $metadata,
            ]),
        );

        ($this->sendEvent)($event);
    }

    public function fail(string|\Throwable $error): void
    {
        if ($this->closed) {
            error_log("[TraceFlow] Trace {$this->traceId} already closed");

            return;
        }

        $this->closed = true;

        $errorMessage = $error instanceof \Throwable ? $error->getMessage() : $error;
        $errorStack = $error instanceof \Throwable ? $error->getTraceAsString() : null;

        $event = new TraceEvent(
            eventId: Uuid::uuid4()->toString(),
            eventType: TraceEventType::TRACE_FAILED,
            traceId: $this->traceId,
            timestamp: now()->toIso8601String(),
            source: $this->source,
            payload: array_filter([
                'error' => $errorMessage,
                'stack' => $errorStack,
            ]),
        );

        ($this->sendEvent)($event);
    }

    public function cancel(): void
    {
        if ($this->closed) {
            error_log("[TraceFlow] Trace {$this->traceId} already closed");

            return;
        }

        $this->closed = true;

        $event = new TraceEvent(
            eventId: Uuid::uuid4()->toString(),
            eventType: TraceEventType::TRACE_CANCELLED,
            traceId: $this->traceId,
            timestamp: now()->toIso8601String(),
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
            timestamp: now()->toIso8601String(),
            source: $this->source,
            payload: array_filter([
                'name' => $name,
                'step_type' => $stepType,
                'input' => $input,
                'metadata' => $metadata,
            ]),
            stepId: $stepId,
        );

        ($this->sendEvent)($event);

        return new StepHandle(
            stepId: $stepId,
            traceId: $this->traceId,
            source: $this->source,
            sendEvent: $this->sendEvent,
        );
    }

    public function log(string $message, LogLevel|string $level = LogLevel::INFO, ?string $eventType = null, mixed $details = null): void
    {
        $event = new TraceEvent(
            eventId: Uuid::uuid4()->toString(),
            eventType: TraceEventType::LOG_EMITTED,
            traceId: $this->traceId,
            timestamp: now()->toIso8601String(),
            source: $this->source,
            payload: [
                'message' => $message,
                'level' => $level instanceof LogLevel ? $level->value : $level,
                'event_type' => $eventType,
                'details' => $details,
            ],
        );

        ($this->sendEvent)($event);
    }
}
