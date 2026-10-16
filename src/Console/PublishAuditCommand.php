<?php

namespace Smartness\TraceFlow\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Smartness\TraceFlow\Audit\AuditClient;
use Smartness\TraceFlow\Audit\AuditDeliveryException;
use Smartness\TraceFlow\Audit\AuditEvent;

final class PublishAuditCommand extends Command
{
    protected $signature = 'traceflow:audit-publish {--connection=} {--limit=100} {--max-seconds=30} {--json}';

    protected $description = 'Deliver committed audit envelopes from the transactional outbox';

    public function handle(AuditClient $client): int
    {
        $deadline = hrtime(true) / 1e9 + min(300, max(1, (int) $this->option('max-seconds')));
        $stats = ['published' => 0, 'retry_scheduled' => 0, 'failed' => 0, 'budget_exhausted' => false];
        $db = DB::connection($this->option('connection') ?: null);
        $rows = $db->table('traceflow_audit_outbox')->whereNull('failed_at')->where('available_at', '<=', now())
            ->orderBy('created_at')->limit(min(1000, max(1, (int) $this->option('limit'))))->get();
        $failed = false;
        foreach ($rows as $row) {
            $remaining = $deadline - hrtime(true) / 1e9;
            if ($remaining <= 0) {
                $stats['budget_exhausted'] = true;
                break;
            }
            try {
                $client->record(AuditEvent::fromJson($row->payload), $remaining);
                // Concurrent publishers are safe: identical event IDs are idempotent in Traceflow.
                $db->table('traceflow_audit_outbox')->where('event_id', $row->event_id)->delete();
                $stats['published']++;
            } catch (\Throwable $error) {
                $failed = true;
                $permanent = $error instanceof AuditDeliveryException && ! $error->retryable || $error instanceof \InvalidArgumentException || $error instanceof \JsonException;
                $db->table('traceflow_audit_outbox')->where('event_id', $row->event_id)->update([
                    'attempts' => $row->attempts + 1,
                    'available_at' => now()->addSeconds(min(3600, 2 ** min(12, $row->attempts + 1))),
                    'failed_at' => $permanent ? now() : null,
                    'last_error' => $error instanceof AuditDeliveryException ? $error->getMessage() : 'Audit publishing failed; inspect the envelope and publisher configuration',
                ]);
                $stats[$permanent ? 'failed' : 'retry_scheduled']++;
                if (! $this->option('json')) {
                    $this->error("Audit {$row->event_id}: ".($permanent ? 'requires attention' : 'scheduled for retry'));
                }
                // A service outage must not trigger retries for every row in the batch.
                if (! $permanent) {
                    break;
                }
            }
        }

        if ($this->option('json')) {
            $this->line(json_encode($stats, JSON_THROW_ON_ERROR));
        }

        return $failed || $stats['budget_exhausted'] ? self::FAILURE : self::SUCCESS;
    }
}
