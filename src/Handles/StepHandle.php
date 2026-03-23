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

    public function __destruct()
    {
        if (! $this->closed) {
            try {
                $this->fail('Step not explicitly closed (auto-closed by destructor)');
            } catch (\Throwable) {}
        }
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

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
            timestamp: now('UTC')->format('Y-m-d\TH:i:s.v\Z'),
            source: $this->source,
            payload: array_filter([
                'output' => $output,
                'metadata' => $metadata,
            ], fn ($value) => $value !== null),
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
            timestamp: now('UTC')->format('Y-m-d\TH:i:s.v\Z'),
            source: $this->source,
            payload: array_filter([
                'error' => $errorMessage,
                'stack' => $errorStack,
            ], fn ($value) => $value !== null),
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
            timestamp: now('UTC')->format('Y-m-d\TH:i:s.v\Z'),
            source: $this->source,
            payload: array_filter([
                'message' => $message,
                'level' => $level instanceof LogLevel ? $level->value : $level,
                'event_type' => $eventType,
                'details' => $details,
            ], fn ($value) => $value !== null),
            stepId: $this->stepId,
        );

        ($this->sendEvent)($event);
    }
}
