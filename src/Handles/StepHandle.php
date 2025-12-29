<?php

namespace Smartness\TraceFlow\Handles;

use Ramsey\Uuid\Uuid;
use Smartness\TraceFlow\DTO\TraceEvent;
use Smartness\TraceFlow\Enums\LogLevel;
use Smartness\TraceFlow\Enums\TraceEventType;

class StepHandle
{
    private bool $closed = false;

    public function __construct(
        public readonly string $stepId,
        public readonly string $traceId,
        private string $source,
        private \Closure $sendEvent,
    ) {}

    public function finish(mixed $output = null, ?array $metadata = null): void
    {
        if ($this->closed) {
            error_log("[TraceFlow] Step {$this->stepId} already closed");

            return;
        }

        $this->closed = true;

        $event = new TraceEvent(
            eventId: Uuid::uuid4()->toString(),
            eventType: TraceEventType::STEP_FINISHED,
            traceId: $this->traceId,
            timestamp: now()->toIso8601String(),
            source: $this->source,
            payload: array_filter([
                'output' => $output,
                'metadata' => $metadata,
            ]),
            stepId: $this->stepId,
        );

        ($this->sendEvent)($event);
    }

    public function fail(string|\Throwable $error): void
    {
        if ($this->closed) {
            error_log("[TraceFlow] Step {$this->stepId} already closed");

            return;
        }

        $this->closed = true;

        $errorMessage = $error instanceof \Throwable ? $error->getMessage() : $error;
        $errorStack = $error instanceof \Throwable ? $error->getTraceAsString() : null;

        $event = new TraceEvent(
            eventId: Uuid::uuid4()->toString(),
            eventType: TraceEventType::STEP_FAILED,
            traceId: $this->traceId,
            timestamp: now()->toIso8601String(),
            source: $this->source,
            payload: array_filter([
                'error' => $errorMessage,
                'stack' => $errorStack,
            ]),
            stepId: $this->stepId,
        );

        ($this->sendEvent)($event);
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
            stepId: $this->stepId,
        );

        ($this->sendEvent)($event);
    }
}
