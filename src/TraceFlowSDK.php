<?php

namespace Smartpricing\TraceFlow;

use Ramsey\Uuid\Uuid;
use Smartpricing\TraceFlow\DTO\TraceEvent;
use Smartpricing\TraceFlow\Enums\TraceEventType;
use Smartpricing\TraceFlow\Handles\TraceHandle;
use Smartpricing\TraceFlow\Handles\StepHandle;
use Smartpricing\TraceFlow\Transport\TransportInterface;
use Smartpricing\TraceFlow\Transport\HttpTransport;
use GuzzleHttp\Client;

class TraceFlowSDK
{
    private TransportInterface $transport;
    private string $source;
    private ?string $endpoint;
    private bool $silentErrors;
    private array $activeTraces = [];
    private ?string $currentTraceId = null; // Simple context storage

    public function __construct(array $config)
    {
        $this->source = $config['source'];
        $this->endpoint = $config['endpoint'] ?? null;
        $this->silentErrors = $config['silent_errors'] ?? true;

        // Initialize transport
        if (($config['transport'] ?? 'http') === 'http') {
            $this->transport = new HttpTransport($config);
        } else {
            throw new \RuntimeException('Kafka transport not yet implemented for PHP');
        }
    }

    /**
     * Start a new trace
     */
    public function startTrace(
        ?string $traceId = null,
        ?string $traceType = null,
        ?string $title = null,
        ?string $description = null,
        ?string $owner = null,
        ?array $tags = null,
        ?array $metadata = null,
        mixed $params = null,
        ?int $traceTimeoutMs = null,
        ?int $stepTimeoutMs = null,
    ): TraceHandle {
        $traceId = $traceId ?? Uuid::uuid4()->toString();

        // Create trace started event
        $event = new TraceEvent(
            eventId: Uuid::uuid4()->toString(),
            eventType: TraceEventType::TRACE_STARTED,
            traceId: $traceId,
            timestamp: now()->toIso8601String(),
            source: $this->source,
            payload: array_filter([
                'trace_type' => $traceType,
                'title' => $title,
                'description' => $description,
                'owner' => $owner,
                'tags' => $tags,
                'metadata' => $metadata,
                'params' => $params,
                'trace_timeout_ms' => $traceTimeoutMs,
                'step_timeout_ms' => $stepTimeoutMs,
            ]),
        );

        $this->sendEvent($event);

        // Track trace
        $this->activeTraces[$traceId] = true;
        $this->currentTraceId = $traceId;

        return new TraceHandle(
            traceId: $traceId,
            source: $this->source,
            sendEvent: $this->sendEvent(...),
        );
    }

    /**
     * Get existing trace by ID (makes HTTP call)
     */
    public function getTrace(string $traceId): TraceHandle
    {
        if (!$this->endpoint) {
            error_log('[TraceFlow] getTrace() requires HTTP transport with endpoint');
            return new TraceHandle($traceId, $this->source, $this->sendEvent(...));
        }

        try {
            $client = new Client(['base_uri' => $this->endpoint]);
            $response = $client->get("/api/v1/traces/{$traceId}/state");
            
            // Update context
            $this->currentTraceId = $traceId;

            error_log("[TraceFlow] Retrieved trace: {$traceId}");
        } catch (\Exception $e) {
            if (!$this->silentErrors) {
                throw $e;
            }
            error_log("[TraceFlow] Error getting trace (silenced): {$e->getMessage()}");
        }

        return new TraceHandle(
            traceId: $traceId,
            source: $this->source,
            sendEvent: $this->sendEvent(...),
        );
    }

    /**
     * Get current trace (from context)
     */
    public function getCurrentTrace(): ?TraceHandle
    {
        if (!$this->currentTraceId) {
            return null;
        }

        return new TraceHandle(
            traceId: $this->currentTraceId,
            source: $this->source,
            sendEvent: $this->sendEvent(...),
        );
    }

    /**
     * Run callback with trace context
     */
    public function runWithTrace(callable $callback, array $traceOptions = []): mixed
    {
        $trace = $this->startTrace(...$traceOptions);

        try {
            $result = $callback($trace);
            $trace->finish(['result' => $result]);
            return $result;
        } catch (\Throwable $e) {
            $trace->fail($e);
            throw $e;
        }
    }

    /**
     * Send heartbeat for trace
     */
    public function heartbeat(?string $traceId = null): void
    {
        $targetTraceId = $traceId ?? $this->currentTraceId;

        if (!$targetTraceId || !$this->endpoint) {
            return;
        }

        try {
            $client = new Client(['base_uri' => $this->endpoint]);
            $client->post("/api/v1/traces/{$targetTraceId}/heartbeat");
            error_log("[TraceFlow] Heartbeat sent for: {$targetTraceId}");
        } catch (\Exception $e) {
            if (!$this->silentErrors) {
                error_log("[TraceFlow] Heartbeat error: {$e->getMessage()}");
            }
        }
    }

    /**
     * Start a step (requires active trace context)
     */
    public function startStep(
        ?string $name = null,
        ?string $stepType = null,
        mixed $input = null,
        ?array $metadata = null
    ): ?StepHandle {
        $trace = $this->getCurrentTrace();

        if (!$trace) {
            error_log('[TraceFlow] No active trace context for step');
            return null;
        }

        return $trace->startStep($name, $stepType, $input, $metadata);
    }

    /**
     * Log message (uses current trace context if available)
     */
    public function log(string $message, string $level = 'INFO', ?string $eventType = null, mixed $details = null): void
    {
        $trace = $this->getCurrentTrace();

        if (!$trace) {
            error_log("[TraceFlow] {$message}");
            return;
        }

        $trace->log($message, $level, $eventType, $details);
    }

    /**
     * Send event through transport
     */
    private function sendEvent(TraceEvent $event): void
    {
        try {
            $this->transport->send($event);
        } catch (\Exception $e) {
            if ($this->silentErrors) {
                error_log("[TraceFlow] Error sending event (silenced): {$e->getMessage()}");
            } else {
                throw $e;
            }
        }
    }

    /**
     * Flush pending events
     */
    public function flush(): void
    {
        $this->transport->flush();
    }

    /**
     * Shutdown SDK
     */
    public function shutdown(): void
    {
        error_log('[TraceFlow] Shutting down SDK...');
        $this->transport->shutdown();
    }
}

