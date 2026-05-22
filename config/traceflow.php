<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Master Enable / Disable
    |--------------------------------------------------------------------------
    |
    | When false, the SDK is wired with a no-op transport. All public methods
    | keep working (so call sites need no `if (enabled)` guards) but events are
    | dropped silently — no HTTP traffic and no AsyncHttpTransport circuit
    | breaker `error_log()` noise. Useful in local development when the
    | TraceFlow collector is not running.
    |
    */
    'enabled' => env('TRACEFLOW_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Transport Configuration
    |--------------------------------------------------------------------------
    |
    | Choose between 'http', 'log', or 'kafka' transport.
    | 'log' writes all trace events to the Laravel log file.
    |
    */
    'transport' => env('TRACEFLOW_TRANSPORT', 'http'),

    /*
    |--------------------------------------------------------------------------
    | Service Source
    |--------------------------------------------------------------------------
    |
    | Identifier for this service
    |
    */
    'source' => env('TRACEFLOW_SOURCE', env('APP_NAME', 'laravel-app')),

    /*
    |--------------------------------------------------------------------------
    | HTTP Transport Options
    |--------------------------------------------------------------------------
    */
    'endpoint' => env('TRACEFLOW_URL', 'http://localhost:3009'),

    // Use async HTTP (non-blocking) for better performance
    // Set to false to use synchronous HTTP (blocking)
    'async_http' => env('TRACEFLOW_ASYNC_HTTP', true),

    'api_key' => env('TRACEFLOW_API_KEY'),

    'timeout' => env('TRACEFLOW_TIMEOUT', 5.0),

    /*
    |--------------------------------------------------------------------------
    | Retry Configuration
    |--------------------------------------------------------------------------
    */
    'max_retries' => env('TRACEFLOW_MAX_RETRIES', 3),

    'retry_delay' => env('TRACEFLOW_RETRY_DELAY', 1000), // milliseconds

    /*
    |--------------------------------------------------------------------------
    | Behavior Options
    |--------------------------------------------------------------------------
    */
    'silent_errors' => env('TRACEFLOW_SILENT_ERRORS', true),

    /*
    |--------------------------------------------------------------------------
    | Log Transport Options
    |--------------------------------------------------------------------------
    |
    | Used when transport is set to 'log'.
    | Events are written to the specified Laravel log channel.
    |
    */
    'log_channel' => env('TRACEFLOW_LOG_CHANNEL', env('LOG_CHANNEL', 'stack')),

    'log_level' => env('TRACEFLOW_LOG_LEVEL', 'info'),

    /*
    |--------------------------------------------------------------------------
    | Middleware Configuration
    |--------------------------------------------------------------------------
    */
    'middleware' => [
        'enabled' => env('TRACEFLOW_MIDDLEWARE_ENABLED', true),
        'header_name' => 'X-Trace-Id',
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    |
    | Controls trace context propagation through queue jobs.
    | Jobs using the TracedJob trait will automatically capture and restore
    | the active trace context.
    |
    */
    'queue' => [
        'propagate_context' => env('TRACEFLOW_QUEUE_PROPAGATE', true),
    ],
];
