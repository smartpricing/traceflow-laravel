<?php

namespace Smartness\TraceFlow\Tests\Unit;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use Smartness\TraceFlow\Audit\AuditClient;
use Smartness\TraceFlow\Audit\AuditContext;
use Smartness\TraceFlow\Audit\AuditDeliveryException;
use Smartness\TraceFlow\Audit\AuditEvent;
use Smartness\TraceFlow\Audit\AuditPublisher;
use Smartness\TraceFlow\Audit\LogsAudit;
use Smartness\TraceFlow\TraceFlowServiceProvider;
use Throwable;

class AuditPublisherTest extends TestCase
{
    private FakeQueen $queen;

    /** @var list<Throwable> */
    private array $reported = [];

    protected function getPackageProviders($app): array
    {
        return [TraceFlowServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('traceflow.enabled', true);
        $app['config']->set('traceflow.source', 'audit-test');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->queen = new FakeQueen;
        // No cooldown here: test_after_a_failure_pushes_fail_at_once_until_the_cooldown_ends covers it.
        $config = config('traceflow');
        $config['audit']['queen']['cooldown_seconds'] = 0;
        $this->app->instance(AuditPublisher::class, new AuditPublisher($config, fn () => $this->queen));
        $handler = $this->app->make(ExceptionHandler::class);
        $this->app->instance(ExceptionHandler::class, new class($handler, $this->reported) implements ExceptionHandler
        {
            public function __construct(private ExceptionHandler $inner, private array &$reported) {}

            public function report(Throwable $e)
            {
                $this->reported[] = $e;
            }

            public function shouldReport(Throwable $e)
            {
                return true;
            }

            public function render($request, Throwable $e)
            {
                return $this->inner->render($request, $e);
            }

            public function renderForConsole($output, Throwable $e)
            {
                $this->inner->renderForConsole($output, $e);
            }
        });
        Schema::create('audit_test_subjects', function (Blueprint $table) {
            $table->id();
            $table->string('status')->nullable();
            $table->string('note')->nullable();
            $table->string('secret')->nullable();
            $table->softDeletes();
        });
    }

    public function test_each_model_change_is_pushed_to_the_subject_partition_with_its_event_id(): void
    {
        $model = AuditTestSubject::create(['status' => 'draft']);
        $model->update(['status' => 'sent']);
        $model->delete();
        $model->restore();

        $this->assertSame(['created', 'updated', 'deleted', 'restored'], array_map(fn ($push) => $push['data']->action, $this->queen->pushes));
        foreach ($this->queen->pushes as $push) {
            $this->assertSame('traceflow.audit', $push['queue']);
            $this->assertSame('custom-subject:'.$model->id, $push['partition']);
            $this->assertSame($push['data']->event_id, $push['transactionId']);
            $this->assertSame('audit-test', $push['data']->source);
        }
        // Empty images stay JSON objects on the wire, as the backend contract requires.
        $this->assertStringContainsString('"before":{}', $this->queen->pushes[0]['json']);
        $this->assertStringContainsString('"after":{}', $this->queen->pushes[2]['json']);
        $updated = $this->queen->pushes[1]['data'];
        $this->assertSame('draft', $updated->changes->before->status);
        $this->assertSame('sent', $updated->changes->after->status);
    }

    public function test_a_transaction_publishes_after_the_commit_and_a_rollback_publishes_nothing(): void
    {
        DB::transaction(function () {
            AuditTestSubject::create(['status' => 'draft']);
            DB::transaction(fn () => AuditTestSubject::create(['status' => 'nested']));
            $this->assertSame([], $this->queen->pushes, 'nothing leaves before the outermost commit');
        });
        $this->assertCount(2, $this->queen->pushes);

        try {
            DB::transaction(function () {
                AuditTestSubject::create(['status' => 'rolled back']);
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
        }
        $this->assertCount(2, $this->queen->pushes);
        $this->assertSame(2, AuditTestSubject::count());
    }

    public function test_a_failed_push_after_the_write_is_reported_and_does_not_undo_the_write(): void
    {
        $this->queen->failure = new \RuntimeException('connect ECONNREFUSED');
        AuditTestSubject::create(['status' => 'draft']);
        DB::transaction(fn () => AuditTestSubject::create(['status' => 'sent']));

        $this->assertSame(2, AuditTestSubject::count());
        $this->assertCount(2, $this->reported);
        $this->assertInstanceOf(AuditDeliveryException::class, $this->reported[0]);
        $this->assertTrue($this->reported[0]->retryable);
        $this->assertStringContainsString('ECONNREFUSED', $this->reported[0]->getMessage());
    }

    public function test_manual_publish_throws_when_queen_refuses_and_does_nothing_while_disabled(): void
    {
        $event = app(AuditClient::class)->createEvent(['subject' => ['type' => 'quote', 'id' => '1'], 'action' => 'exported']);
        $this->queen->failure = new \RuntimeException('Push failed');
        try {
            app(AuditPublisher::class)->publish($event);
            $this->fail('Expected a delivery failure');
        } catch (AuditDeliveryException $error) {
            $this->assertSame($event->id(), $error->eventId);
        }

        $this->queen->failure = null;
        (new AuditPublisher(array_merge(config('traceflow'), ['enabled' => false]), fn () => $this->queen))->publish($event);
        $this->assertSame([], $this->queen->pushes);
        app(AuditPublisher::class)->publish($event);
        $this->assertSame('quote:1', $this->queen->pushes[0]['partition']);
    }

    public function test_after_a_failure_pushes_fail_at_once_until_the_cooldown_ends(): void
    {
        $event = fn () => AuditEvent::create('audit-test', ['action' => 'exported']);
        $publisher = new AuditPublisher(config('traceflow'), fn () => $this->queen);
        $this->queen->failure = new \RuntimeException('Operation timed out');
        $this->expectDeliveryFailure($publisher, $event(), 'timed out');
        $this->queen->failure = null;
        $this->expectDeliveryFailure($publisher, $event(), 'recent audit push');
        $this->assertSame(1, $this->queen->calls, 'the broker is not called during the cooldown');

        $config = config('traceflow');
        $config['audit']['queen']['cooldown_seconds'] = 0;
        $publisher = new AuditPublisher($config, fn () => $this->queen);
        $this->queen->failure = new \RuntimeException('Operation timed out');
        $this->expectDeliveryFailure($publisher, $event(), 'timed out');
        $this->queen->failure = null;
        $publisher->publish($event());
        $this->assertCount(1, $this->queen->pushes);
    }

    private function expectDeliveryFailure(AuditPublisher $publisher, AuditEvent $event, string $reason): void
    {
        try {
            $publisher->publish($event);
            $this->fail('Expected a delivery failure');
        } catch (AuditDeliveryException $error) {
            $this->assertStringContainsString($reason, $error->getMessage());
            $this->assertTrue($error->retryable);
        }
    }

    public function test_a_missing_broker_url_is_a_configuration_error(): void
    {
        $config = config('traceflow');
        $config['audit']['queen']['url'] = '';
        $event = AuditEvent::create('audit-test', ['action' => 'exported']);
        try {
            (new AuditPublisher($config))->publish($event);
            $this->fail('Expected a configuration error');
        } catch (AuditDeliveryException $error) {
            $this->assertFalse($error->retryable);
            $this->assertMatchesRegularExpression('/queen-mq\/php-client|QUEEN_URL/', $error->getMessage());
        }
    }

    public function test_events_without_subject_and_long_subjects_get_valid_partitions(): void
    {
        $this->assertSame('Default', AuditPublisher::partitionFor(AuditEvent::create('test', ['action' => 'exported'])));
        $long = AuditEvent::create('test', ['action' => 'exported', 'subject' => ['type' => str_repeat('t', 150), 'id' => str_repeat('i', 150)]]);
        $partition = AuditPublisher::partitionFor($long);
        $this->assertStringStartsWith('sha256:', $partition);
        $this->assertSame($partition, AuditPublisher::partitionFor($long));
        $this->assertLessThanOrEqual(200, strlen($partition));
    }

    public function test_model_changes_are_frozen_and_only_selected_fields_are_audited(): void
    {
        $model = AuditTestSubject::create(['status' => 'draft', 'secret' => 'example-excluded-value']);
        app(AuditContext::class)->run(['actor' => ['type' => 'user', 'id' => '42'], 'origin' => ['channel' => 'mobile_app']], fn () => DB::transaction(fn () => $model->update(['status' => 'sent', 'note' => null])));
        $model->update(['secret' => 'example-updated-excluded-value']);

        $this->assertCount(2, $this->queen->pushes);
        $updated = $this->queen->pushes[1]['data'];
        $this->assertSame('draft', $updated->changes->before->status);
        $this->assertSame('sent', $updated->changes->after->status);
        $this->assertSame('42', $updated->actor->id);
        $this->assertSame('mobile_app', $updated->origin->channel);
        foreach ($this->queen->pushes as $push) {
            $this->assertStringNotContainsString('secret', $push['json']);
        }
        $this->assertSame([], app(AuditContext::class)->values());
    }
}

/** Records pushes as the Queen PHP client would send them. */
final class FakeQueen
{
    /** @var list<array{queue: string, partition: string, transactionId: string, json: string, data: object}> */
    public array $pushes = [];

    public ?Throwable $failure = null;

    public int $calls = 0;

    public function queue(string $name): object
    {
        $this->calls++;

        return new class($this, $name)
        {
            private string $partition = 'Default';

            public function __construct(private FakeQueen $queen, private string $name) {}

            public function partition(string $partition): static
            {
                $this->partition = $partition;

                return $this;
            }

            public function push(array $items): object
            {
                return new class($this->queen, $this->name, $this->partition, $items)
                {
                    public function __construct(private FakeQueen $queen, private string $name, private string $partition, private array $items) {}

                    public function execute(): array
                    {
                        if ($this->queen->failure !== null) {
                            throw $this->queen->failure;
                        }
                        foreach ($this->items as $item) {
                            $json = json_encode($item['data'], JSON_THROW_ON_ERROR);
                            $this->queen->pushes[] = ['queue' => $this->name, 'partition' => $this->partition, 'transactionId' => $item['transactionId'], 'json' => $json, 'data' => json_decode($json)];
                        }

                        return [['status' => 'queued']];
                    }
                };
            }
        };
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
