<?php

namespace Smartness\TraceFlow\Transport;

/**
 * Circuit breaker state kept in the current process only.
 *
 * Right for long-lived processes (queue workers, Octane) and scripts. Under
 * PHP-FPM it lasts one request, so use CacheCircuitBreakerStore there (the
 * Laravel service provider does).
 */
final class InMemoryCircuitBreakerStore implements CircuitBreakerStore
{
    private int $failures = 0;

    private ?float $openUntil = null;

    private float $probeUntil = 0.0;

    public function read(): array
    {
        return ['failures' => $this->failures, 'open_until' => $this->openUntil];
    }

    public function incrementFailures(): int
    {
        return ++$this->failures;
    }

    public function open(float $until): void
    {
        $this->openUntil = $until;
        $this->probeUntil = 0.0;
    }

    public function close(): void
    {
        $this->failures = 0;
        $this->openUntil = null;
        $this->probeUntil = 0.0;
    }

    public function acquireProbe(float $ttlSeconds): bool
    {
        $now = microtime(true);

        if ($this->probeUntil > $now) {
            return false;
        }

        $this->probeUntil = $now + $ttlSeconds;

        return true;
    }
}
