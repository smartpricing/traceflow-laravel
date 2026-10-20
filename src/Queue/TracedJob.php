<?php

namespace Smartness\TraceFlow\Queue;

use Smartness\TraceFlow\Context\TraceFlowContext;

trait TracedJob
{
    public ?array $traceFlowContext = null;

    public function initializeTracedJob(): void
    {
        if (TraceFlowContext::hasActiveTrace()) {
            $this->traceFlowContext = TraceFlowContext::toArray();
        }
    }

    /**
     * Get the middleware the job should pass through.
     *
     * If the job already defines middleware(), merge ours in by
     * overriding and calling parent::middleware() from the job class.
     */
    public function middleware(): array
    {
        return [new RestoreTraceContext];
    }
}
