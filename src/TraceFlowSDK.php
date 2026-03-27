<?php

namespace Smartness\TraceFlow;

use GuzzleHttp\Client;
use Ramsey\Uuid\Uuid;
use Smartness\TraceFlow\DTO\TraceEvent;
use Smartness\TraceFlow\Enums\TraceEventType;
use Smartness\TraceFlow\Handles\StepHandle;
use Smartness\TraceFlow\Handles\TraceHandle;
use Smartness\TraceFlow\Context\TraceFlowContext;
use Smartness\TraceFlow\Transport\AsyncHttpTransport;
use Smartness\TraceFlow\Transport\HttpTransport;
use Smartness\TraceFlow\Transport\TransportInterface;

class TraceFlowSDK
{
    private const UUID_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    private static function ensureValidUuid(?string $value, string $fieldName): string
    {
        if ($value === null || ! preg_match(self::UUID_REGEX, $value)) {
            $replacement = Uuid::uuid4()->toString();
            if ($value !== null) {
                error_log("[TraceFlow] Invalid UUID for \"{$fieldName}\": \"{$value}\" — replaced with \"{$replacement}\"");
            }
            return $replacement;
        }
        return $value;
    }

    private TransportInterface $transport;

    private string $source;

    private ?string $endpoint;

    private bool $silentErrors;

    private ?string $apiKey;

    private float $timeout;

    /** @var array<string, TraceHandle> */
    private array $activeTraces = [];

    public function __construct(array $config)
    {
        if (empty($config['source'])) {
            throw new \InvalidArgumentException('TraceFlow: "source" config key is required');
        }

        $this->source = $config['source'];
        $this->endpoint = $config['endpoint'] ?? null;
        $this->silentErrors = $config['silent_errors'] ?? true;
        $this->apiKey = $config['api_key'] ?? null;
        $this->timeout = (float) ($config['timeout'] ?? 5.0);

        $transportType = $config['transport'] ?? 'http';

        if ($transportType === 'http') {
            $useAsync = $config['async_http'] ?? true;

            if ($useAsync) {
                $this->transport = new AsyncHttpTransport($config);
            } else {
                $this->transport = new HttpTransport($config);
            }
        } elseif ($transportType === 'kafka') {
            throw new \RuntimeException('Kafka transport not yet implemented for PHP');
        } else {
            throw new \RuntimeException("Unknown transport type: {$transportType}");
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
        $traceId = self::ensureValidUuid($traceId, 'trace_id');

        $event = new TraceEvent(
            eventId: Uuid::uuid4()->toString(),
            eventType: TraceEventType::TRACE_STARTED,
            traceId: $traceId,
            timestamp: now('UTC')->format('Y-m-d\TH:i:s.v\Z'),
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
            ], fn ($value) => $value !== null),
        );

        $this->sendEvent($event);
        TraceFlowContext::set($traceId);

        $handle = new TraceHandle(
            traceId: $traceId,
            source: $this->source,
            sendEvent: $this->sendEvent(...),
            ownsLifecycle: true,
            onClose: function () use ($traceId) {
                unset($this->activeTraces[$traceId]);
            },
            flushEvents: fn () => $this->transport->flush(),
        );

        $this->activeTraces[$traceId] = $handle;

        return $handle;
    }

    /**
     * Get existing trace by ID (makes HTTP call)
     */
    public function getTrace(string $traceId): TraceHandle
    {
        $traceId = self::ensureValidUuid($traceId, 'trace_id');

        if (! $this->endpoint) {
            error_log('[TraceFlow] getTrace() requires HTTP transport with endpoint');

            return new TraceHandle(
                traceId: $traceId,
                source: $this->source,
                sendEvent: $this->sendEvent(...),
                flushEvents: fn () => $this->transport->flush(),
            );
        }

        try {
            $this->makeHttpClient()->get("/api/v1/traces/{$traceId}/state");
            TraceFlowContext::set($traceId);
        } catch (\Exception $e) {
            if (! $this->silentErrors) {
                throw $e;
            }
            error_log("[TraceFlow] Error getting trace (silenced): {$e->getMessage()}");
        }

        return new TraceHandle(
            traceId: $traceId,
            source: $this->source,
            sendEvent: $this->sendEvent(...),
            flushEvents: fn () => $this->transport->flush(),
        );
    }

    /**
     * Get current trace (from context)
     */
    public function getCurrentTrace(): ?TraceHandle
    {
        $traceId = TraceFlowContext::currentTraceId();

        if (! $traceId) {
            return null;
        }

        return new TraceHandle(
            traceId: $traceId,
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
            $trace->finish($result);

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
        $targetTraceId = $traceId !== null
            ? self::ensureValidUuid($traceId, 'trace_id')
            : TraceFlowContext::currentTraceId();

        if (! $targetTraceId || ! $this->endpoint) {
            return;
        }

        try {
            $this->makeHttpClient()->post("/api/v1/traces/{$targetTraceId}/heartbeat");
        } catch (\Exception $e) {
            if ($this->silentErrors) {
                error_log("[TraceFlow] Heartbeat error: {$e->getMessage()}");
            } else {
                throw $e;
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
        $traceId = TraceFlowContext::currentTraceId();

        if (! $traceId) {
            error_log('[TraceFlow] No active trace context for step');

            return null;
        }

        // Prefer the owned handle so the step is tracked for orphan cleanup on shutdown.
        // Fall back to a floating handle for traces started externally (e.g. via getTrace).
        $trace = $this->activeTraces[$traceId] ?? $this->getCurrentTrace();

        return $trace->startStep($name, $stepType, $input, $metadata);
    }

    /**
     * Log message (uses current trace context if available)
     */
    public function log(string $message, string $level = 'INFO', ?string $eventType = null, mixed $details = null): void
    {
        $trace = $this->getCurrentTrace();

        if (! $trace) {
            error_log("[TraceFlow] {$message}");

            return;
        }

        $trace->log($message, $level, $eventType, $details);
    }

    /**
     * Flush pending events
     */
    public function flush(): void
    {
        $this->transport->flush();
    }

    /**
     * Shutdown SDK — close all active handles, then flush transport.
     */
    public function shutdown(): void
    {
        $this->closeAllActive();
        $this->transport->shutdown();
    }

    /**
     * Close all active traces that were not explicitly closed.
     * Each trace cascades to close its own orphaned steps via closeOrphanedSteps().
     */
    private function closeAllActive(): void
    {
        foreach ($this->activeTraces as $trace) {
            if (! $trace->isClosed()) {
                try {
                    $trace->fail('Process shutting down');
                } catch (\Throwable) {}
            }
        }

        $this->activeTraces = [];
    }

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

    private function makeHttpClient(): Client
    {
        $headers = ['Content-Type' => 'application/json'];

        if ($this->apiKey) {
            $headers['X-API-Key'] = $this->apiKey;
        }

        return new Client([
            'base_uri' => $this->endpoint,
            'headers' => $headers,
            'timeout' => $this->timeout,
        ]);
    }
}
