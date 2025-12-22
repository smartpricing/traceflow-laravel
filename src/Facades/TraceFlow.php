<?php

namespace Smartpricing\TraceFlow\Facades;

use Illuminate\Support\Facades\Facade;
use Smartpricing\TraceFlow\Handles\TraceHandle;
use Smartpricing\TraceFlow\Handles\StepHandle;

/**
 * @method static TraceHandle startTrace(?string $traceId = null, ?string $traceType = null, ?string $title = null, ?string $description = null, ?string $owner = null, ?array $tags = null, ?array $metadata = null, mixed $params = null, ?int $traceTimeoutMs = null, ?int $stepTimeoutMs = null)
 * @method static TraceHandle getTrace(string $traceId)
 * @method static TraceHandle|null getCurrentTrace()
 * @method static mixed runWithTrace(callable $callback, array $traceOptions = [])
 * @method static void heartbeat(?string $traceId = null)
 * @method static StepHandle|null startStep(?string $name = null, ?string $stepType = null, mixed $input = null, ?array $metadata = null)
 * @method static void log(string $message, string $level = 'INFO', ?string $eventType = null, mixed $details = null)
 * @method static void flush()
 * @method static void shutdown()
 *
 * @see \Smartpricing\TraceFlow\TraceFlowSDK
 */
class TraceFlow extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'traceflow';
    }
}

