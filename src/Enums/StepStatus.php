<?php

namespace Smartness\TraceFlow\Enums;

enum StepStatus: string
{
    case STARTED = 'STARTED';
    case IN_PROGRESS = 'IN_PROGRESS';
    case COMPLETED = 'COMPLETED';
    case FAILED = 'FAILED';
}
