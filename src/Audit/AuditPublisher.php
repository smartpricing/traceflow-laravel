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
 * (traceflow.audit.queen). Single events hash their subject into 64 partitions; bulk calls put
 * bounded groups of snapshots in one message, with stable IDs for retries.
 */
final class AuditPublisher
{
    private const MAX_BATCH_COUNT = 100;

    private const MAX_BATCH_BYTES = 512 * 1024;

    /** @var object{client: ?object, unavailableUntil: ?float} Shared by scoped publishers. */
    private object $delivery;

    private bool $checkHistory = false;

    /**
     * @param  array<string, mixed>  $config  the traceflow configuration
     * @param  (Closure(array<string, mixed>): object)|null  $clientFactory  builds the Queen client
     */
    public function __construct(private readonly array $config, private readonly ?Closure $clientFactory = null)
    {
        $this->delivery = (object) ['client' => null, 'unavailableUntil' => null];
    }

    /** Check archived event IDs for this publisher's sends without changing the original. */
    public function checkHistory(bool $enabled = true): self
    {
        $publisher = clone $this;
        $publisher->checkHistory = $enabled;

        return $publisher;
    }

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
     * Push bounded envelopes, each containing up to 100 original events.
     * On failure, retry the original events: some earlier batches may already be accepted.
     *
     * @param  list<AuditEvent>  $events
     */
    public function publishBatch(array $events): void
    {
        if (($this->config['enabled'] ?? true) === false || $events === []) {
            return;
        }
        $checkHistory = $this->checkHistory;
        $events = array_values($events);
        if (count($events) === 1) {
            $event = $events[0];
            $data = $event->jsonSerialize();
            if ($checkHistory) {
                $data->check_history = true;
            }
            $this->push([['partition' => self::partitionFor($event), 'transactionId' => $event->id(), 'data' => $data]], $event->id());

            return;
        }
        // Keep an operation's chunks ordered without creating a partition per subject.
        $key = $events[0]->jsonSerialize()->batch_id ?? $events[0]->id();
        $partition = sprintf('audit:%02x', hexdec(substr(hash('sha256', $key), 0, 2)) & 63);
        $batch = [];
        $overhead = strlen(json_encode(['schema_version' => 1, 'kind' => 'audit_batch', 'events' => [], ...($checkHistory ? ['check_history' => true] : [])], JSON_THROW_ON_ERROR));
        $bytes = $overhead;
        foreach ($events as $event) {
            $item = $event->jsonSerialize();
            $size = strlen(json_encode($item, JSON_THROW_ON_ERROR)) + 1;
            if ($batch !== [] && (count($batch) >= self::MAX_BATCH_COUNT || $bytes + $size > self::MAX_BATCH_BYTES)) {
                $this->pushEnvelope($batch, $partition, $checkHistory);
                $batch = [];
                $bytes = $overhead;
            }
            $batch[] = $item;
            $bytes += $size;
        }
        $this->pushEnvelope($batch, $partition, $checkHistory);
    }

    /** @param list<object> $events */
    private function pushEnvelope(array $events, string $partition, bool $checkHistory): void
    {
        $data = (object) ['schema_version' => 1, 'kind' => 'audit_batch', 'events' => $events];
        $id = 'audit-batch:'.hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
        if ($checkHistory) {
            $data->check_history = true;
        }
        $this->push([['partition' => $partition, 'transactionId' => $id, 'data' => $data]], $events[0]->event_id);
    }

    /** @param list<array{partition: string, transactionId: string, data: object}> $items */
    private function push(array $items, string $eventId): void
    {
        $settings = $this->settings();
        if ($this->delivery->unavailableUntil !== null && microtime(true) < $this->delivery->unavailableUntil) {
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
            $this->delivery->unavailableUntil = $cooldown > 0 ? microtime(true) + $cooldown : null;

            throw new AuditDeliveryException('Queen did not confirm the audit batch; retry with the original event IDs: '.$error->getMessage(), $eventId, true);
        }
        $this->delivery->unavailableUntil = null;
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

    /** Fixed transport shards; subjects remain searchable in Traceflow's history indexes. */
    public static function partitionFor(AuditEvent $event): string
    {
        $subject = $event->jsonSerialize()->subject ?? null;
        $key = $event->jsonSerialize()->source.':'.(is_object($subject) ? $subject->type.':'.$subject->id : 'Default');

        return sprintf('audit:%02x', hexdec(substr(hash('sha256', $key), 0, 2)) & 63);
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
        if ($this->delivery->client !== null) {
            return $this->delivery->client;
        }
        if ($this->clientFactory !== null) {
            return $this->delivery->client = ($this->clientFactory)($settings);
        }
        if (! class_exists(Queen::class)) {
            throw new RuntimeException('Audit delivery requires the queen-mq/php-client package');
        }
        $urls = array_values(array_filter(array_map('trim', explode(',', (string) ($settings['url'] ?? '')))));
        if ($urls === []) {
            throw new RuntimeException('Audit delivery requires TRACEFLOW_AUDIT_QUEEN_URL or QUEEN_URL');
        }

        return $this->delivery->client = new Queen([
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
