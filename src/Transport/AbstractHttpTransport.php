<?php

namespace Smartness\TraceFlow\Transport;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Smartness\TraceFlow\DTO\TraceEvent;
use Smartness\TraceFlow\Enums\StepStatus;
use Smartness\TraceFlow\Enums\TraceEventType;
use Smartness\TraceFlow\Enums\TraceStatus;

abstract class AbstractHttpTransport implements TransportInterface
{
    /** Drop warnings are logged at most once per this many seconds per process. */
    protected const DROP_WARNING_INTERVAL = 10.0;

    /** Upper bound of one retry delay, in milliseconds. */
    protected const MAX_RETRY_DELAY_MS = 30000;

    protected Client $client;

    protected string $endpoint;

    protected bool $silentErrors;

    protected int $maxRetries;

    protected int $retryDelay;

    /** Total timeout of one HTTP attempt (connect + response), in seconds. */
    protected float $timeout;

    protected CircuitBreaker $breaker;

    /**
     * Cumulative delivery counters for this transport.
     *
     * @var array<string, int>
     */
    protected array $stats = [
        'delivered' => 0,
        'failed' => 0,
        'rejected' => 0,
        'dropped_queue_full' => 0,
        'dropped_breaker_open' => 0,
        'dropped_flush_timeout' => 0,
    ];

    /** @var array<string, int> undelivered events per reason since the last failure report */
    private array $undeliveredSinceReport = [];

    private ?string $lastError = null;

    /** @var array<string, int> */
    private array $dropsSinceWarning = [];

    private float $lastDropWarningAt = 0.0;

    private float $createdAt;

    public function __construct(array $config)
    {
        $this->createdAt = microtime(true);
        $this->endpoint = $config['endpoint'];
        $this->silentErrors = (bool) ($config['silent_errors'] ?? true);
        $this->maxRetries = max(0, (int) ($config['max_retries'] ?? 3));
        $this->retryDelay = max(0, (int) ($config['retry_delay'] ?? 1000));
        $this->timeout = (float) ($config['timeout'] ?? 5.0);

        $store = $config['circuit_breaker_store'] ?? null;
        $this->breaker = new CircuitBreaker(
            store: $store instanceof CircuitBreakerStore ? $store : new InMemoryCircuitBreakerStore,
            threshold: (int) ($config['circuit_breaker_threshold'] ?? 5),
            timeoutSeconds: ((int) ($config['circuit_breaker_timeout_ms'] ?? 60000)) / 1000,
            // A probe lasts at most one attempt (sync) or one flush (async).
            probeTtlSeconds: max($this->timeout, (float) ($config['flush_timeout'] ?? 0)) + 1.0,
            logPrefix: $this->logPrefix(),
        );

        $headers = ['Content-Type' => 'application/json'];

        if (isset($config['api_key'])) {
            $headers['X-API-Key'] = $config['api_key'];
        }

        $this->client = $this->buildClient([
            'base_uri' => $this->endpoint,
            'headers' => $headers,
            'timeout' => $this->timeout,
        ]);
    }

    protected function buildClient(array $options): Client
    {
        return new Client($options);
    }

    public function circuitBreaker(): CircuitBreaker
    {
        return $this->breaker;
    }

    /**
     * Delivery counters since this transport was created.
     *
     * @return array<string, int|string>
     */
    public function getStats(): array
    {
        return $this->stats + ['circuit' => $this->breaker->knownState()];
    }

    /**
     * Map a trace event to its HTTP request. The payload is sanitized now, so
     * later changes to objects the caller passed do not affect what is sent.
     *
     * @return array{method: string, uri: string, payload: array, trace: string, step: ?string, barrier: bool}|null
     */
    protected function buildRequest(TraceEvent $event): ?array
    {
        $request = match ($event->eventType) {
            TraceEventType::TRACE_STARTED => ['POST', '/api/v1/traces', $this->createTracePayload($event)],
            TraceEventType::TRACE_FINISHED,
            TraceEventType::TRACE_FAILED,
            TraceEventType::TRACE_CANCELLED => ['PATCH', "/api/v1/traces/{$event->traceId}", $this->updateTracePayload($event)],
            TraceEventType::STEP_STARTED => ['POST', '/api/v1/steps', $this->createStepPayload($event)],
            TraceEventType::STEP_FINISHED,
            TraceEventType::STEP_FAILED => ['PATCH', "/api/v1/steps/{$event->traceId}/{$event->stepId}", $this->updateStepPayload($event)],
            TraceEventType::LOG_EMITTED => ['POST', '/api/v1/logs', $this->createLogPayload($event)],
            default => null,
        };

        if ($request === null) {
            return null;
        }

        $isStepEvent = in_array($event->eventType, [
            TraceEventType::STEP_STARTED, TraceEventType::STEP_FINISHED, TraceEventType::STEP_FAILED,
        ], true);

        return [
            'method' => $request[0],
            'uri' => $request[1],
            'payload' => $this->sanitizePayload($request[2]),
            'trace' => $event->traceId,
            // Requests of the same step must be delivered in order (create, then update).
            'step' => $isStepEvent ? (string) $event->stepId : null,
            // A trace's create and its terminal update wait for everything sent
            // before them for that trace, and hold back everything sent after.
            'barrier' => in_array($event->eventType, [
                TraceEventType::TRACE_STARTED, TraceEventType::TRACE_FINISHED,
                TraceEventType::TRACE_FAILED, TraceEventType::TRACE_CANCELLED,
            ], true),
        ];
    }

    private function createTracePayload(TraceEvent $event): array
    {
        return array_filter([
            'trace_id' => $event->traceId,
            'trace_type' => $event->payload['trace_type'] ?? null,
            'status' => TraceStatus::PENDING->value,
            'source' => $event->source,
            'created_at' => $event->timestamp,
            'updated_at' => $event->timestamp,
            'last_activity_at' => $event->timestamp,
            'title' => $event->payload['title'] ?? null,
            'description' => $event->payload['description'] ?? null,
            'owner' => $event->payload['owner'] ?? null,
            'tags' => $event->payload['tags'] ?? null,
            'metadata' => $event->payload['metadata'] ?? null,
            'params' => $event->payload['params'] ?? null,
            'idempotency_key' => $event->payload['idempotency_key'] ?? $event->eventId,
            'trace_timeout_ms' => $event->payload['trace_timeout_ms'] ?? null,
            'step_timeout_ms' => $event->payload['step_timeout_ms'] ?? null,
        ], fn ($value) => $value !== null);
    }

    private function updateTracePayload(TraceEvent $event): array
    {
        $status = match ($event->eventType) {
            TraceEventType::TRACE_FINISHED => TraceStatus::SUCCESS,
            TraceEventType::TRACE_FAILED => TraceStatus::FAILED,
            TraceEventType::TRACE_CANCELLED => TraceStatus::CANCELLED,
            default => throw new \UnexpectedValueException("Unexpected event type for updateTrace: {$event->eventType->value}"),
        };

        return array_filter([
            'status' => $status->value,
            'updated_at' => $event->timestamp,
            'finished_at' => $event->timestamp,
            'last_activity_at' => $event->timestamp,
            'result' => $event->payload['result'] ?? null,
            'error' => $event->payload['error'] ?? null,
            'stack' => $event->payload['stack'] ?? null,
            'metadata' => $event->payload['metadata'] ?? null,
        ], fn ($value) => $value !== null);
    }

    private function createStepPayload(TraceEvent $event): array
    {
        return array_filter([
            'trace_id' => $event->traceId,
            'step_id' => $event->stepId,
            'step_type' => $event->payload['step_type'] ?? null,
            'name' => $event->payload['name'] ?? null,
            'status' => StepStatus::STARTED->value,
            'started_at' => $event->timestamp,
            'updated_at' => $event->timestamp,
            'input' => $event->payload['input'] ?? null,
            'metadata' => $event->payload['metadata'] ?? null,
        ], fn ($value) => $value !== null);
    }

    private function updateStepPayload(TraceEvent $event): array
    {
        $status = $event->eventType === TraceEventType::STEP_FINISHED
            ? StepStatus::COMPLETED
            : StepStatus::FAILED;

        return array_filter([
            'status' => $status->value,
            'updated_at' => $event->timestamp,
            'finished_at' => $event->timestamp,
            'output' => $event->payload['output'] ?? null,
            'error' => $event->payload['error'] ?? null,
            'metadata' => $event->payload['metadata'] ?? null,
        ], fn ($value) => $value !== null);
    }

    private function createLogPayload(TraceEvent $event): array
    {
        return array_filter([
            'trace_id' => $event->traceId,
            'log_time' => $event->timestamp,
            'log_id' => $event->eventId,
            'level' => $event->payload['level'] ?? 'INFO',
            'message' => $event->payload['message'],
            'details' => $event->payload['details'] ?? null,
            'source' => $event->source,
            'event_type' => $event->payload['event_type'] ?? null,
        ], fn ($value) => $value !== null);
    }

    /**
     * Recursively sanitize a payload so it is safe for json_encode.
     * Closures, resources, and non-serializable objects are replaced
     * with descriptive string placeholders instead of crashing.
     */
    protected function sanitizePayload(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 64) {
            return '[max depth reached]';
        }

        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if ($value instanceof \Closure) {
            return '[Closure]';
        }

        if (is_resource($value)) {
            return '[resource:' . get_resource_type($value) . ']';
        }

        if (is_array($value)) {
            $sanitized = [];
            foreach ($value as $k => $v) {
                $sanitized[$k] = $this->sanitizePayload($v, $depth + 1);
            }

            return $sanitized;
        }

        if (is_object($value)) {
            if ($value instanceof \JsonSerializable) {
                try {
                    return $this->sanitizePayload($value->jsonSerialize(), $depth + 1);
                } catch (\Throwable) {
                    return '[' . get_class($value) . ': serialization failed]';
                }
            }

            if ($value instanceof \Stringable) {
                try {
                    return (string) $value;
                } catch (\Throwable) {
                    return '[' . get_class($value) . ': __toString failed]';
                }
            }

            if ($value instanceof \UnitEnum) {
                return $value instanceof \BackedEnum ? $value->value : $value->name;
            }

            // Last resort: try to convert to array
            try {
                return $this->sanitizePayload((array) $value, $depth + 1);
            } catch (\Throwable) {
                return '[' . get_class($value) . ']';
            }
        }

        return '[unknown type: ' . gettype($value) . ']';
    }

    /**
     * HTTP status code carried by an exception, or null for transport-level
     * errors (connection refused, timeout, DNS) that have no response.
     */
    protected function statusCode(\Throwable $e): ?int
    {
        if ($e instanceof RequestException && $e->hasResponse()) {
            return $e->getResponse()->getStatusCode();
        }

        return null;
    }

    /**
     * Network/transport errors, timeouts, 5xx and 429 are worth retrying and
     * count toward the circuit breaker. Other 4xx client errors are
     * deterministic: retrying a 404/422 just wastes attempts.
     */
    protected function isRetryable(\Throwable $e): bool
    {
        $status = $this->statusCode($e);

        return $status === null || $status >= 500 || $status === 429;
    }

    /**
     * A 409 Conflict on a create means the entity already exists. In distributed
     * tracing a trace_id is propagated across services, so more than one service
     * may try to create the same trace/step — that is expected, not an error.
     */
    protected function isBenignConflict(\Throwable $e): bool
    {
        return $this->statusCode($e) === 409;
    }

    /**
     * Delay before retry number $attempt (1-based): exponential backoff from
     * retry_delay, capped at 30 s, with jitter (between half and all of it).
     */
    protected function retryDelaySeconds(int $attempt): float
    {
        $base = min(self::MAX_RETRY_DELAY_MS, $this->retryDelay * (2 ** max(0, $attempt - 1)));
        $half = intdiv((int) $base, 2);

        return ($half + mt_rand(0, max(0, (int) $base - $half))) / 1000;
    }

    protected function noteDelivered(): void
    {
        $this->stats['delivered']++;
    }

    /**
     * Remember the error of a failed attempt, for the next warning or report.
     */
    protected function noteAttemptError(?string $error): void
    {
        if ($error !== null) {
            $this->lastError = $error;
        }
    }

    /**
     * Count events that will not be delivered. $reason is one of failed,
     * rejected, dropped_queue_full, dropped_breaker_open, dropped_flush_timeout.
     */
    protected function noteUndelivered(string $reason, int $count = 1, ?string $error = null): void
    {
        if ($count <= 0) {
            return;
        }

        $this->stats[$reason] = ($this->stats[$reason] ?? 0) + $count;
        $this->undeliveredSinceReport[$reason] = ($this->undeliveredSinceReport[$reason] ?? 0) + $count;
        $this->dropsSinceWarning[$reason] = ($this->dropsSinceWarning[$reason] ?? 0) + $count;

        if ($error !== null) {
            $this->lastError = $error;
        }
    }

    /**
     * Log undelivered events at most once per DROP_WARNING_INTERVAL, with the
     * counts since the previous warning.
     *
     * Events dropped because the breaker is open are left out of the warning
     * in processes younger than the interval: under PHP-FPM every request
     * starts a fresh transport, so they would otherwise be logged once per
     * request for the whole outage. The breaker logs when it opens and
     * re-opens instead, and long-lived processes (queue workers, Octane)
     * still report them every interval.
     */
    protected function warnUndelivered(): void
    {
        if ($this->dropsSinceWarning === []) {
            return;
        }

        $now = microtime(true);

        if ($now - $this->lastDropWarningAt < self::DROP_WARNING_INTERVAL) {
            return;
        }

        $report = $this->dropsSinceWarning;

        if ($now - $this->createdAt < self::DROP_WARNING_INTERVAL) {
            unset($report['dropped_breaker_open']);
        }

        if ($report === []) {
            return;
        }

        $parts = [];
        foreach ($report as $reason => $count) {
            $parts[] = "{$reason}={$count}";
            unset($this->dropsSinceWarning[$reason]);
        }

        $message = $this->logPrefix().' '.array_sum($report).' trace event(s) not delivered since the last warning ('.implode(', ', $parts).')';

        if ($this->lastError !== null) {
            $message .= "; last error: {$this->lastError}";
        }

        error_log($message);
        $this->lastDropWarningAt = $now;
    }

    /**
     * With silent_errors=false, throw if events went undelivered since the
     * previous report. Called by flush() and shutdown().
     */
    protected function reportUndelivered(): void
    {
        if ($this->silentErrors || $this->undeliveredSinceReport === []) {
            return;
        }

        $undelivered = $this->undeliveredSinceReport;
        $lastError = $this->lastError;
        $this->undeliveredSinceReport = [];

        throw new DeliveryException($undelivered, $lastError);
    }

    abstract protected function logPrefix(): string;
}
