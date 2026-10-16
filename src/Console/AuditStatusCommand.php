<?php

namespace Smartness\TraceFlow\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class AuditStatusCommand extends Command
{
    protected $signature = 'traceflow:audit-status {--connection=} {--max-age=300} {--max-pending=10000}';

    protected $description = 'Report audit backlog as JSON; exit nonzero for failed or overdue deliveries';

    public function handle(): int
    {
        $table = DB::connection($this->option('connection') ?: null)->table('traceflow_audit_outbox');
        $pending = (clone $table)->whereNull('failed_at');
        $oldest = (clone $pending)->min('created_at');
        $age = $oldest === null ? 0 : max(0, now()->getTimestamp() - Carbon::parse($oldest)->getTimestamp());
        $result = [
            'pending' => (clone $pending)->count(),
            'ready' => (clone $pending)->where('available_at', '<=', now())->count(),
            'failed' => (clone $table)->whereNotNull('failed_at')->count(),
            'oldest_pending_seconds' => $age,
        ];
        $result['healthy'] = $result['failed'] === 0
            && $age <= max(0, (int) $this->option('max-age'))
            && $result['pending'] <= max(0, (int) $this->option('max-pending'));
        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        return $result['healthy'] ? self::SUCCESS : self::FAILURE;
    }
}
