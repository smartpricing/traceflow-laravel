<?php

namespace Smartness\TraceFlow\Enums;

enum TraceStatus: string
{
    case PENDING = 'PENDING';
    case RUNNING = 'RUNNING';
    case SUCCESS = 'SUCCESS';
    case FAILED = 'FAILED';
    case CANCELLED = 'CANCELLED';
}
