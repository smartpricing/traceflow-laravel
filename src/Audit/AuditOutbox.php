<?php

namespace Smartness\TraceFlow\Audit;

use Illuminate\Database\ConnectionInterface;
use LogicException;

final class AuditOutbox
{
    public static function requireTransaction(ConnectionInterface $connection): void
    {
        if ($connection->transactionLevel() < 1) {
            throw new LogicException('Audit requires an explicit transaction on the subject/outbox connection');
        }
    }

    public function record(AuditEvent $event, ConnectionInterface $connection): void
    {
        self::requireTransaction($connection);
        $connection->table('traceflow_audit_outbox')->insert([
            'event_id' => $event->id(), 'payload' => $event->toJson(),
            'created_at' => now(), 'available_at' => now(), 'attempts' => 0,
        ]);
    }
}
