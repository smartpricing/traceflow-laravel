<?php

namespace Smartness\TraceFlow\Audit;

use Illuminate\Database\ConnectionInterface;
use LogicException;

final class AuditOutbox
{
    /** Always enforces an open transaction, whatever the configuration. */
    public static function requireTransaction(ConnectionInterface $connection): void
    {
        if ($connection->transactionLevel() < 1) {
            throw new LogicException('Audit requires an explicit transaction on the subject/outbox connection');
        }
    }

    /** Whether audited writes must run inside a transaction (traceflow.audit.require_transaction). */
    public static function transactionRequired(): bool
    {
        return (bool) config('traceflow.audit.require_transaction', true);
    }

    /** Enforces the configured policy. Without the requirement, an open transaction is still joined. */
    public static function guardTransaction(ConnectionInterface $connection): void
    {
        if (self::transactionRequired()) {
            self::requireTransaction($connection);
        }
    }

    public function record(AuditEvent $event, ConnectionInterface $connection): void
    {
        self::guardTransaction($connection);
        $connection->table('traceflow_audit_outbox')->insert([
            'event_id' => $event->id(), 'payload' => $event->toJson(),
            'created_at' => now(), 'available_at' => now(), 'attempts' => 0,
        ]);
    }
}
