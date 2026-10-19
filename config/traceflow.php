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

    /*
    |--------------------------------------------------------------------------
    | Audit Delivery
    |--------------------------------------------------------------------------
    |
    | Audit events are pushed to a Queen MQ queue that the Traceflow worker
    | reads (AUDIT_QUEEN_ENABLED on the service). Requires queen-mq/php-client.
    |
    | A model write inside a transaction publishes after the commit, and a
    | rolled-back write publishes nothing. A push that fails after the write
    | has committed is reported to the exception handler: that event is lost.
    | The URL and token default to the application's QUEEN_URL and
    | QUEEN_BEARER_TOKEN. A push waits at most timeout_ms per attempt. After a
    | failure, pushes from the same process fail at once for cooldown_seconds
    | (0 disables it), so an outage does not slow every request.
    |
    */
    'audit' => [
        'queen' => [
            'url' => env('TRACEFLOW_AUDIT_QUEEN_URL', env('QUEEN_URL')),
            'bearer_token' => env('TRACEFLOW_AUDIT_QUEEN_BEARER_TOKEN', env('QUEEN_BEARER_TOKEN')),
            'queue' => env('TRACEFLOW_AUDIT_QUEEN_QUEUE', 'traceflow.audit'),
            'timeout_ms' => env('TRACEFLOW_AUDIT_QUEEN_TIMEOUT_MS', 2000),
            'retry_attempts' => env('TRACEFLOW_AUDIT_QUEEN_RETRY_ATTEMPTS', 1),
            'cooldown_seconds' => env('TRACEFLOW_AUDIT_QUEEN_COOLDOWN_SECONDS', 30),
        ],
    ],
];
