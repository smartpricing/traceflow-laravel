<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Transport Configuration
    |--------------------------------------------------------------------------
    |
    | Choose between 'http' or 'kafka' transport
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
    'endpoint' => env('TRACEFLOW_ENDPOINT', 'http://localhost:3009'),

    // Use async HTTP (non-blocking) for better performance
    // Set to false to use synchronous HTTP (blocking)
    'async_http' => env('TRACEFLOW_ASYNC_HTTP', true),

    'api_key' => env('TRACEFLOW_API_KEY'),

    'username' => env('TRACEFLOW_USERNAME'),

    'password' => env('TRACEFLOW_PASSWORD'),

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
    | Middleware Configuration
    |--------------------------------------------------------------------------
    */
    'middleware' => [
        'enabled' => env('TRACEFLOW_MIDDLEWARE_ENABLED', true),
        'header_name' => 'X-Trace-Id',
    ],
];
