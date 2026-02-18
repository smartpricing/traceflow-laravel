<?php

namespace Smartness\TraceFlow;

use Illuminate\Support\ServiceProvider;
use Smartness\TraceFlow\Console\TestCommand;

class TraceFlowServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/traceflow.php', 'traceflow'
        );

        // Register SDK as singleton
        $this->app->singleton(TraceFlowSDK::class, function ($app) {
            return new TraceFlowSDK([
                'transport' => config('traceflow.transport'),
                'source' => config('traceflow.source'),
                'endpoint' => config('traceflow.endpoint'),
                'async_http' => config('traceflow.async_http'),
                'api_key' => config('traceflow.api_key'),
                'username' => config('traceflow.username'),
                'password' => config('traceflow.password'),
                'timeout' => config('traceflow.timeout'),
                'max_retries' => config('traceflow.max_retries'),
                'retry_delay' => config('traceflow.retry_delay'),
                'silent_errors' => config('traceflow.silent_errors'),
            ]);
        });

        // Register alias
        $this->app->alias(TraceFlowSDK::class, 'traceflow');
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            // Publish config
            $this->publishes([
                __DIR__.'/../config/traceflow.php' => config_path('traceflow.php'),
            ], 'traceflow-config');

            // Register commands
            $this->commands([TestCommand::class]);
        }

        // Register shutdown handler to flush async events
        $this->app->terminating(function () {
            $sdk = $this->app->make(TraceFlowSDK::class);
            $sdk->shutdown();
        });
    }
}
