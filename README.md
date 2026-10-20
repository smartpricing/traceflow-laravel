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

Audit events travel through a [Queen MQ](https://queenmq.com) queue: the SDK
pushes each event, and the Traceflow worker reads the queue and stores it. Enable
the consumer on the service first (`AUDIT_QUEEN_ENABLED`, see the
[service README](https://github.com/smartpricing/traceflow-service#delivery-through-queen))
and install the Queen client in the application:

```sh
composer require queen-mq/php-client
```

```dotenv
TRACEFLOW_AUDIT_QUEEN_URL=http://queen-mq.queen.svc.cluster.local:6632
```

The URL and token default to the application's `QUEEN_URL` and
`QUEEN_BEARER_TOKEN`. No table or scheduled command is needed.

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

Enum casts are recorded as their backing value or case name, dates as ISO strings.
Free text (`description`, `actor.label`, `redacted_fields`) and nested objects are
validated against the backend contract when the event is created, so an invalid
envelope fails the write instead of the later publish.

Update the model as usual; inside a transaction, the event leaves after the commit:

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

The trait captures create, update, delete and restore events. Updates with no
changes to selected fields are skipped; before/after images are captured at the
write.

Inside a transaction, the events are pushed once the outermost transaction on the
model's connection commits; a rollback pushes nothing. Outside a transaction, they
are pushed right after the write. Each subject has its own partition, so Traceflow
stores its events in order, and the event ID is the push's transaction ID, so a
repeated push is stored once.

The push happens after the write has committed. If Queen does not accept it, the
SDK reports an `AuditDeliveryException` to the application's exception handler and
the write stays: that event is lost. Watch those reports. A push waits at most
`TRACEFLOW_AUDIT_QUEEN_TIMEOUT_MS` (default `2000`) per attempt, with
`TRACEFLOW_AUDIT_QUEEN_RETRY_ATTEMPTS` (default `1`) attempts. After a failure, pushes
from the same process fail at once for `TRACEFLOW_AUDIT_QUEEN_COOLDOWN_SECONDS`
(default `30`, `0` disables it), so an outage does not slow every request or job item;
those events are reported and lost too.

`AuditContext::run()` scopes context to the callback and restores the previous
context even on an exception. The trait uses the authenticated actor when none
is supplied. Pass trusted context explicitly into queued jobs.
Override `customizeAudit(array $event): array` to redact or enrich an event.

### Manual capture

Bulk updates/deletes, raw SQL, pivot changes and muted Eloquent events bypass
model capture. Use the manual API inside the transaction doing that work.
For example, with an existing `quotes` table on the default connection:

```php
use Illuminate\Support\Facades\DB;
use Smartness\TraceFlow\Audit\AuditClient;
use Smartness\TraceFlow\Audit\AuditEvent;
use Smartness\TraceFlow\Audit\AuditPublisher;

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
    app(AuditPublisher::class)->publishAfterCommit($event, $db);
});
```

Manual events take their context from the supplied payload. Obtain the actual
before/after values within the operation; account for locking, casts and database
triggers in the integrating application. Do not reconstruct past values later by
reloading the current model.

`publishAfterCommit()` follows the trait's rules: it waits for the commit and
reports failures. `publish($event)` pushes at once and throws
`AuditDeliveryException` when Queen does not accept the event, for callers that
handle the failure themselves. Both do nothing while `TRACEFLOW_ENABLED=false`.

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
| `TRACEFLOW_ENABLED=false` | Drops trace events and audit events. Audit envelopes are still validated. |
| `TRACEFLOW_TIMEOUT` | HTTP timeout in seconds; default `5`. |
| `TRACEFLOW_MAX_RETRIES` | Default `3`. |
| `TRACEFLOW_RETRY_DELAY` | Initial retry delay in milliseconds; default `1000`. |

Audit events always go through Queen, regardless of the tracing transport,
`async_http` or `silent_errors`.

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
vendor/bin/phpunit tests/Unit/AuditTest.php tests/Unit/AuditPublisherTest.php
```

Optional compatibility check against installed Laravel classes, using isolated
SQLite rather than booting the application:

```sh
php tests/audit-installed-framework-smoke.php /path/to/installed/vendor
```

License: MIT, as declared in [composer.json](composer.json).
