<?php

use Composer\Autoload\ClassLoader;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Factory;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Smartness\TraceFlow\Audit\AuditClient;
use Smartness\TraceFlow\Audit\AuditContext;
use Smartness\TraceFlow\Audit\AuditPublisher;
use Smartness\TraceFlow\Audit\LogsAudit;

// Compatibility test using already installed framework classes, without booting
// the host application, loading its .env, or connecting to its databases.
$vendor = realpath($argv[1] ?? '') ?: throw new RuntimeException('Pass an installed vendor directory');
require $vendor.'/composer/ClassLoader.php';
$loader = new ClassLoader;
foreach (require $vendor.'/composer/autoload_psr4.php' as $prefix => $paths) {
    $loader->addPsr4($prefix, $paths);
}
$loader->setPsr4('Smartness\\TraceFlow\\', [__DIR__.'/../src']);
$loader->register();
foreach (require $vendor.'/composer/autoload_files.php' as $file) {
    if (str_contains($file, '/laravel/framework/') || str_contains($file, '/illuminate/')) {
        require_once $file;
    }
}

$app = new Container;
Container::setInstance($app);
$app->instance('config', new Repository);
$app->instance('events', new Dispatcher($app));
$app->instance('auth', new class
{
    public function user()
    {
        return null;
    }
});
$app->alias('auth', Factory::class);
Facade::setFacadeApplication($app);
$app->scoped(AuditContext::class, fn () => new AuditContext);
$app->instance(AuditClient::class, new AuditClient(['source' => 'compatibility-test']));
// Captures pushes instead of contacting a broker.
$pushes = [];
$queen = new class($pushes)
{
    public function __construct(private array &$pushes) {}

    public function queue(string $name): object
    {
        $pushes = &$this->pushes;

        return new class($pushes)
        {
            public function __construct(private array &$pushes) {}

            public function partition(string $partition): static
            {
                return $this;
            }

            public function push(array $items): object
            {
                $pushes = &$this->pushes;

                return new class($pushes, $items)
                {
                    public function __construct(private array &$pushes, private array $items) {}

                    public function execute(): array
                    {
                        foreach ($this->items as $item) {
                            $this->pushes[] = json_decode(json_encode($item['data']));
                        }

                        return [['status' => 'queued']];
                    }
                };
            }
        };
    }
};
$app->instance(AuditPublisher::class, new AuditPublisher(['enabled' => true], fn () => $queen));
$capsule = new Manager($app);
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
$capsule->setAsGlobal();
$capsule->bootEloquent();
$db = $capsule->getConnection();
$db->setTransactionManager(new DatabaseTransactionsManager);
$db->getSchemaBuilder()->create('audit_subjects', function ($table) {
    $table->id();
    $table->string('status');
    $table->softDeletes();
});

class InstalledFrameworkAuditSubject extends Model
{
    use LogsAudit, SoftDeletes;

    protected $table = 'audit_subjects';

    protected $guarded = [];

    public $timestamps = false;

    public function auditFields(): array
    {
        return ['status'];
    }
}

$model = $db->transaction(fn () => InstalledFrameworkAuditSubject::create(['status' => 'draft']));
$db->transaction(fn () => $model->update(['status' => 'sent']));
$db->transaction(fn () => $model->delete());
$db->transaction(fn () => $model->restore());
try {
    $db->transaction(function () {
        InstalledFrameworkAuditSubject::create(['status' => 'rollback']);
        throw new RuntimeException('rollback');
    });
} catch (RuntimeException $error) {
    if ($error->getMessage() !== 'rollback') {
        throw $error;
    }
}
$events = collect($pushes);
if ($events->count() !== 4 || InstalledFrameworkAuditSubject::count() !== 1) {
    throw new RuntimeException('Transactional audit compatibility failed');
}
$updated = $events->first(fn ($event) => $event->action === 'updated');
if ($updated->changes->before->status !== 'draft' || $updated->changes->after->status !== 'sent') {
    throw new RuntimeException('Audit image compatibility failed');
}
echo 'Laravel '.Application::VERSION.": create/update/delete/restore, frozen images, publish after commit and rollback passed on isolated SQLite\n";
