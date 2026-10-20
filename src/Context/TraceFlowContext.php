<?php

namespace Smartness\TraceFlow\Context;

class TraceFlowContext
{
    private static ?string $traceId = null;

    private static ?string $stepId = null;

    private static array $metadata = [];

    public static function set(string $traceId, ?string $stepId = null, array $metadata = []): void
    {
        static::$traceId = $traceId;
        static::$stepId = $stepId;
        static::$metadata = $metadata;
    }

    public static function currentTraceId(): ?string
    {
        return static::$traceId;
    }

    public static function currentStepId(): ?string
    {
        return static::$stepId;
    }

    public static function metadata(): array
    {
        return static::$metadata;
    }

    public static function clear(): void
    {
        static::$traceId = null;
        static::$stepId = null;
        static::$metadata = [];
    }

    public static function hasActiveTrace(): bool
    {
        return static::$traceId !== null;
    }

    /**
     * Serialize context for queue job propagation.
     */
    public static function toArray(): array
    {
        return [
            'trace_id' => static::$traceId,
            'step_id' => static::$stepId,
            'metadata' => static::$metadata,
        ];
    }

    /**
     * Restore context from serialized data (e.g., from a queue job).
     */
    public static function restore(array $data): void
    {
        static::$traceId = $data['trace_id'] ?? null;
        static::$stepId = $data['step_id'] ?? null;
        static::$metadata = $data['metadata'] ?? [];
    }
}
