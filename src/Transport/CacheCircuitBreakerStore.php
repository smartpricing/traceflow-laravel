<?php

namespace Smartness\TraceFlow\Transport;

use Illuminate\Contracts\Cache\Repository;

/**
 * Circuit breaker state shared through a Laravel cache store, so the failures
 * of all PHP-FPM requests and workers using the same store add up.
 *
 * Cost: the breaker reads two keys at most once per second per process (once
 * per request under FPM, after the response for the async transport), and
 * writes only when an attempt fails or the breaker changes state.
 *
 * A cache error never reaches the application: the store logs it and keeps
 * the state in memory for RETRY_AFTER seconds before trying the cache again.
 * Failure counting is approximate when the cache store has no atomic
 * increment, which is fine for a breaker.
 */
final class CacheCircuitBreakerStore implements CircuitBreakerStore
{
    private const RETRY_AFTER = 30.0;

    /** Lifetime of the failure counter where the store needs one. */
    private const FAILURES_TTL = 3600;

    private ?Repository $cache = null;

    /** @var (\Closure(): Repository)|null */
    private ?\Closure $resolver;

    private InMemoryCircuitBreakerStore $fallback;

    private float $unavailableUntil = 0.0;

    private string $failuresKey;

    private string $openKey;

    private string $probeKey;

    /**
     * @param  Repository|\Closure(): Repository  $cache  the store, or a closure resolving it on first use
     * @param  string  $namespace  key prefix; use one per Traceflow endpoint
     */
    public function __construct(Repository|\Closure $cache, string $namespace = 'traceflow:circuit')
    {
        if ($cache instanceof Repository) {
            $this->cache = $cache;
            $this->resolver = null;
        } else {
            $this->resolver = $cache;
        }

        $this->fallback = new InMemoryCircuitBreakerStore;
        $this->failuresKey = "{$namespace}:failures";
        $this->openKey = "{$namespace}:open_until";
        $this->probeKey = "{$namespace}:probe";
    }

    public function read(): array
    {
        return $this->attempt(function (Repository $cache) {
            $values = $cache->getMultiple([$this->failuresKey, $this->openKey]);
            $values = is_array($values) ? $values : iterator_to_array($values);
            $openUntil = $values[$this->openKey] ?? null;

            return [
                'failures' => (int) ($values[$this->failuresKey] ?? 0),
                'open_until' => is_numeric($openUntil) ? (float) $openUntil : null,
            ];
        }, fn () => $this->fallback->read());
    }

    public function incrementFailures(): int
    {
        return $this->attempt(function (Repository $cache) {
            $count = $cache->increment($this->failuresKey);

            if (is_int($count)) {
                return max(1, $count);
            }

            // Stores such as database and memcached do not increment a missing key.
            if ($cache->add($this->failuresKey, 1, self::FAILURES_TTL)) {
                return 1;
            }

            return max(1, (int) $cache->increment($this->failuresKey));
        }, fn () => $this->fallback->incrementFailures());
    }

    public function open(float $until): void
    {
        $this->attempt(function (Repository $cache) use ($until) {
            // Keep the key a little longer than the open period so every
            // process sees the half-open state once the period is over.
            $ttl = (int) ceil(max(1.0, $until - microtime(true))) + 300;
            $cache->put($this->openKey, $until, $ttl);
            $cache->forget($this->probeKey);
        }, fn () => $this->fallback->open($until));
    }

    public function close(): void
    {
        $this->attempt(function (Repository $cache) {
            $cache->forget($this->openKey);
            $cache->forget($this->failuresKey);
            $cache->forget($this->probeKey);
        }, fn () => $this->fallback->close());
    }

    public function acquireProbe(float $ttlSeconds): bool
    {
        return $this->attempt(
            fn (Repository $cache) => (bool) $cache->add($this->probeKey, 1, max(1, (int) ceil($ttlSeconds))),
            fn () => $this->fallback->acquireProbe($ttlSeconds),
        );
    }

    /**
     * Run $operation against the cache, or $fallback when the cache is unavailable.
     *
     * @template T
     *
     * @param  \Closure(Repository): T  $operation
     * @param  \Closure(): T  $fallback
     * @return T
     */
    private function attempt(\Closure $operation, \Closure $fallback): mixed
    {
        if (microtime(true) < $this->unavailableUntil) {
            return $fallback();
        }

        try {
            return $operation($this->cache());
        } catch (\Throwable $e) {
            $this->unavailableUntil = microtime(true) + self::RETRY_AFTER;
            error_log('[TraceFlow] Circuit breaker cache store unavailable, using per-process state for '
                .(int) self::RETRY_AFTER."s: {$e->getMessage()}");

            return $fallback();
        }
    }

    private function cache(): Repository
    {
        if ($this->cache === null) {
            $resolver = $this->resolver;
            $this->cache = $resolver();
        }

        return $this->cache;
    }
}
