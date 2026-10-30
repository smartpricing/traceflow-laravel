<?php

namespace Smartness\TraceFlow;

use Illuminate\Container\Container;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\ServiceProvider;
use Laravel\Octane\Events\RequestTerminated;
use Smartness\TraceFlow\Audit\AuditClient;
use Smartness\TraceFlow\Audit\AuditContext;
use Smartness\TraceFlow\Audit\AuditPublisher;
use Smartness\TraceFlow\Console\BattleTestCommand;
use Smartness\TraceFlow\Console\TestCommand;
use Smartness\TraceFlow\Transport\CacheCircuitBreakerStore;
use Smartness\TraceFlow\Transport\CircuitBreakerStore;
use Smartness\TraceFlow\Transport\InMemoryCircuitBreakerStore;

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
        $this->app->scoped(AuditContext::class, fn () => new AuditContext);
        $this->app->singleton(AuditClient::class, fn () => new AuditClient(config('traceflow')));
        $this->app->singleton(AuditPublisher::class, fn () => new AuditPublisher(config('traceflow')));

        // Register SDK as singleton
        $this->app->singleton(TraceFlowSDK::class, function ($app) {
            return new TraceFlowSDK([
                'enabled' => config('traceflow.enabled', true),
                'transport' => config('traceflow.transport'),
                'source' => config('traceflow.source'),
                'endpoint' => config('traceflow.endpoint'),
                'async_http' => config('traceflow.async_http'),
                'api_key' => config('traceflow.api_key'),
                'timeout' => config('traceflow.timeout'),
                'max_retries' => config('traceflow.max_retries'),
                'retry_delay' => config('traceflow.retry_delay'),
                'flush_timeout' => config('traceflow.flush_timeout'),
                'max_queue_size' => config('traceflow.max_queue_size'),
                'concurrency' => config('traceflow.concurrency'),
                'silent_errors' => config('traceflow.silent_errors'),
                // The flat keys were read before the circuit_breaker section existed; still honoured.
                'circuit_breaker_threshold' => config('traceflow.circuit_breaker_threshold') ?? config('traceflow.circuit_breaker.threshold'),
                'circuit_breaker_timeout_ms' => config('traceflow.circuit_breaker_timeout_ms') ?? config('traceflow.circuit_breaker.timeout_ms'),
                'circuit_breaker_store' => $this->circuitBreakerStore(),
                'log_channel' => config('traceflow.log_channel'),
                'log_level' => config('traceflow.log_level'),
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

        // Trace events are queued in memory and sent at these points, each
        // flush waiting at most traceflow.flush_timeout:
        //
        // - app terminating: after the response has been sent under PHP-FPM
        //   (Symfony's Response::send() calls fastcgi_finish_request()) and
        //   under Octane; at the end of an artisan command. Closes the traces
        //   left open, flushes, and drops what is still undelivered.
        // - after each queue job, because a worker never terminates between jobs.
        // - register_shutdown_function(): exit(), fatal errors and other abrupt ends.
        $this->app->terminating(function () {
            $this->runSdk(fn (TraceFlowSDK $sdk) => $sdk->shutdown());
        });

        $flushAfterJob = function (object $event): void {
            // Sync (and deferred) jobs run inside the current request or
            // command: flushing there would make it wait for the network.
            if (($event->job ?? null) instanceof SyncJob) {
                return;
            }

            $this->runSdk(fn (TraceFlowSDK $sdk) => $sdk->flush());
        };

        $events = $this->app['events'];
        $events->listen(JobProcessed::class, $flushAfterJob);
        $events->listen(JobExceptionOccurred::class, $flushAfterJob);
        $events->listen(JobFailed::class, $flushAfterJob);

        // Octane runs the terminating callbacks too; this flushes whatever a
        // later terminating callback queued.
        if (class_exists(RequestTerminated::class)) {
            $events->listen(RequestTerminated::class, function (RequestTerminated $event) {
                $this->runSdk(fn (TraceFlowSDK $sdk) => $sdk->flush(), $event->sandbox);
            });
        }

        register_shutdown_function(function () {
            $this->runSdk(fn (TraceFlowSDK $sdk) => $sdk->flush());
        });
    }

    /**
     * Run a flush/shutdown on the SDK if it was used, never letting a delivery
     * error escape into the application (it would surface after the response,
     * or fail a queue job that already succeeded).
     *
     * The SDK is looked up in the container serving the current request:
     * Octane handles each request in a clone of the application (and makes it
     * the current container), so the SDK used by the request lives there, not
     * in the application this provider was booted with.
     */
    private function runSdk(\Closure $callback, ?Container $app = null): void
    {
        try {
            $app ??= Container::getInstance();
            if (! $app->resolved(TraceFlowSDK::class)) {
                return;
            }

            $callback($app->make(TraceFlowSDK::class));
        } catch (\Throwable $e) {
            error_log("[TraceFlow] {$e->getMessage()}");
        }
    }

    /**
     * The circuit breaker state must be shared across requests: under
     * PHP-FPM each request has its own transport, so a per-process breaker
     * would never see enough failures to open. Laravel's cache store is used
     * (traceflow.circuit_breaker.cache_store, null = default store); if it
     * fails, the breaker falls back to per-process state.
     */
    private function circuitBreakerStore(): CircuitBreakerStore
    {
        if (! filter_var(config('traceflow.circuit_breaker.shared', true), FILTER_VALIDATE_BOOL)) {
            return new InMemoryCircuitBreakerStore;
        }

        $storeName = config('traceflow.circuit_breaker.cache_store');

        return new CacheCircuitBreakerStore(
            fn () => $this->app['cache']->store($storeName ?: null),
            'traceflow:circuit:'.sha1((string) config('traceflow.endpoint')),
        );
    }
}
