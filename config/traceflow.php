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

    // true (default): tracing calls never wait for the server; events are
    // sent after the response, after each queue job and at shutdown.
    // false: synchronous HTTP. Every tracing call BLOCKS until its request,
    // retries included, is done. Use only where that is acceptable (scripts).
    'async_http' => env('TRACEFLOW_ASYNC_HTTP', true),

    'api_key' => env('TRACEFLOW_API_KEY'),

    // Total timeout of one HTTP attempt (connect + response), in seconds.
    'timeout' => env('TRACEFLOW_TIMEOUT', 5.0),

    /*
    |--------------------------------------------------------------------------
    | Delivery (async HTTP transport)
    |--------------------------------------------------------------------------
    |
    | Tracing calls only queue the event in memory and return. Queued events
    | are sent when the app terminates (under PHP-FPM and Octane: after the
    | response has been sent), after each queue job, and at shutdown.
    |
    | flush_timeout: longest wait of one of those flushes, in seconds. Under
    | PHP-FPM it holds the worker (not the client) after every request, so
    | keep it short. Events not delivered by then are dropped at the end of
    | the request, or kept for the next flush in a queue worker.
    |
    | max_queue_size: events waiting for delivery; beyond it new events are
    | dropped and counted.
    |
    | concurrency: requests in flight at once. A trace's create is sent before
    | its steps and logs, a step's create before its update, and the trace's
    | final update after them.
    |
    */
    'flush_timeout' => env('TRACEFLOW_FLUSH_TIMEOUT', 2.0),

    'max_queue_size' => env('TRACEFLOW_MAX_QUEUE_SIZE', 10000),

    'concurrency' => env('TRACEFLOW_CONCURRENCY', 4),

    /*
    |--------------------------------------------------------------------------
    | Circuit Breaker
    |--------------------------------------------------------------------------
    |
    | After `threshold` consecutive failed attempts (network error, timeout,
    | 5xx, 429) no request is sent for `timeout_ms`, and trace events are
    | dropped meanwhile. Then one request probes the server: success closes
    | the breaker, failure re-opens it for another `timeout_ms`.
    |
    | The state is shared through the Laravel cache (`cache_store`, null =
    | default store), so the failures of all requests and workers add up: a
    | per-process breaker would never open under PHP-FPM. It is read about
    | once per request and written only on failures and state changes. If the
    | cache fails, the breaker falls back to per-process state. Set `shared`
    | to false to keep the state per process.
    |
    */
    'circuit_breaker' => [
        'threshold' => env('TRACEFLOW_CIRCUIT_BREAKER_THRESHOLD', 5),
        'timeout_ms' => env('TRACEFLOW_CIRCUIT_BREAKER_TIMEOUT_MS', 60000),
        'shared' => env('TRACEFLOW_CIRCUIT_BREAKER_SHARED', true),
        'cache_store' => env('TRACEFLOW_CIRCUIT_BREAKER_CACHE_STORE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retry Configuration
    |--------------------------------------------------------------------------
    */
    'max_retries' => env('TRACEFLOW_MAX_RETRIES', 3),

    // Initial retry delay in milliseconds, doubled per retry (with jitter, capped
    // at 30 s). Async transport: retries happen inside a flush and its time budget.
    'retry_delay' => env('TRACEFLOW_RETRY_DELAY', 1000),

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
