<?php

namespace Smartness\TraceFlow\Transport;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils as PromiseUtils;
use Smartness\TraceFlow\DTO\TraceEvent;

/**
 * Default HTTP transport. Tracing calls never wait for the network.
 *
 * send() only maps the event to a request and appends it to a bounded
 * in-memory queue (no I/O). Requests are sent by flush(), which the Laravel
 * integration calls after the response has been sent (app terminating), after
 * each queue job and at shutdown. flush() drives the requests on a curl multi
 * handle with a hard deadline (flush_timeout), so an unreachable or hanging
 * server costs at most that long per flush, and the shared circuit breaker
 * soon stops even that.
 *
 * Ordering: requests of one trace keep their order where it matters — the
 * trace's create goes before anything else of that trace, a step's create
 * before its update, and the trace's terminal update after everything sent
 * before it. Independent requests run in parallel, at most `concurrency` at
 * a time.
 */
class AsyncHttpTransport extends AbstractHttpTransport
{
    /** How long one curl_multi_select() may block: bounds how far a flush can overshoot its deadline. */
    private const SELECT_TIMEOUT = 0.025;

    private const QUEUED = 0;

    private const IN_FLIGHT = 1;

    private ?CurlMultiHandler $multiHandler = null;

    private float $flushTimeout;

    private int $maxQueueSize;

    private int $concurrency;

    /**
     * Undelivered requests (queued or in flight), keyed by sequence number in send order.
     *
     * @var array<int, array{method: string, uri: string, payload: array, trace: string, step: ?string, barrier: bool, state: int, attempts: int, next_at: float, token: int}>
     */
    private array $items = [];

    private int $sequence = 0;

    private int $tokens = 0;

    /** @var array<int, PromiseInterface> in-flight requests by sequence number */
    private array $inFlight = [];

    /** @var array<int, array{ok: bool, status: ?int, error: ?string, retryable: bool}> settled, not yet processed */
    private array $results = [];

    private bool $delivering = false;

    public function __construct(array $config)
    {
        // Created before the parent constructor, which calls buildClient().
        if (function_exists('curl_multi_exec')) {
            $this->multiHandler = new CurlMultiHandler(['select_timeout' => self::SELECT_TIMEOUT]);
        }

        $this->flushTimeout = max(0.0, (float) ($config['flush_timeout'] ?? 2.0));
        $this->maxQueueSize = max(1, (int) ($config['max_queue_size'] ?? 10000));
        $this->concurrency = max(1, (int) ($config['concurrency'] ?? 4));

        parent::__construct($config);
    }

    protected function buildClient(array $options): Client
    {
        if ($this->multiHandler !== null && ! isset($options['handler'])) {
            $options['handler'] = HandlerStack::create($this->multiHandler);
        }

        try {
            return new Client($options);
        } catch (\Exception $e) {
            if ($this->silentErrors) {
                error_log("[TraceFlow Async] Error initializing client (silenced): {$e->getMessage()}");
                unset($options['base_uri']);

                return new Client($options);
            }
            throw $e;
        }
    }

    protected function logPrefix(): string
    {
        return '[TraceFlow Async]';
    }

    /**
     * Queue the event. No network I/O and no cache access happen here.
     */
    public function send(TraceEvent $event): void
    {
        if ($this->breaker->isKnownOpen()) {
            $this->noteUndelivered('dropped_breaker_open');
            $this->warnUndelivered();

            return;
        }

        if (count($this->items) >= $this->maxQueueSize) {
            $this->noteUndelivered('dropped_queue_full');
            $this->warnUndelivered();

            return;
        }

        $request = $this->buildRequest($event);

        if ($request === null) {
            return;
        }

        $this->items[++$this->sequence] = $request + [
            'state' => self::QUEUED,
            'attempts' => 0,
            'next_at' => 0.0,
            'token' => 0,
        ];
    }

    /**
     * Deliver queued events, waiting at most flush_timeout.
     *
     * Events still undelivered at the deadline (in flight, waiting for a
     * retry, or held while the breaker is open) stay queued for the next
     * flush: in a long-lived process (queue worker, Octane) that is the next
     * job or request. shutdown() drops them instead.
     *
     * With silent_errors=false, throws DeliveryException if events were not
     * delivered since the previous flush.
     */
    public function flush(): void
    {
        $this->deliver(microtime(true) + $this->flushTimeout);
        $this->warnUndelivered();
        $this->reportUndelivered();
    }

    /**
     * Flush with the same deadline, then drop and log what is left. The
     * transport keeps accepting events afterwards: Laravel calls this at the
     * end of every request, and under Octane the process serves more.
     */
    public function shutdown(): void
    {
        try {
            $this->deliver(microtime(true) + $this->flushTimeout);
        } finally {
            $this->dropRemaining();
            $this->warnUndelivered();
        }

        $this->reportUndelivered();
    }

    /**
     * Number of events waiting for delivery (queued or in flight).
     */
    public function pendingCount(): int
    {
        return count($this->items);
    }

    public function getStats(): array
    {
        return parent::getStats() + [
            'queued' => count($this->items) - count($this->inFlight),
            'in_flight' => count($this->inFlight),
        ];
    }

    private function deliver(float $deadline): void
    {
        if ($this->delivering || $this->items === []) {
            return;
        }

        $this->delivering = true;

        try {
            while (true) {
                PromiseUtils::queue()->run();
                $this->processResults();

                if ($this->items === []) {
                    return;
                }

                $now = microtime(true);

                if ($now >= $deadline) {
                    // The flush budget is spent: an attempt still running counts as
                    // a failed one, or a hanging server would never open the breaker.
                    $this->abortInFlight(countAsFailure: true);

                    return;
                }

                $blocked = count($this->inFlight) < $this->concurrency && ! $this->dispatchReady($now);

                if ($this->inFlight !== []) {
                    $this->tick();

                    continue;
                }

                if ($blocked) {
                    // The breaker is open, or another process is probing it.
                    return;
                }

                $nextAttemptAt = $this->nextAttemptAt();

                if ($nextAttemptAt === null || $nextAttemptAt >= $deadline) {
                    // Only retries due after the deadline are left: waiting is pointless.
                    return;
                }

                usleep((int) max(1000, ($nextAttemptAt - $now) * 1_000_000));
            }
        } finally {
            $this->delivering = false;
        }
    }

    /**
     * Send every request that is due and not held back by an earlier request
     * of the same trace or step, up to the concurrency limit.
     *
     * Returns false when the circuit breaker refused a request.
     */
    private function dispatchReady(float $now): bool
    {
        $slots = $this->concurrency - count($this->inFlight);

        /** @var array<string, true> $pendingAhead traces with an undelivered request ahead */
        $pendingAhead = [];
        /** @var array<string, true> $barrierAhead traces with an undelivered create/terminal update ahead */
        $barrierAhead = [];
        /** @var array<string, true> $stepAhead steps with an undelivered request ahead */
        $stepAhead = [];

        foreach ($this->items as $seq => $item) {
            if ($slots <= 0) {
                break;
            }

            $trace = $item['trace'];
            $stepKey = $item['step'] !== null ? $trace.'/'.$item['step'] : null;

            $ready = $item['state'] === self::QUEUED
                && $item['next_at'] <= $now
                && ! isset($barrierAhead[$trace])
                && (! $item['barrier'] || ! isset($pendingAhead[$trace]))
                && ($stepKey === null || ! isset($stepAhead[$stepKey]));

            if ($ready) {
                if (! $this->breaker->allowRequest()) {
                    return false;
                }

                $this->dispatch($seq);
                $slots--;
            }

            $pendingAhead[$trace] = true;

            if ($item['barrier']) {
                $barrierAhead[$trace] = true;
            }

            if ($stepKey !== null) {
                $stepAhead[$stepKey] = true;
            }
        }

        return true;
    }

    private function dispatch(int $seq): void
    {
        $item = $this->items[$seq];
        $token = ++$this->tokens;

        $this->items[$seq]['state'] = self::IN_FLIGHT;
        $this->items[$seq]['attempts'] = $item['attempts'] + 1;
        $this->items[$seq]['token'] = $token;

        try {
            $promise = $this->client->requestAsync($item['method'], $item['uri'], ['json' => $item['payload']]);
        } catch (\Throwable $e) {
            // Invalid request (e.g. unencodable payload): not the server's fault.
            $this->inFlight[$seq] = Create::promiseFor(null);
            $this->results[$seq] = ['ok' => false, 'status' => null, 'error' => $e->getMessage(), 'retryable' => false];

            return;
        }

        $this->inFlight[$seq] = $promise->then(
            function () use ($seq, $token): void {
                $this->settle($seq, $token, ['ok' => true, 'status' => null, 'error' => null, 'retryable' => false]);
            },
            function ($reason) use ($seq, $token): void {
                $error = $reason instanceof \Throwable ? $reason : new \RuntimeException((string) $reason);
                $status = $this->statusCode($error);

                $this->settle($seq, $token, [
                    'ok' => $status === 409,
                    'status' => $status,
                    'error' => $error->getMessage(),
                    'retryable' => $this->isRetryable($error),
                ]);
            },
        );
    }

    /**
     * Promise callback: store the outcome for processResults(). Outcomes of
     * requests cancelled by abortInFlight() are ignored.
     */
    private function settle(int $seq, int $token, array $result): void
    {
        if (($this->items[$seq]['token'] ?? null) === $token && isset($this->inFlight[$seq])) {
            $this->results[$seq] = $result;
        }
    }

    private function processResults(): void
    {
        foreach ($this->results as $seq => $result) {
            unset($this->results[$seq], $this->inFlight[$seq]);

            if (! isset($this->items[$seq])) {
                continue;
            }

            if ($result['ok']) {
                unset($this->items[$seq]);
                $this->noteDelivered();
                $this->breaker->recordSuccess();

                continue;
            }

            if (! $result['retryable']) {
                // 4xx: the request is wrong, not the server. Drop it, no retry,
                // and it does not count toward the breaker.
                unset($this->items[$seq]);
                $this->noteUndelivered('rejected', 1, $result['error']);
                $this->breaker->recordRejection();

                continue;
            }

            $this->noteAttemptError($result['error']);
            $this->breaker->recordFailure();
            $this->retryOrDrop($seq, $result['error']);
        }

        // Opened by this flush or by another process: stop the requests still
        // running instead of waiting for them, and keep them queued.
        if ($this->inFlight !== [] && $this->breaker->knownState() === CircuitBreaker::OPEN) {
            $this->abortInFlight(countAsFailure: false);
        }
    }

    /**
     * Cancel the requests in flight and put them back in the queue.
     */
    private function abortInFlight(bool $countAsFailure): void
    {
        $inFlight = $this->inFlight;
        $this->inFlight = [];

        foreach ($inFlight as $seq => $promise) {
            unset($this->results[$seq]);

            if (! isset($this->items[$seq])) {
                continue;
            }

            $this->items[$seq]['token'] = 0;

            try {
                $promise->cancel();
            } catch (\Throwable) {
                // Nothing to do: the request is abandoned either way.
            }

            if ($countAsFailure) {
                $error = "{$this->items[$seq]['method']} {$this->items[$seq]['uri']}: no response within the flush timeout ({$this->flushTimeout}s)";
                $this->noteAttemptError($error);
                $this->breaker->recordFailure();
                $this->retryOrDrop($seq, $error);
            } else {
                // Not a failed attempt: it was stopped because the breaker opened.
                $this->items[$seq]['state'] = self::QUEUED;
                $this->items[$seq]['attempts']--;
                $this->breaker->abandonProbe();
            }
        }

        // Let the cancellation callbacks run now, while they are cheap to ignore.
        PromiseUtils::queue()->run();
    }

    private function retryOrDrop(int $seq, ?string $error): void
    {
        $attempts = $this->items[$seq]['attempts'];

        if ($attempts > $this->maxRetries) {
            unset($this->items[$seq]);
            $this->noteUndelivered('failed', 1, $error);

            return;
        }

        $this->items[$seq]['state'] = self::QUEUED;
        $this->items[$seq]['next_at'] = microtime(true) + $this->retryDelaySeconds($attempts);
    }

    private function nextAttemptAt(): ?float
    {
        $next = null;

        foreach ($this->items as $item) {
            if ($item['state'] === self::QUEUED && ($next === null || $item['next_at'] < $next)) {
                $next = $item['next_at'];
            }
        }

        return $next;
    }

    /**
     * Let curl make progress: blocks for at most SELECT_TIMEOUT.
     */
    private function tick(): void
    {
        if ($this->multiHandler !== null) {
            $this->multiHandler->tick();
        }

        PromiseUtils::queue()->run();
    }

    private function dropRemaining(): void
    {
        if ($this->items === []) {
            return;
        }

        if ($this->inFlight !== []) {
            $this->abortInFlight(countAsFailure: false);
        }

        $count = count($this->items);
        $this->items = [];
        $this->results = [];

        $this->noteUndelivered(
            $this->breaker->knownState() === CircuitBreaker::CLOSED ? 'dropped_flush_timeout' : 'dropped_breaker_open',
            $count,
        );
    }
}
