<?php

namespace Smartness\TraceFlow\Audit;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

final class AuditClient
{
    public function __construct(private readonly array $config, private readonly ?ClientInterface $http = null) {}

    public function createEvent(array $input): AuditEvent
    {
        return AuditEvent::create($this->config['source'] ?? '', $input);
    }

    public function recordAudit(array $input): array
    {
        return $this->record($this->createEvent($input));
    }

    /** Synchronous durable receipt; never uses the tracing silent_errors or async transport. */
    public function record(AuditEvent $event, ?float $budgetSeconds = null): array
    {
        if (($this->config['enabled'] ?? true) === false) {
            throw new AuditDeliveryException('Audit delivery is disabled', $event->id(), true);
        }
        if (empty($this->config['endpoint']) || empty($this->config['api_key'])) {
            throw new AuditDeliveryException('Audit requires endpoint and API key', $event->id(), true);
        }
        if ($event->source() !== ($this->config['source'] ?? null)) {
            throw new AuditDeliveryException('Audit event source does not match publisher source', $event->id(), false);
        }
        $deadline = $budgetSeconds === null ? null : hrtime(true) / 1e9 + max(0, $budgetSeconds);
        $http = $this->http ?? new Client;
        $retries = min(10, max(0, (int) ($this->config['max_retries'] ?? 3)));
        for ($attempt = 0; ; $attempt++) {
            $remaining = $deadline === null ? INF : $deadline - hrtime(true) / 1e9;
            if ($remaining <= 0) {
                throw new AuditDeliveryException('Audit delivery deadline exceeded', $event->id(), true);
            }
            $delay = min(30000, ($this->config['retry_delay'] ?? 250) * (2 ** $attempt));
            try {
                $response = $http->request('POST', rtrim($this->config['endpoint'], '/').'/api/v1/audit-events', [
                    'headers' => ['X-API-Key' => $this->config['api_key'], 'Content-Type' => 'application/json'],
                    'body' => $event->toJson(),
                    'timeout' => min(max(0.001, (float) ($this->config['timeout'] ?? 10)), $remaining),
                    'connect_timeout' => min(5.0, $remaining),
                    'allow_redirects' => false,
                    'http_errors' => false,
                ]);
                $status = $response->getStatusCode();
                if ($status >= 200 && $status < 300) {
                    $receipt = json_decode((string) $response->getBody(), true);
                    if (($receipt['status'] ?? null) !== 'persisted' || ($receipt['event_id'] ?? null) !== $event->id()) {
                        throw new AuditDeliveryException('Invalid audit persistence receipt', $event->id(), true);
                    }

                    return $receipt;
                }
                $retryAfter = $response->getHeaderLine('Retry-After');
                if ($retryAfter !== '') {
                    $seconds = is_numeric($retryAfter) ? (float) $retryAfter : max(0, (strtotime($retryAfter) ?: time()) - time());
                    $delay = max($delay, min(30000, $seconds * 1000));
                }
                throw new AuditDeliveryException("Audit request failed ({$status})", $event->id(), $status >= 500 || in_array($status, [401, 403, 404, 408, 429], true), $status);
            } catch (GuzzleException $exception) {
                $failure = new AuditDeliveryException('Audit request failed or timed out', $event->id(), true);
            } catch (AuditDeliveryException $exception) {
                $failure = $exception;
            }
            // Authentication/routing can recover after configuration or deployment changes.
            // Retain the outbox row, but do not repeat identical requests in this invocation.
            if (! $failure->retryable || $attempt >= $retries || in_array($failure->statusCode, [401, 403, 404], true)) {
                throw $failure;
            }
            if ($deadline !== null && hrtime(true) / 1e9 + $delay / 1000 >= $deadline) {
                throw $failure;
            }
            usleep((int) ($delay * 1000));
        }
    }
}
