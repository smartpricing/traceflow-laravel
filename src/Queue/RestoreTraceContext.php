<?php

namespace Smartness\TraceFlow\Queue;

use Closure;
use Smartness\TraceFlow\Context\TraceFlowContext;

class RestoreTraceContext
{
    public function handle(object $job, Closure $next): void
    {
        if (isset($job->traceFlowContext) && is_array($job->traceFlowContext)) {
            TraceFlowContext::restore($job->traceFlowContext);
        }

        try {
            $next($job);
        } finally {
            TraceFlowContext::clear();
        }
    }
}
