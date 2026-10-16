# Traceflow SDK for Laravel

Laravel client for traces, steps, logs and audit events. Audit logging uses the
SDK's `LogsAudit` trait or manual API, with no Spatie dependency or integration.

## Install and configure

```sh
composer require smartness/traceflow-laravel
php artisan vendor:publish --tag=traceflow-config
```

Composer constraints support PHP 8.1+ and Laravel 10–13; the selected Laravel
version also imposes its own PHP requirement. Laravel discovers the service
provider automatically. The package is distributed from the
[split repository](https://github.com/smartpricing/traceflow-laravel).

Set these values in the application's `.env`:

```dotenv
TRACEFLOW_SOURCE=booking-api
TRACEFLOW_URL=http://localhost:3009
TRACEFLOW_API_KEY=your-api-key-here
TRACEFLOW_TRANSPORT=http
```

The URL is the service base URL, without `/api/v1`. Use a key accepted by the
backend and a distinct source for each environment sharing its storage.
See [config/traceflow.php](config/traceflow.php) for all settings.

## Audit model changes

Deploy the backend audit API and apply its CQL schema first. Publish the outbox
migration and run it on the same database connection as the audited models:

```sh
php artisan vendor:publish --tag=traceflow-audit-migrations
php artisan migrate
```

For a named connection, use `php artisan migrate --database=business`. No migration
runs automatically when the package is installed.

Add `LogsAudit` and an explicit field allowlist to a model:

```php
use Illuminate\Database\Eloquent\Model;
use Smartness\TraceFlow\Audit\LogsAudit;

class Quote extends Model
{
    use LogsAudit;

    public function auditType(): string
    {
        return 'quote';
    }

    public function auditFields(): array
    {
        return ['status', 'total'];
    }
}
```

For this model on the default connection, update it inside a transaction:

```php
use Illuminate\Support\Facades\DB;
use Smartness\TraceFlow\Audit\AuditContext;

app(AuditContext::class)->run([
    'actor' => ['type' => 'user', 'id' => '42'],
    'origin' => ['channel' => 'mobile_app', 'client_source' => 'mobile-app'],
    'metadata' => ['reason' => 'Discount agreed'],
], function () {
    DB::transaction(function () {
        $quote = Quote::query()->lockForUpdate()->findOrFail('12345');
        $quote->total = '420.00';
        $quote->save();
    });
});
```

The trait captures create, update, delete and restore events. Saves, deletes and
restores require an already-open transaction on the model's connection. The model
write and outbox insert roll back together. Updates with no changes to selected
fields are skipped; before/after images are captured before publishing.

`AuditContext::run()` scopes context to the callback and restores the previous
context even on an exception. The trait uses the authenticated actor when none
is supplied. Pass trusted context explicitly into queued jobs.
Override `customizeAudit(array $event): array` to redact or enrich an event.

### Publish the outbox

Run this command periodically from the application's scheduler or job runner:

```sh
php artisan traceflow:audit-publish --limit=100 --max-seconds=30 --json
```

For a named database, add `--connection=business`. Each invocation processes at
most the requested number of ready rows and exits. Choose the frequency and
batch size for the expected volume. `--max-seconds` bounds delivery attempts and
HTTP timeouts for one invocation (default 30, maximum 300); database queries still
use the application's database timeout. A transient delivery failure stops the
batch to avoid retrying every row during an outage. Remaining rows stay pending.
`--json` reports published, retry-scheduled and failed counts. A delivery failure
or exhausted time budget returns a nonzero exit code.

A row is deleted only after Traceflow confirms persistence. Transient failures
retain the same event and set a later `available_at`. Missing credentials and
HTTP `401`/`403`/`404` also retain pending rows: fixing publisher configuration or
deploying the route must not require reconstructing audit events. Those HTTP
responses end the current delivery attempt without immediate HTTP retries. Permanent failures set
`failed_at` and retain the row for review. After fixing the cause, run
`php artisan traceflow:audit-retry EVENT_ID`. It preserves the original payload,
ID and attempt history. Investigate `409` conflicts before resending: the same
event ID cannot describe different data.

Concurrent publishers may send duplicates; Traceflow deduplicates by source and
event ID. Audit history is read from Traceflow. The outbox holds pending or failed
deliveries, not the history shown to users.

Monitor the publisher separately from application request health:

```sh
php artisan traceflow:audit-status --max-age=300 --max-pending=10000
```

The command emits JSON with pending/ready/failed counts and oldest pending age.
It exits nonzero when there are failed rows or the age/backlog thresholds are
exceeded. Feed this result and publisher exit codes into your scheduler's alerting.
Use `--connection` consistently for capture, publishing, status and retries.
Publish and apply the additional `index_traceflow_audit_outbox` migration when
upgrading an existing installation; it indexes ready rows and pending age.

### Manual capture

Bulk updates/deletes, raw SQL, pivot changes and muted Eloquent events bypass
model capture. Use the manual API inside the transaction doing that work.
For example, with an existing `quotes` table on the default connection:

```php
use Illuminate\Support\Facades\DB;
use Smartness\TraceFlow\Audit\AuditClient;
use Smartness\TraceFlow\Audit\AuditEvent;
use Smartness\TraceFlow\Audit\AuditOutbox;

$db = DB::connection();
$db->transaction(function () use ($db) {
    $row = $db->table('quotes')->where('id', '12345')->lockForUpdate()->first();
    if ($row === null) {
        throw new RuntimeException('Quote not found');
    }

    $before = ['total' => (string) $row->total];
    $after = ['total' => '420.00'];
    $changes = AuditEvent::diff($before, $after);
    if ((array) $changes['before'] === [] && (array) $changes['after'] === []) {
        return;
    }

    $db->table('quotes')->where('id', '12345')->update($after);
    $event = app(AuditClient::class)->createEvent([
        'subject' => ['type' => 'quote', 'id' => '12345'],
        'action' => 'updated',
        'actor' => ['type' => 'user', 'id' => '42'],
        'changes' => $changes,
        'metadata' => ['reason' => 'Discount agreed'],
    ]);
    app(AuditOutbox::class)->record($event, $db);
});
```

Manual events take their context from the supplied payload. Obtain the actual
before/after values within the operation; account for locking, casts and database
triggers in the integrating application. Do not reconstruct past values in the
publisher by reloading the current model.

`AuditClient::record($event)` sends directly and returns a persistence receipt or
throws `AuditDeliveryException`. `recordAudit($input)` creates and sends an event
in one call; it is also available on `TraceFlowSDK`. Direct sending alone does
not recover an event lost between business commit and a process crash.

Subject types and IDs are strings. Missing fields differ from `null`; use decimal
strings for exact money values. Creation uses an empty before image, deletion an
empty after image. Custom activities can omit changes. Select fields and redact
metadata before capture. See the [backend audit contract](https://github.com/smartpricing/traceflow-service#audit-log)
for payload limits, retention and read APIs.

When replacing another activity log, migrate its readers as well as writers.
Historical import, business restores and application-specific search or metrics
are separate integration work.

## Tracing

```php
use Smartness\TraceFlow\Facades\TraceFlow;

$trace = TraceFlow::startTrace(traceType: 'quote', title: 'Calculate quote');
$step = $trace->startStep(name: 'Calculate total');
$step->log('Calculation completed');
$step->finish(output: ['total' => '420.00']);
$trace->finish(result: ['quote_id' => '12345']);
```

Use `fail($error)` on a trace or step to record a failure. Explicit trace IDs must
be UUIDs; omit them to generate one. `getTrace($traceId)` reads live state over
HTTP, and `heartbeat($traceId)` refreshes activity. With silent errors enabled,
a returned handle does not prove that the backend accepted or found a trace.

Register [TraceFlowMiddleware](src/Middleware/TraceFlowMiddleware.php) in the
application's middleware configuration to trace HTTP requests. It reads
`X-Trace-Id`, stores the handle in request attribute `trace`, finishes or fails
the trace after the response, and clears context.

For queue propagation, [TracedJob](src/Queue/TracedJob.php) captures the current
trace when `initializeTracedJob()` is called in the job constructor. Its middleware
restores and clears that context. Merge this middleware if the job already defines
its own middleware. This propagates trace context, not `AuditContext`.

## Delivery settings

| Setting | Behaviour |
| --- | --- |
| `TRACEFLOW_TRANSPORT=http` | Trace events use HTTP; `log` writes to Laravel's log channel. Kafka is not implemented in the PHP SDK. |
| `TRACEFLOW_ASYNC_HTTP=true` | Default for tracing. Pending requests are flushed during shutdown; abrupt termination can lose them. |
| `TRACEFLOW_SILENT_ERRORS=true` | Default for tracing delivery failures. Configuration and input errors can still throw. |
| `TRACEFLOW_ENABLED=false` | Drops trace events. Audit delivery throws a retryable error, leaving outbox rows pending. |
| `TRACEFLOW_TIMEOUT` | HTTP timeout in seconds; default `5`. |
| `TRACEFLOW_MAX_RETRIES` | Default `3`. |
| `TRACEFLOW_RETRY_DELAY` | Initial retry delay in milliseconds; default `1000`. |

Audit always uses synchronous HTTP and explicit errors, regardless of the tracing
transport, `async_http` or `silent_errors`. Disabling delivery does not disable
model capture or its transaction requirement.

For local trace logs, set `TRACEFLOW_TRANSPORT=log` and choose
`TRACEFLOW_LOG_CHANNEL` / `TRACEFLOW_LOG_LEVEL`. These settings do not redirect
audit events to a log file.

## Development

From this directory:

```sh
composer install
composer test:unit
composer test:feature
composer analyse
composer format:check
```

Run the audit tests without an application database:

```sh
vendor/bin/phpunit tests/Unit/AuditTest.php tests/Unit/AuditOutboxTest.php
```

Optional compatibility check against installed Laravel classes, using isolated
SQLite rather than booting the application:

```sh
php tests/audit-installed-framework-smoke.php /path/to/installed/vendor
```

License: MIT, as declared in [composer.json](composer.json).
