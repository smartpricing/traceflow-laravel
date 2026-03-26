<?php

namespace Smartness\TraceFlow\Transport;

use GuzzleHttp\Client;
use Smartness\TraceFlow\DTO\TraceEvent;
use Smartness\TraceFlow\Enums\StepStatus;
use Smartness\TraceFlow\Enums\TraceEventType;
use Smartness\TraceFlow\Enums\TraceStatus;

abstract class AbstractHttpTransport implements TransportInterface
{
    protected Client $client;

    protected string $endpoint;

    protected bool $silentErrors;

    protected int $maxRetries;

    protected int $retryDelay;

    // Circuit breaker state
    private int $failureCount = 0;

    private bool $circuitOpen = false;

    private int $circuitOpenUntil = 0;

    private int $circuitBreakerThreshold;

    private int $circuitBreakerTimeoutMs;

    /** @var TraceEvent[] */
    protected array $eventQueue = [];

    public function __construct(array $config)
    {
        $this->endpoint = $config['endpoint'];
        $this->silentErrors = $config['silent_errors'] ?? true;
        $this->maxRetries = $config['max_retries'] ?? 3;
        $this->retryDelay = $config['retry_delay'] ?? 1000;
        $this->circuitBreakerThreshold = $config['circuit_breaker_threshold'] ?? 5;
        $this->circuitBreakerTimeoutMs = $config['circuit_breaker_timeout_ms'] ?? 60000;

        $headers = ['Content-Type' => 'application/json'];

        if (isset($config['api_key'])) {
            $headers['X-API-Key'] = $config['api_key'];
        }

        $this->client = $this->buildClient([
            'base_uri' => $this->endpoint,
            'headers' => $headers,
            'timeout' => $config['timeout'] ?? 5.0,
        ]);
    }

    protected function buildClient(array $options): Client
    {
        return new Client($options);
    }

    public function send(TraceEvent $event): void
    {
        if ($this->isCircuitOpen()) {
            $this->eventQueue[] = $event;
            error_log($this->logPrefix()." Circuit open, queued event: {$event->eventType->value} (".count($this->eventQueue)." pending)");

            return;
        }

        try {
            match ($event->eventType) {
                TraceEventType::TRACE_STARTED => $this->createTrace($event),
                TraceEventType::TRACE_FINISHED,
                TraceEventType::TRACE_FAILED,
                TraceEventType::TRACE_CANCELLED => $this->updateTrace($event),
                TraceEventType::STEP_STARTED => $this->createStep($event),
                TraceEventType::STEP_FINISHED,
                TraceEventType::STEP_FAILED => $this->updateStep($event),
                TraceEventType::LOG_EMITTED => $this->createLog($event),
                default => null,
            };
            // Reset on success
            $this->failureCount = 0;
        } catch (\Exception $e) {
            if ($this->silentErrors) {
                error_log($this->logPrefix()." Error sending event (silenced): {$e->getMessage()}");
            } else {
                throw $e;
            }
        }
    }

    protected function recordFailure(): void
    {
        if ($this->circuitOpen) {
            return;
        }

        $this->failureCount++;
        if ($this->failureCount >= $this->circuitBreakerThreshold) {
            $this->circuitOpen = true;
            $this->circuitOpenUntil = (int) (microtime(true) * 1000) + $this->circuitBreakerTimeoutMs;
            error_log($this->logPrefix()." Circuit breaker opened for {$this->circuitBreakerTimeoutMs}ms");
        }
    }

    private function isCircuitOpen(): bool
    {
        if ($this->circuitOpen && (int) (microtime(true) * 1000) > $this->circuitOpenUntil) {
            $this->circuitOpen = false;
            $this->failureCount = 0;
            error_log($this->logPrefix().' Circuit breaker closed, resuming requests');
            $this->drainQueue();
        }

        return $this->circuitOpen;
    }

    protected function drainQueue(): void
    {
        if (empty($this->eventQueue)) {
            return;
        }

        $queued = $this->eventQueue;
        $this->eventQueue = [];

        foreach ($queued as $event) {
            $this->send($event);
        }
    }

    private function createTrace(TraceEvent $event): void
    {
        $payload = array_filter([
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

        $this->dispatchSafe('POST', '/api/v1/traces', $payload);
    }

    private function updateTrace(TraceEvent $event): void
    {
        $status = match ($event->eventType) {
            TraceEventType::TRACE_FINISHED => TraceStatus::SUCCESS,
            TraceEventType::TRACE_FAILED => TraceStatus::FAILED,
            TraceEventType::TRACE_CANCELLED => TraceStatus::CANCELLED,
            default => throw new \UnexpectedValueException("Unexpected event type for updateTrace: {$event->eventType->value}"),
        };

        $payload = array_filter([
            'status' => $status->value,
            'updated_at' => $event->timestamp,
            'finished_at' => $event->timestamp,
            'last_activity_at' => $event->timestamp,
            'result' => $event->payload['result'] ?? null,
            'error' => $event->payload['error'] ?? null,
            'stack' => $event->payload['stack'] ?? null,
            'metadata' => $event->payload['metadata'] ?? null,
        ], fn ($value) => $value !== null);

        $this->dispatchSafe('PATCH', "/api/v1/traces/{$event->traceId}", $payload);
    }

    private function createStep(TraceEvent $event): void
    {
        $payload = array_filter([
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

        $this->dispatchSafe('POST', '/api/v1/steps', $payload);
    }

    private function updateStep(TraceEvent $event): void
    {
        $status = $event->eventType === TraceEventType::STEP_FINISHED
            ? StepStatus::COMPLETED
            : StepStatus::FAILED;

        $payload = array_filter([
            'status' => $status->value,
            'updated_at' => $event->timestamp,
            'finished_at' => $event->timestamp,
            'output' => $event->payload['output'] ?? null,
            'error' => $event->payload['error'] ?? null,
            'metadata' => $event->payload['metadata'] ?? null,
        ], fn ($value) => $value !== null);

        $this->dispatchSafe('PATCH', "/api/v1/steps/{$event->traceId}/{$event->stepId}", $payload);
    }

    private function createLog(TraceEvent $event): void
    {
        $payload = array_filter([
            'trace_id' => $event->traceId,
            'log_time' => $event->timestamp,
            'log_id' => $event->eventId,
            'level' => $event->payload['level'] ?? 'INFO',
            'message' => $event->payload['message'],
            'details' => $event->payload['details'] ?? null,
            'source' => $event->source,
            'event_type' => $event->payload['event_type'] ?? null,
        ], fn ($value) => $value !== null);

        $this->dispatchSafe('POST', '/api/v1/logs', $payload);
    }

    /**
     * Sanitize the payload and delegate to dispatch.
     */
    private function dispatchSafe(string $method, string $uri, array $payload): void
    {
        $this->dispatch($method, $uri, $this->sanitizePayload($payload));
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

    abstract protected function dispatch(string $method, string $uri, array $payload): void;

    abstract protected function logPrefix(): string;
}
