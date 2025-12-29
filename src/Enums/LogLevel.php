<?php

namespace Smartness\TraceFlow\Enums;

enum LogLevel: string
{
    case DEBUG = 'DEBUG';
    case INFO = 'INFO';
    case WARN = 'WARN';
    case ERROR = 'ERROR';
    case FATAL = 'FATAL';
}
