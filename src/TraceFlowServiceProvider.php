<?php

namespace Smartpricing\TraceFlow;

use Illuminate\Support\ServiceProvider;

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
        // Publish config
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/traceflow.php' => config_path('traceflow.php'),
            ], 'traceflow-config');
        }
    }
}

