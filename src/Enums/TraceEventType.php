<?php

namespace Smartpricing\TraceFlow\Enums;

enum TraceEventType: string
{
    case TRACE_STARTED = 'trace_started';
    case TRACE_FINISHED = 'trace_finished';
    case TRACE_FAILED = 'trace_failed';
    case TRACE_CANCELLED = 'trace_cancelled';
    case STEP_STARTED = 'step_started';
    case STEP_FINISHED = 'step_finished';
    case STEP_FAILED = 'step_failed';
    case LOG_EMITTED = 'log_emitted';
}

