<?php

namespace Smartness\TraceFlow\Audit;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Queen\Queen;
use RuntimeException;
use Throwable;

/**
 * Delivers audit events to the Traceflow worker through a Queen MQ queue
 * (traceflow.audit.queen). Each subject has its own partition, so its events
 * are stored in order; the event ID is the transaction ID, so a repeated push
 * is stored once.
 */
final class AuditPublisher
{
    /** Queue and partition names share a budget of 411 bytes on the broker. */
    private const MAX_PARTITION_BYTES = 200;

    private ?object $client = null;

    /** Until when pushes fail at once, after a failure (traceflow.audit.queen.cooldown_seconds). */
    private ?float $unavailableUntil = null;

    /**
     * @param  array<string, mixed>  $config  the traceflow configuration
     * @param  (Closure(array<string, mixed>): object)|null  $clientFactory  builds the Queen client
     */
    public function __construct(private readonly array $config, private readonly ?Closure $clientFactory = null) {}

    /**
     * Push the event now. Throws AuditDeliveryException when Queen does not accept it.
     * Does nothing while Traceflow is disabled (TRACEFLOW_ENABLED=false).
     *
     * After a failure, pushes from this process fail at once for cooldown_seconds, so an
     * outage does not hold every request or job item for the push timeout.
     */
    public function publish(AuditEvent $event): void
    {
        if (($this->config['enabled'] ?? true) === false) {
            return;
        }
        $settings = $this->settings();
        if ($this->unavailableUntil !== null && microtime(true) < $this->unavailableUntil) {
            throw new AuditDeliveryException('Queen failed a recent audit push; this event was not sent', $event->id(), true);
        }
        try {
            $client = $this->client($settings);
        } catch (Throwable $error) {
            throw new AuditDeliveryException($error->getMessage(), $event->id(), false);
        }
        try {
            $client->queue((string) ($settings['queue'] ?? 'traceflow.audit'))
                ->partition(self::partitionFor($event))
                ->push([['transactionId' => $event->id(), 'data' => $event->jsonSerialize()]])
                ->execute();
        } catch (Throwable $error) {
            $cooldown = max(0, (int) ($settings['cooldown_seconds'] ?? 30));
            $this->unavailableUntil = $cooldown > 0 ? microtime(true) + $cooldown : null;

            throw new AuditDeliveryException('Queen did not accept the audit event: '.$error->getMessage(), $event->id(), true);
        }
        $this->unavailableUntil = null;
    }

    /**
     * Push the event once the open transaction on the connection commits, or now
     * without one. A rolled-back transaction publishes nothing. The business write
     * has already committed when the push runs, so a failure is reported to the
     * exception handler instead of thrown: that event is lost.
     */
    public function publishAfterCommit(AuditEvent $event, ?ConnectionInterface $connection = null): void
    {
        $connection ??= DB::connection();
        $deliver = function () use ($event): void {
            try {
                $this->publish($event);
            } catch (Throwable $error) {
                self::report($error);
            }
        };
        if ($connection instanceof Connection && $connection->transactionLevel() > 0) {
            $connection->afterCommit($deliver);

            return;
        }
        $deliver();
    }

    /** One partition per subject; long keys are hashed to fit the broker's name budget. */
    public static function partitionFor(AuditEvent $event): string
    {
        $subject = $event->jsonSerialize()->subject ?? null;
        if (! is_object($subject)) {
            return 'Default';
        }
        $key = $subject->type.':'.$subject->id;

        return strlen($key) <= self::MAX_PARTITION_BYTES ? $key : 'sha256:'.hash('sha256', $key);
    }

    /** @return array<string, mixed> */
    private function settings(): array
    {
        $settings = $this->config['audit']['queen'] ?? [];

        return is_array($settings) ? $settings : [];
    }

    /** @param  array<string, mixed>  $settings */
    private function client(array $settings): object
    {
        if ($this->client !== null) {
            return $this->client;
        }
        if ($this->clientFactory !== null) {
            return $this->client = ($this->clientFactory)($settings);
        }
        if (! class_exists(Queen::class)) {
            throw new RuntimeException('Audit delivery requires the queen-mq/php-client package');
        }
        $urls = array_values(array_filter(array_map('trim', explode(',', (string) ($settings['url'] ?? '')))));
        if ($urls === []) {
            throw new RuntimeException('Audit delivery requires TRACEFLOW_AUDIT_QUEEN_URL or QUEEN_URL');
        }

        return $this->client = new Queen([
            'urls' => $urls,
            'bearerToken' => ($settings['bearer_token'] ?? null) ?: null,
            // A push runs on the request path: fail fast rather than hold the request.
            'timeoutMillis' => max(100, (int) ($settings['timeout_ms'] ?? 2000)),
            'retryAttempts' => max(1, (int) ($settings['retry_attempts'] ?? 1)),
        ]);
    }

    private static function report(Throwable $error): void
    {
        if (function_exists('report')) {
            report($error);

            return;
        }
        error_log('[TraceFlow] '.$error->getMessage());
    }
}
