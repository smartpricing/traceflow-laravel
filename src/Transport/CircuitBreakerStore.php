<?php

namespace Smartness\TraceFlow\Transport;

/**
 * Where the circuit breaker keeps its state.
 *
 * Under PHP-FPM every object dies with the request, so a breaker kept in a
 * transport object never sees more than one request's failures and never
 * opens. A store shared by all requests and workers (the Laravel cache, see
 * CacheCircuitBreakerStore) lets those failures add up.
 *
 * Implementations must never throw. Times are Unix timestamps in seconds.
 */
interface CircuitBreakerStore
{
    /**
     * Current shared state.
     *
     * @return array{failures: int, open_until: float|null}
     */
    public function read(): array;

    /**
     * Record one more consecutive failed attempt and return the new count.
     */
    public function incrementFailures(): int;

    /**
     * Open (or re-open) the breaker until the given time.
     */
    public function open(float $until): void;

    /**
     * Close the breaker: forget the failures, the open state and the probe lease.
     */
    public function close(): void;

    /**
     * Claim the single half-open probe for the given time. Returns false when
     * another request or process already holds it.
     */
    public function acquireProbe(float $ttlSeconds): bool;
}
