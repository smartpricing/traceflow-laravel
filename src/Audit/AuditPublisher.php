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

    private const MAX_BATCH_COUNT = 100;

    private const MAX_BATCH_BYTES = 512 * 1024;

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
        $this->publishBatch([$event]);
    }

    /**
     * Push bounded batches with each event's original partition and transaction ID.
     * On failure, retry the original events: some earlier batches may already be accepted.
     *
     * @param  list<AuditEvent>  $events
     */
    public function publishBatch(array $events): void
    {
        if (($this->config['enabled'] ?? true) === false || $events === []) {
            return;
        }
        $items = array_map(static fn (AuditEvent $event): array => [
            'partition' => self::partitionFor($event),
            'transactionId' => $event->id(),
            'data' => $event->jsonSerialize(),
        ], $events);
        $batch = [];
        $bytes = 0;
        foreach ($items as $item) {
            $size = strlen(json_encode($item, JSON_THROW_ON_ERROR));
            if ($batch !== [] && (count($batch) >= self::MAX_BATCH_COUNT || $bytes + $size > self::MAX_BATCH_BYTES)) {
                $this->push($batch);
                $batch = [];
                $bytes = 0;
            }
            $batch[] = $item;
            $bytes += $size;
        }
        $this->push($batch);
    }

    /** @param list<array{partition: string, transactionId: string, data: object}> $items */
    private function push(array $items): void
    {
        $eventId = $items[0]['transactionId'];
        $settings = $this->settings();
        if ($this->unavailableUntil !== null && microtime(true) < $this->unavailableUntil) {
            throw new AuditDeliveryException('Queen failed a recent audit push; this event was not sent', $eventId, true);
        }
        try {
            $client = $this->client($settings);
        } catch (Throwable $error) {
            throw new AuditDeliveryException($error->getMessage(), $eventId, false);
        }
        try {
            $results = $client->queue((string) ($settings['queue'] ?? 'traceflow.audit'))
                ->partition($items[0]['partition'])
                ->push($items)
                ->execute();
            if (! is_array($results) || count($results) !== count($items)) {
                throw new RuntimeException('Queen returned an incomplete audit push response');
            }
            foreach ($results as $result) {
                if (! in_array($result['status'] ?? null, ['queued', 'duplicate'], true)) {
                    throw new RuntimeException($result['error'] ?? 'Queen did not confirm an audit event');
                }
            }
        } catch (Throwable $error) {
            $cooldown = max(0, (int) ($settings['cooldown_seconds'] ?? 30));
            $this->unavailableUntil = $cooldown > 0 ? microtime(true) + $cooldown : null;

            throw new AuditDeliveryException('Queen did not confirm the audit batch; retry with the original event IDs: '.$error->getMessage(), $eventId, true);
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
        $this->publishBatchAfterCommit([$event], $connection);
    }

    /** @param list<AuditEvent> $events */
    public function publishBatchAfterCommit(array $events, ?ConnectionInterface $connection = null): void
    {
        if ($events === []) {
            return;
        }
        $connection ??= DB::connection();
        $deliver = function () use ($events): void {
            try {
                $this->publishBatch($events);
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
