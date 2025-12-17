<?php

namespace Smartpricing\TraceFlow\DTO;

use Smartpricing\TraceFlow\Enums\TraceEventType;

class TraceEvent
{
    public function __construct(
        public readonly string $eventId,
        public readonly TraceEventType $eventType,
        public readonly string $traceId,
        public readonly string $timestamp,
        public readonly string $source,
        public readonly array $payload,
        public readonly ?string $parentTraceId = null,
        public readonly ?string $stepId = null,
    ) {
    }

    public function toArray(): array
    {
        return [
            'event_id' => $this->eventId,
            'event_type' => $this->eventType->value,
            'trace_id' => $this->traceId,
            'timestamp' => $this->timestamp,
            'source' => $this->source,
            'payload' => $this->payload,
            'parent_trace_id' => $this->parentTraceId,
            'step_id' => $this->stepId,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray());
    }
}

