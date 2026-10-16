<?php

namespace Smartness\TraceFlow\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class RetryAuditCommand extends Command
{
    protected $signature = 'traceflow:audit-retry {event_id} {--connection=}';

    protected $description = 'Requeue one failed audit envelope after its delivery problem has been fixed';

    public function handle(): int
    {
        $changed = DB::connection($this->option('connection') ?: null)->table('traceflow_audit_outbox')
            ->where('event_id', $this->argument('event_id'))->whereNotNull('failed_at')
            ->update(['failed_at' => null, 'available_at' => now(), 'last_error' => null]);
        $this->line($changed ? 'Audit event requeued with its original ID and payload.' : 'Failed audit event not found.');

        return $changed ? self::SUCCESS : self::FAILURE;
    }
}
