<?php

namespace Smartness\TraceFlow\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Smartness\TraceFlow\Audit\AuditClient;
use Smartness\TraceFlow\Audit\AuditDeliveryException;
use Smartness\TraceFlow\Audit\AuditEvent;

final class PublishAuditCommand extends Command
{
    protected $signature = 'traceflow:audit-publish {--connection=} {--limit=100}';

    protected $description = 'Deliver committed audit envelopes from the transactional outbox';

    public function handle(AuditClient $client): int
    {
        $db = DB::connection($this->option('connection') ?: null);
        $rows = $db->table('traceflow_audit_outbox')->whereNull('failed_at')->where('available_at', '<=', now())
            ->orderBy('created_at')->limit(min(1000, max(1, (int) $this->option('limit'))))->get();
        $failed = false;
        foreach ($rows as $row) {
            try {
                $client->record(AuditEvent::fromJson($row->payload));
                // Concurrent publishers are safe: identical event IDs are idempotent in Traceflow.
                $db->table('traceflow_audit_outbox')->where('event_id', $row->event_id)->delete();
            } catch (\Throwable $error) {
                $failed = true;
                $permanent = $error instanceof AuditDeliveryException && ! $error->retryable || $error instanceof \InvalidArgumentException || $error instanceof \JsonException;
                $db->table('traceflow_audit_outbox')->where('event_id', $row->event_id)->update([
                    'attempts' => $row->attempts + 1,
                    'available_at' => now()->addSeconds(min(3600, 2 ** min(12, $row->attempts + 1))),
                    'failed_at' => $permanent ? now() : null,
                    'last_error' => $error instanceof AuditDeliveryException ? $error->getMessage() : 'Audit publishing failed; inspect the envelope and publisher configuration',
                ]);
                $this->error("Audit {$row->event_id}: ".($permanent ? 'requires attention' : 'scheduled for retry'));
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
