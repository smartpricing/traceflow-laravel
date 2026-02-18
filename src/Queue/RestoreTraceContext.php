<?php

namespace Smartness\TraceFlow\Queue;

use Closure;
use Smartness\TraceFlow\Context\TraceFlowContext;
use Smartness\TraceFlow\TraceFlowSDK;

class RestoreTraceContext
{
    public function handle(object $job, Closure $next): void
    {
        if (isset($job->traceFlowContext) && is_array($job->traceFlowContext)) {
            TraceFlowContext::restore($job->traceFlowContext);

            // Also sync the SDK singleton so getCurrentTrace() works
            try {
                $sdk = app(TraceFlowSDK::class);
                $sdk->setCurrentTraceId($job->traceFlowContext['trace_id']);
            } catch (\Throwable $e) {
                // SDK may not be bound in tests or edge cases
            }
        }

        try {
            $next($job);
        } finally {
            TraceFlowContext::clear();
        }
    }
}
