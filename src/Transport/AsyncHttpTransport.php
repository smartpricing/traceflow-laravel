<?php

namespace Smartness\TraceFlow\Transport;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils;
use Smartness\TraceFlow\DTO\TraceEvent;
use Smartness\TraceFlow\Enums\StepStatus;
use Smartness\TraceFlow\Enums\TraceEventType;
use Smartness\TraceFlow\Enums\TraceStatus;

/**
 * Non-blocking HTTP transport using Guzzle async promises
 *
 * Events are sent asynchronously without waiting for responses.
 * Promises are settled during flush() (called on shutdown).
 */
class AsyncHttpTransport implements TransportInterface
{
    private Client $client;

    private string $endpoint;

    private bool $silentErrors;

    private int $maxRetries;

    private int $retryDelay;

    /** @var PromiseInterface[] */
    private array $promises = [];

    private int $eventCount = 0;

    public function __construct(array $config)
    {
        $this->endpoint = $config['endpoint'];
        $this->silentErrors = $config['silent_errors'] ?? true;
        $this->maxRetries = $config['max_retries'] ?? 3;
        $this->retryDelay = $config['retry_delay'] ?? 1000;

        $headers = ['Content-Type' => 'application/json'];

        if (isset($config['api_key'])) {
            $headers['X-API-Key'] = $config['api_key'];
        } elseif (isset($config['username']) && isset($config['password'])) {
            $auth = base64_encode($config['username'].':'.$config['password']);
            $headers['Authorization'] = 'Basic '.$auth;
        }

        try {
            $this->client = new Client([
                'base_uri' => $this->endpoint,
                'headers' => $headers,
                'timeout' => $config['timeout'] ?? 5.0,
            ]);
        } catch (\Exception $e) {
            if ($this->silentErrors) {
                error_log("[TraceFlow Async] Error initializing client (silenced): {$e->getMessage()}");
                // Create a client without base_uri to allow the SDK to continue
                $this->client = new Client([
                    'headers' => $headers,
                    'timeout' => $config['timeout'] ?? 5.0,
                ]);
            } else {
                throw $e;
            }
        }
    }

    public function send(TraceEvent $event)
    {
        try {
            $this->sendEventToAPIAsync($event);
        } catch (\Exception $e) {
            if ($this->silentErrors) {
                error_log("[TraceFlow Async] Error sending event (silenced): {$e->getMessage()}");
            } else {
                throw $e;
            }
        }
    }

    private function sendEventToAPIAsync(TraceEvent $event)
    {
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
    }

    private function createTrace(TraceEvent $event)
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
        ], fn($value) => $value !== null);

        $this->executeAsync('POST', '/api/v1/traces', $payload);
    }

    private function updateTrace(TraceEvent $event)
    {
        $status = match ($event->eventType) {
            TraceEventType::TRACE_FINISHED => TraceStatus::SUCCESS,
            TraceEventType::TRACE_FAILED => TraceStatus::FAILED,
            TraceEventType::TRACE_CANCELLED => TraceStatus::CANCELLED,
            default => TraceStatus::RUNNING,
        };

        $payload = array_filter([
            'status' => $status->value,
            'updated_at' => $event->timestamp,
            'finished_at' => $event->timestamp,
            'last_activity_at' => $event->timestamp,
            'result' => $event->payload['result'] ?? null,
            'error' => $event->payload['error'] ?? null,
            'metadata' => $event->payload['metadata'] ?? null,
        ], fn($value) => $value !== null);

        $this->executeAsync('PATCH', "/api/v1/traces/{$event->traceId}", $payload);
    }

    private function createStep(TraceEvent $event)
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
        ], fn($value) => $value !== null);

        $this->executeAsync('POST', '/api/v1/steps', $payload);
    }

    private function updateStep(TraceEvent $event)
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
        ], fn($value) => $value !== null);

        $this->executeAsync('PATCH', "/api/v1/steps/{$event->traceId}/{$event->stepId}", $payload);
    }

    private function createLog(TraceEvent $event)
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
        ], fn($value) => $value !== null);

        $this->executeAsync('POST', '/api/v1/logs', $payload);
    }

    /**
     * Execute async HTTP request with retry logic
     *
     * Returns immediately without waiting for response.
     * Retries are handled asynchronously if request fails.
     */
    private function executeAsync(string $method, string $uri, array $data, int $attempt = 0)
    {
        $promise = $this->client->requestAsync($method, $uri, ['json' => $data])
            ->then(
                // Success callback
                function ($response) {
                    // Request succeeded, nothing to do
                    $this->eventCount++;
                },
                // Failure callback with retry logic
                function (GuzzleException $exception) use ($method, $uri, $data, $attempt) {
                    if ($attempt < $this->maxRetries) {
                        // Retry with exponential backoff
                        $delay = $this->retryDelay * pow(2, $attempt);
                        usleep($delay * 1000);
                        $this->executeAsync($method, $uri, $data, $attempt + 1);
                    } else {
                        // Max retries exceeded
                        if ($this->silentErrors) {
                            error_log("[TraceFlow Async] Failed after {$this->maxRetries} retries: {$exception->getMessage()}");
                        } else {
                            throw $exception;
                        }
                    }
                }
            );

        // Store promise for later settling
        $this->promises[] = $promise;
    }

    /**
     * Flush all pending async requests
     *
     * Waits for all promises to settle (resolve or reject).
     * Should be called on shutdown to ensure events are sent.
     */
    public function flush()
    {
        if (empty($this->promises)) {
            return;
        }

        try {
            // Wait for all promises to settle
            Utils::settle($this->promises)->wait();

            error_log("[TraceFlow Async] Flushed {$this->eventCount} events successfully");

            // Clear promises array
            $this->promises = [];
            $this->eventCount = 0;
        } catch (\Exception $e) {
            if ($this->silentErrors) {
                error_log("[TraceFlow Async] Error during flush (silenced): {$e->getMessage()}");
            } else {
                throw $e;
            }
        }
    }

    /**
     * Shutdown transport and flush pending events
     */
    public function shutdown()
    {
        error_log('[TraceFlow Async] Shutting down async transport...');
        $this->flush();
    }
}
