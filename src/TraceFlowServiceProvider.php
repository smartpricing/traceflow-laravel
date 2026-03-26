<?php

namespace Smartness\TraceFlow;

use Illuminate\Support\ServiceProvider;
use Smartness\TraceFlow\Console\BattleTestCommand;
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
                'timeout' => config('traceflow.timeout'),
                'max_retries' => config('traceflow.max_retries'),
                'retry_delay' => config('traceflow.retry_delay'),
                'silent_errors' => config('traceflow.silent_errors'),
                'circuit_breaker_threshold' => config('traceflow.circuit_breaker_threshold'),
                'circuit_breaker_timeout_ms' => config('traceflow.circuit_breaker_timeout_ms'),
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
            $this->commands([TestCommand::class, BattleTestCommand::class]);
        }

        // Register shutdown handler to close active handles and flush async events.
        // app->terminating() handles graceful Laravel shutdown.
        // register_shutdown_function() catches exit(), fatal errors, and other abrupt terminations.
        $this->app->terminating(function () {
            $sdk = $this->app->make(TraceFlowSDK::class);
            $sdk->shutdown();
        });

        register_shutdown_function(function () {
            try {
                $sdk = $this->app->make(TraceFlowSDK::class);
                $sdk->flush();
            } catch (\Throwable $e) {
                // Best-effort: never throw from a shutdown function
            }
        });
    }
}
