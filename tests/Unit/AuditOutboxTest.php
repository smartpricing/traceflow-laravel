<?php

namespace Smartness\TraceFlow\Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use Smartness\TraceFlow\Audit\AuditClient;
use Smartness\TraceFlow\Audit\AuditContext;
use Smartness\TraceFlow\Audit\AuditOutbox;
use Smartness\TraceFlow\Audit\LogsAudit;
use Smartness\TraceFlow\TraceFlowServiceProvider;

class AuditOutboxTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [TraceFlowServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('traceflow.enabled', false);
        $app['config']->set('traceflow.source', 'audit-test');
    }

    protected function setUp(): void
    {
        parent::setUp();
        (require __DIR__.'/../../database/migrations/create_traceflow_audit_outbox.php.stub')->up();
        (require __DIR__.'/../../database/migrations/index_traceflow_audit_outbox.php.stub')->up();
        Schema::create('audit_test_subjects', function (Blueprint $table) {
            $table->id();
            $table->string('status')->nullable();
            $table->string('note')->nullable();
            $table->string('secret')->nullable();
            $table->softDeletes();
        });
    }

    public function test_rollback_removes_both_subject_and_audit(): void
    {
        try {
            DB::transaction(function () {
                AuditTestSubject::create(['status' => 'draft']);
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame(0, AuditTestSubject::count());
        $this->assertSame(0, DB::table('traceflow_audit_outbox')->count());
    }

    public function test_unwrapped_writes_are_rejected_before_the_subject_is_saved(): void
    {
        try {
            AuditTestSubject::create(['status' => 'draft']);
            $this->fail('Expected transaction requirement');
        } catch (\LogicException $error) {
            $this->assertStringContainsString('transaction', $error->getMessage());
        }
        $this->assertSame(0, AuditTestSubject::count());
    }

    public function test_optional_transactions_record_unwrapped_model_writes(): void
    {
        config(['traceflow.audit.require_transaction' => false]);
        $model = AuditTestSubject::create(['status' => 'draft']);
        $model->update(['status' => 'sent']);
        $model->delete();
        $model->restore();
        $events = DB::table('traceflow_audit_outbox')->get()->map(fn ($row) => json_decode($row->payload));
        $actions = $events->pluck('action')->sort()->values()->all();
        $this->assertSame(['created', 'deleted', 'restored', 'updated'], $actions);
        $updated = $events->firstWhere('action', 'updated');
        $this->assertSame('draft', $updated->changes->before->status);
        $this->assertSame('sent', $updated->changes->after->status);
    }

    public function test_optional_transactions_keep_wrapped_writes_atomic(): void
    {
        config(['traceflow.audit.require_transaction' => false]);
        try {
            DB::transaction(function () {
                AuditTestSubject::create(['status' => 'draft']);
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame(0, AuditTestSubject::count());
        $this->assertSame(0, DB::table('traceflow_audit_outbox')->count());
    }

    public function test_manual_records_follow_the_configured_policy(): void
    {
        $event = app(AuditClient::class)->createEvent(['subject' => ['type' => 'quote', 'id' => '1'], 'action' => 'exported']);
        try {
            app(AuditOutbox::class)->record($event, DB::connection());
            $this->fail('Expected transaction requirement');
        } catch (\LogicException) {
        }
        $this->assertSame(0, DB::table('traceflow_audit_outbox')->count());
        config(['traceflow.audit.require_transaction' => false]);
        app(AuditOutbox::class)->record($event, DB::connection());
        $this->assertSame(1, DB::table('traceflow_audit_outbox')->count());
        // The explicit check keeps enforcing a transaction for callers that rely on it.
        $this->expectException(\LogicException::class);
        AuditOutbox::requireTransaction(DB::connection());
    }

    public function test_model_changes_are_frozen_and_only_selected_fields_are_audited(): void
    {
        $model = DB::transaction(fn () => AuditTestSubject::create(['status' => 'draft', 'secret' => 'example-excluded-value']));
        app(AuditContext::class)->run(['actor' => ['type' => 'user', 'id' => '42'], 'origin' => ['channel' => 'mobile_app']], fn () => DB::transaction(fn () => $model->update(['status' => 'sent', 'note' => null])));
        DB::transaction(fn () => $model->update(['secret' => 'example-updated-excluded-value']));
        $events = DB::table('traceflow_audit_outbox')->get()->map(fn ($row) => json_decode($row->payload));
        $this->assertCount(2, $events);
        $updated = $events->first(fn ($event) => $event->action === 'updated');
        $this->assertSame('draft', $updated->changes->before->status);
        $this->assertSame('sent', $updated->changes->after->status);
        $this->assertSame('42', $updated->actor->id);
        $this->assertSame('mobile_app', $updated->origin->channel);
        foreach ($events as $event) {
            $this->assertStringNotContainsString('secret', json_encode($event));
        }
        $this->assertSame([], app(AuditContext::class)->values());
        DB::transaction(fn () => $model->delete());
        DB::transaction(fn () => $model->restore());
        $actions = DB::table('traceflow_audit_outbox')->get()->map(fn ($row) => json_decode($row->payload)->action)->all();
        sort($actions);
        $this->assertSame(['created', 'deleted', 'restored', 'updated'], $actions);
    }

    public function test_publisher_keeps_failed_delivery_then_deletes_only_after_persistence(): void
    {
        DB::transaction(fn () => AuditTestSubject::create(['status' => 'draft']));
        $id = DB::table('traceflow_audit_outbox')->value('event_id');
        $mock = new MockHandler([new Response(503), new Response(201, [], json_encode(['status' => 'persisted', 'event_id' => $id]))]);
        $client = new AuditClient(['source' => 'audit-test', 'endpoint' => 'http://audit.test', 'api_key' => 'key', 'max_retries' => 0], new Client(['handler' => HandlerStack::create($mock)]));
        $this->app->instance(AuditClient::class, $client);
        $this->artisan('traceflow:audit-publish')->assertExitCode(1);
        $this->assertSame(1, DB::table('traceflow_audit_outbox')->count());
        $this->assertSame(1, DB::table('traceflow_audit_outbox')->value('attempts'));
        DB::table('traceflow_audit_outbox')->update(['available_at' => now()->subMinute()]);
        $this->artisan('traceflow:audit-publish')->assertExitCode(0);
        $this->assertSame(0, DB::table('traceflow_audit_outbox')->count());
    }

    public function test_outage_stops_the_batch_without_discarding_other_events(): void
    {
        DB::transaction(function () {
            for ($i = 0; $i < 3; $i++) {
                AuditTestSubject::create(['status' => 'draft']);
            }
        });
        $mock = new MockHandler([new Response(503)]);
        $this->app->instance(AuditClient::class, new AuditClient(['source' => 'audit-test', 'endpoint' => 'http://audit.test', 'api_key' => 'key', 'max_retries' => 0], new Client(['handler' => HandlerStack::create($mock)])));
        $this->artisan('traceflow:audit-publish', ['--json' => true])->assertExitCode(1);
        $this->assertSame(3, DB::table('traceflow_audit_outbox')->count());
        $this->assertSame(1, (int) DB::table('traceflow_audit_outbox')->sum('attempts'));
        $this->assertSame(0, DB::table('traceflow_audit_outbox')->whereNotNull('failed_at')->count());
    }

    public function test_status_detects_backlog_and_failed_events_can_be_requeued_without_mutation(): void
    {
        $this->artisan('traceflow:audit-status')->assertExitCode(0);
        DB::transaction(fn () => AuditTestSubject::create(['status' => 'draft']));
        $row = DB::table('traceflow_audit_outbox')->first();
        DB::table('traceflow_audit_outbox')->update(['created_at' => now()->subMinutes(10)]);
        $this->artisan('traceflow:audit-status', ['--max-age' => 300])->assertExitCode(1);
        $this->artisan('traceflow:audit-status', ['--max-age' => 1000])->assertExitCode(0);
        $this->artisan('traceflow:audit-status', ['--max-age' => 1000, '--max-pending' => 0])->assertExitCode(1);
        DB::table('traceflow_audit_outbox')->update(['failed_at' => now(), 'attempts' => 2]);
        $this->artisan('traceflow:audit-status')->assertExitCode(1);
        $this->artisan('traceflow:audit-retry', ['event_id' => $row->event_id])->assertExitCode(0);
        $retry = DB::table('traceflow_audit_outbox')->first();
        $this->assertNull($retry->failed_at);
        $this->assertSame($row->payload, $retry->payload);
        $this->assertSame(2, $retry->attempts);
        $this->artisan('traceflow:audit-retry', ['event_id' => $row->event_id])->assertExitCode(1);
    }

    public function test_permanent_failure_does_not_block_valid_events_and_can_be_recovered(): void
    {
        DB::transaction(function () {
            AuditTestSubject::create(['status' => 'invalid']);
            AuditTestSubject::create(['status' => 'valid']);
        });
        $rows = DB::table('traceflow_audit_outbox')->orderBy('created_at')->get();
        $mock = new MockHandler([new Response(400), new Response(201, [], json_encode(['status' => 'persisted', 'event_id' => $rows[1]->event_id]))]);
        $this->app->instance(AuditClient::class, new AuditClient(['source' => 'audit-test', 'endpoint' => 'http://audit.test', 'api_key' => 'key', 'max_retries' => 0], new Client(['handler' => HandlerStack::create($mock)])));
        $this->artisan('traceflow:audit-publish')->assertExitCode(1);
        $this->assertSame(1, DB::table('traceflow_audit_outbox')->count());
        $this->assertNotNull(DB::table('traceflow_audit_outbox')->value('failed_at'));
    }
}

class AuditTestSubject extends Model
{
    use LogsAudit, SoftDeletes;

    protected $table = 'audit_test_subjects';

    protected $guarded = [];

    public $timestamps = false;

    public function auditFields(): array
    {
        return ['status', 'note'];
    }

    public function auditType(): string
    {
        return 'custom-subject';
    }
}
