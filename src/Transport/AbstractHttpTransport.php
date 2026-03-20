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

    public function __construct(array $config)
    {
        $this->endpoint = $config['endpoint'];
        $this->silentErrors = $config['silent_errors'] ?? true;
        $this->maxRetries = $config['max_retries'] ?? 3;
        $this->retryDelay = $config['retry_delay'] ?? 1000;

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
        } catch (\Exception $e) {
            if ($this->silentErrors) {
                error_log($this->logPrefix()." Error sending event (silenced): {$e->getMessage()}");
            } else {
                throw $e;
            }
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

        $this->dispatch('POST', '/api/v1/traces', $payload);
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
            'metadata' => $event->payload['metadata'] ?? null,
        ], fn ($value) => $value !== null);

        $this->dispatch('PATCH', "/api/v1/traces/{$event->traceId}", $payload);
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

        $this->dispatch('POST', '/api/v1/steps', $payload);
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

        $this->dispatch('PATCH', "/api/v1/steps/{$event->traceId}/{$event->stepId}", $payload);
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

        $this->dispatch('POST', '/api/v1/logs', $payload);
    }

    abstract protected function dispatch(string $method, string $uri, array $payload): void;

    abstract protected function logPrefix(): string;
}
