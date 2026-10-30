<?php

namespace Smartness\TraceFlow\Transport;

/**
 * Circuit breaker with a half-open state, backed by a CircuitBreakerStore.
 *
 * - closed: requests are sent. `threshold` consecutive failed attempts (in any
 *   request or process sharing the store) open it.
 * - open: no request is sent for `timeout` seconds.
 * - half-open: once the timeout has passed, one request in one process (the
 *   holder of the probe lease) is sent as a probe. Success closes the
 *   breaker; failure re-opens it for the full timeout at once.
 *
 * The breaker works on a local copy of the shared state, refreshed at most
 * once per `maxSnapshotAge` seconds (so about once per request under FPM),
 * and writes to the store only on failures and state changes.
 */
final class CircuitBreaker
{
    public const CLOSED = 'closed';

    public const OPEN = 'open';

    public const HALF_OPEN = 'half_open';

    /** @var array{failures: int, open_until: float|null}|null */
    private ?array $snapshot = null;

    private float $snapshotAt = 0.0;

    private bool $probing = false;

    public function __construct(
        private CircuitBreakerStore $store,
        private int $threshold = 5,
        private float $timeoutSeconds = 60.0,
        private float $probeTtlSeconds = 10.0,
        private string $logPrefix = '[TraceFlow]',
        private float $maxSnapshotAge = 1.0,
    ) {
        $this->threshold = max(1, $this->threshold);
        $this->timeoutSeconds = max(0.0, $this->timeoutSeconds);
    }

    public function store(): CircuitBreakerStore
    {
        return $this->store;
    }

    /**
     * Re-read the shared state when the local copy is older than maxSnapshotAge.
     */
    public function refresh(bool $force = false): void
    {
        $now = microtime(true);

        if (! $force && $this->snapshot !== null && $now - $this->snapshotAt < $this->maxSnapshotAge) {
            return;
        }

        $this->snapshot = $this->store->read();
        $this->snapshotAt = $now;
    }

    /**
     * State according to the local copy, without touching the store. Before
     * the first refresh the state is unknown and reported as closed.
     */
    public function knownState(): string
    {
        if ($this->probing) {
            return self::HALF_OPEN;
        }

        $openUntil = $this->snapshot['open_until'] ?? null;

        if ($openUntil === null) {
            return self::CLOSED;
        }

        return microtime(true) < $openUntil ? self::OPEN : self::HALF_OPEN;
    }

    /**
     * Whether the local copy says the breaker is open. Never touches the store.
     */
    public function isKnownOpen(): bool
    {
        return $this->knownState() === self::OPEN;
    }

    /**
     * Current state, refreshing the local copy if it is stale.
     */
    public function state(): string
    {
        $this->refresh();

        return $this->knownState();
    }

    public function isProbing(): bool
    {
        return $this->probing;
    }

    /**
     * Whether a request may be sent now. While half-open only the holder of
     * the probe lease may send, and only one request until its result is
     * recorded.
     */
    public function allowRequest(): bool
    {
        $state = $this->state();

        if ($state === self::CLOSED) {
            return true;
        }

        if ($state === self::OPEN || $this->probing) {
            return false;
        }

        if ($this->store->acquireProbe($this->probeTtlSeconds)) {
            $this->probing = true;

            return true;
        }

        return false;
    }

    /**
     * Forget a probe whose request was abandoned without a result. Its lease
     * expires in the store, after which a request can probe again.
     */
    public function abandonProbe(): void
    {
        $this->probing = false;
    }

    /**
     * The server accepted a request (2xx, or 409 "already exists").
     */
    public function recordSuccess(): void
    {
        if ($this->probing) {
            $this->probing = false;
            $this->store->close();
            $this->setSnapshot(0, null);
            error_log("{$this->logPrefix} Circuit breaker closed: probe succeeded, delivery resumed");

            return;
        }

        // Write only when something changes: one success after failures, or a
        // success from a request sent before another process opened it.
        if (($this->snapshot['failures'] ?? 0) > 0 || ($this->snapshot['open_until'] ?? null) !== null) {
            $this->store->close();
            $this->setSnapshot(0, null);
        }
    }

    /**
     * The server answered with a client error (4xx other than 409 and 429):
     * the request was wrong, not the server. It does not count as a failure;
     * a probe that gets one still proves the server is reachable.
     */
    public function recordRejection(): void
    {
        if ($this->probing) {
            $this->recordSuccess();
        }
    }

    /**
     * An attempt failed: network error, timeout, 5xx or 429.
     */
    public function recordFailure(): void
    {
        $now = microtime(true);

        if ($this->probing) {
            $this->probing = false;
            $until = $now + $this->timeoutSeconds;
            $this->store->open($until);
            $this->setSnapshot($this->snapshot['failures'] ?? $this->threshold, $until);
            error_log("{$this->logPrefix} Circuit breaker re-opened for {$this->timeoutSeconds}s: probe failed; trace events are dropped meanwhile");

            return;
        }

        $failures = $this->store->incrementFailures();
        $this->setSnapshot($failures, $this->snapshot['open_until'] ?? null);

        if ($failures < $this->threshold) {
            return;
        }

        // Another request or process may have opened it already.
        $current = $this->store->read();

        if ($current['open_until'] !== null && $current['open_until'] > $now) {
            $this->setSnapshot($failures, $current['open_until']);

            return;
        }

        $until = $now + $this->timeoutSeconds;
        $this->store->open($until);
        $this->setSnapshot($failures, $until);
        error_log("{$this->logPrefix} Circuit breaker opened for {$this->timeoutSeconds}s after {$failures} consecutive failed attempts; trace events are dropped meanwhile");
    }

    private function setSnapshot(int $failures, ?float $openUntil): void
    {
        $this->snapshot = ['failures' => $failures, 'open_until' => $openUntil];
        $this->snapshotAt = microtime(true);
    }
}
