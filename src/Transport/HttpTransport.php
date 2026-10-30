<?php

namespace Smartness\TraceFlow\Transport;

use GuzzleHttp\Exception\GuzzleException;
use Smartness\TraceFlow\DTO\TraceEvent;

/**
 * Synchronous HTTP transport (async_http=false): an explicit opt-in that
 * BLOCKS the caller. Every tracing call waits for its request, including
 * retries and their backoff: up to (max_retries + 1) x timeout plus the
 * delays when the server hangs.
 *
 * It honours the shared circuit breaker: while the breaker is open, events
 * are dropped at once without a request, and once half-open a single call
 * probes the server (one attempt, no retries).
 *
 * With silent_errors=false a delivery failure is thrown from the tracing
 * call, since the caller is waiting for it anyway.
 */
class HttpTransport extends AbstractHttpTransport
{
    protected function logPrefix(): string
    {
        return '[TraceFlow HTTP]';
    }

    public function send(TraceEvent $event): void
    {
        $request = $this->buildRequest($event);

        if ($request === null) {
            return;
        }

        if (! $this->breaker->allowRequest()) {
            $this->noteUndelivered('dropped_breaker_open');
            $this->warnUndelivered();

            return;
        }

        try {
            $this->execute($request['method'], $request['uri'], $request['payload']);
        } catch (\Exception $e) {
            if (! $this->silentErrors) {
                throw $e;
            }

            $this->warnUndelivered();
        }
    }

    private function execute(string $method, string $uri, array $payload): void
    {
        $isProbe = $this->breaker->isProbing();
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                $this->client->request($method, $uri, ['json' => $payload]);
                $this->noteDelivered();
                $this->breaker->recordSuccess();

                return;
            } catch (GuzzleException $e) {
                // The entity already exists (e.g. a trace_id shared across services).
                // Treat as success — nothing to retry, nothing to fail.
                if ($this->isBenignConflict($e)) {
                    $this->noteDelivered();
                    $this->breaker->recordSuccess();

                    return;
                }

                if (! $this->isRetryable($e)) {
                    $this->noteUndelivered('rejected', 1, $e->getMessage());
                    $this->breaker->recordRejection();

                    throw $e;
                }

                $this->breaker->recordFailure();

                if ($isProbe || $attempt > $this->maxRetries || $this->breaker->knownState() !== CircuitBreaker::CLOSED) {
                    $this->noteUndelivered('failed', 1, $e->getMessage());

                    throw $e;
                }

                usleep((int) ($this->retryDelaySeconds($attempt) * 1_000_000));
            }
        }
    }

    public function flush(): void
    {
        // Nothing is buffered: every event was sent (or dropped) by send().
        $this->warnUndelivered();
    }

    public function shutdown(): void
    {
        $this->warnUndelivered();
    }
}
