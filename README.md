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
are pushed right after the write. Single events hash their subject into one of
64 transport partitions and use the event ID as the push transaction ID, so a
repeated push is stored once. Bulk delivery is described below.

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

For bulk operations, use `publishBatch($events)` or
`publishBatchAfterCommit($events, $db)`, with a list of `AuditEvent` objects.
Each Queen message contains an `audit_batch` envelope with at most **100 events
and 512 KiB of JSON**, preserving each event's ID and snapshot. For example,
10,000 small events produce 100 messages, not 10,000. A single-event call keeps
the original `audit` payload format.

Archived-ID checks are off by default. Enable them for a specific send:

```php
$publisher = app(AuditPublisher::class);
$publisher->checkHistory()->publish($event);
$publisher->checkHistory()->publishBatch($events);
$publisher->checkHistory()->publishBatchAfterCommit($events, $db);
```

`checkHistory()` returns a scoped publisher; later sends through `$publisher` keep
checks off. It also supports `publishAfterCommit()`. There is no environment setting.
The option belongs to the delivery, not to `AuditEvent`, and preserves event IDs.
Choose it on the first attempt: changing it does not reprocess a message that Queen
already accepted within its deduplication window. Pending retries still deduplicate without it. After archival, a retry
can append a physical copy; timeline reads merge identical IDs. Never reuse an ID for
different content. With checks enabled, the consumer verifies retained IDs and sends
conflicts to the dead-letter queue. Queue acceptance does not confirm that check.
Deploy the backend with `check_history` support before enabling the option.

The publisher uses **64 fixed transport partitions**. All chunks of one bulk call
use the same partition (derived from `batch_id`, or its first event ID); single
calls hash their source and subject. This preserves order within a bulk call, but
does not guarantee subject order across independent bulk operations. Traceflow
orders entity history by the event timestamp and ID, independently of partitions.

**Deploy the Traceflow consumer with `audit_batch` support before upgrading to
SDK 2.11.** It accepts both payload formats, validates the entire envelope before
writing, and acknowledges it only after every event is durable. A storage failure
retries the envelope; pending events are deduplicated. Invalid envelopes or
permanent event conflicts go to the dead-letter queue for inspection.

The after-commit variant registers one callback and publishes nothing on rollback.
If delivery fails, retry with the **same original events**: earlier chunks may
already be accepted, and stable envelope IDs deduplicate within Queen’s transaction-ID window.
After-commit failures are reported and are not durably retried. Internal archival
still uses individual events; envelope batching reduces messages on the ingress queue.

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
be UUIDs; omit them to generate one. A returned handle does not prove that the
backend accepted a trace: these calls never wait for the server (see
[Delivery](#delivery)).

`continueTrace($traceId)` continues a trace started by another service, without
a network call. `getTrace($traceId)` also reads the trace's live state over HTTP and
`heartbeat($traceId)` refreshes its activity: both wait for the server, at most
`TRACEFLOW_TIMEOUT` seconds, and return at once without a request while the
circuit breaker is open.

Register [TraceFlowMiddleware](src/Middleware/TraceFlowMiddleware.php) in the
application's middleware configuration to trace HTTP requests. It reads
`X-Trace-Id` (continuing that trace locally, with no request to the backend),
stores the handle in request attribute `trace`, finishes or fails the trace once
the response is built, and clears context. Nothing in the middleware waits for the
Traceflow server.

For queue propagation, [TracedJob](src/Queue/TracedJob.php) captures the current
trace when `initializeTracedJob()` is called in the job constructor. Its middleware
restores and clears that context. Merge this middleware if the job already defines
its own middleware. This propagates trace context, not `AuditContext`.

## Delivery

Tracing never makes the application wait for the Traceflow server, whatever its
state (slow, hanging, answering errors or down). With the default async HTTP
transport, `startTrace`, `startStep`, `log`, `finish` and `fail` only put the event
in an in-memory queue, in well under a millisecond and without any network or
cache access.

The queued events are sent:

- when the application terminates: under PHP-FPM and Octane that is **after the
  response has been sent** (Laravel's `terminating` callbacks; Symfony's
  `Response::send()` calls `fastcgi_finish_request()`), so the client never waits.
  Under Apache mod_php, which has no `fastcgi_finish_request()`, the connection may
  stay open until then. Traces left open are failed first;
- after each queue job (`JobProcessed`, `JobExceptionOccurred`, `JobFailed`), since a
  worker never terminates between jobs. Sync and deferred jobs run inside the
  request and are not flushed separately;
- from a shutdown function, after `exit()` or a fatal error;
- when you call `TraceFlow::flush()`, e.g. in a long-running command or daemon that
  never terminates between units of work.

Each flush waits at most `TRACEFLOW_FLUSH_TIMEOUT` seconds (default `2`). Under
PHP-FPM that time holds the worker, not the client, so keep it short. Within it,
requests run at most `TRACEFLOW_CONCURRENCY` at a time (default `4`) and failed
attempts are retried with exponential backoff and jitter. A trace's create goes
before its steps and logs, a step's create before its update, and a trace's final
update after everything sent before it for that trace.

What is dropped (and counted in `TraceFlow::getStats()`):

| Case | What happens |
| --- | --- |
| More than `TRACEFLOW_MAX_QUEUE_SIZE` events waiting (default `10000`) | New events are dropped. |
| Network error, timeout, 5xx or 429 | Retried up to `TRACEFLOW_MAX_RETRIES` times, then dropped. |
| Other 4xx | Dropped at once, not retried; does not count toward the breaker. |
| 409 | Counts as delivered (the trace or step already exists). |
| Not delivered when the flush time is up | Dropped at the end of the request; kept for the next flush in queue workers. |
| Circuit breaker open | Dropped without a request. |

Drops are logged with `error_log()` at most once per 10 seconds per process, with the
counts since the previous warning. During an outage the breaker logs when it opens
and re-opens instead, so a PHP-FPM application does not log one line per request.

**Circuit breaker.** After `TRACEFLOW_CIRCUIT_BREAKER_THRESHOLD` consecutive failed
attempts (default `5`; network errors, timeouts, the flush time running out, 5xx, 429)
no request is sent for `TRACEFLOW_CIRCUIT_BREAKER_TIMEOUT_MS` (default `60000`).
Then a single request, in a single process, probes the server: success closes the
breaker, failure re-opens it for the full timeout at once. Under PHP-FPM every
request starts from scratch, so the breaker state is kept in the Laravel cache
(`TRACEFLOW_CIRCUIT_BREAKER_CACHE_STORE`, default store when empty) and shared by
all requests and workers using it. It is read about once per request, after the
response, and written only on failures and state changes. Use a fast store shared
by the workers, such as Redis (the `array` store lasts one request under PHP-FPM).
If the cache fails, the breaker falls back to per-process state. Set
`TRACEFLOW_CIRCUIT_BREAKER_SHARED=false` to keep it per process.

An outage therefore costs each PHP-FPM worker at most one flush timeout per
request, only until the breaker opens, then one probe per breaker timeout across all
processes.

**Errors.** Tracing calls never throw delivery errors. With
`TRACEFLOW_SILENT_ERRORS=false`, `TraceFlow::flush()` and `shutdown()` throw a
`DeliveryException` listing the events not delivered since the previous flush,
which keeps that setting useful in tests and scripts. The automatic flushes catch it
and log it instead.

**Synchronous transport.** `TRACEFLOW_ASYNC_HTTP=false` sends each event from the
tracing call itself and **blocks** it until the request, retries and backoff
included, is done: up to `(max_retries + 1) x timeout` plus the delays per call when
the server hangs. It honours the shared breaker (events are dropped at once while it
is open) and throws delivery errors from the tracing call when silent errors are
off. Use it only where blocking is acceptable.

## Delivery settings

| Setting | Behaviour |
| --- | --- |
| `TRACEFLOW_TRANSPORT=http` | Trace events use HTTP; `log` writes to Laravel's log channel. Kafka is not implemented in the PHP SDK. |
| `TRACEFLOW_ASYNC_HTTP=true` | Default: tracing calls never wait; events are sent after the response, after each job and at shutdown. `false` blocks every tracing call. |
| `TRACEFLOW_SILENT_ERRORS=true` | Default for tracing delivery failures. Configuration and input errors can still throw. |
| `TRACEFLOW_ENABLED=false` | Drops trace events and audit events. Audit envelopes are still validated. |
| `TRACEFLOW_TIMEOUT` | Total timeout of one HTTP attempt (connect + response), in seconds; default `5`. |
| `TRACEFLOW_MAX_RETRIES` | Default `3`. |
| `TRACEFLOW_RETRY_DELAY` | Initial retry delay in milliseconds, doubled per retry with jitter, capped at 30 s; default `1000`. |
| `TRACEFLOW_FLUSH_TIMEOUT` | Longest wait of one flush, in seconds; default `2`. |
| `TRACEFLOW_MAX_QUEUE_SIZE` | Events waiting for delivery before new ones are dropped; default `10000`. |
| `TRACEFLOW_CONCURRENCY` | Requests in flight at once during a flush; default `4`. |
| `TRACEFLOW_CIRCUIT_BREAKER_THRESHOLD` | Consecutive failed attempts that open the breaker; default `5`. |
| `TRACEFLOW_CIRCUIT_BREAKER_TIMEOUT_MS` | How long the breaker stays open before a probe; default `60000`. |
| `TRACEFLOW_CIRCUIT_BREAKER_CACHE_STORE` | Laravel cache store holding the breaker state; empty = default store. |
| `TRACEFLOW_CIRCUIT_BREAKER_SHARED` | `false` keeps the breaker state per process; default `true`. |

Audit events always go through Queen, regardless of the tracing transport,
`async_http` or `silent_errors`.

For local trace logs, set `TRACEFLOW_TRANSPORT=log` and choose
`TRACEFLOW_LOG_CHANNEL` / `TRACEFLOW_LOG_LEVEL`. These settings do not redirect
audit events to a log file.

## Upgrading to 2.13

- Tracing calls and `TraceFlowMiddleware` no longer wait for Traceflow. Events are
  sent after the response, after each queue job and at shutdown, each flush waiting at
  most `TRACEFLOW_FLUSH_TIMEOUT` (2 s). Before, `$trace->finish()` waited for every
  request of the trace, inside the request.
- The circuit breaker state now lives in the Laravel cache, so it opens across PHP-FPM
  requests. Set `TRACEFLOW_CIRCUIT_BREAKER_CACHE_STORE` if the default store is slow
  or shared with something it should not be.
- A request with `X-Trace-Id` continues that trace without calling Traceflow
  (`continueTrace()`); `getTrace()` still reads the remote state for explicit callers.
- With `TRACEFLOW_SILENT_ERRORS=false`, the async transport reports delivery failures
  from `flush()`/`shutdown()` (`DeliveryException`), not from the tracing calls.
- During an outage, events are dropped (queue full, retries spent, flush deadline)
  instead of slowing the application down.

## Development

From this directory:

```sh
composer install
composer test:unit
composer test:feature
composer analyse
composer format:check
```

The delivery tests (`tests/Feature/NonBlockingDeliveryTest.php`,
`tests/Feature/LaravelDeliveryTest.php`) start throwaway servers on 127.0.0.1 with
`php -S` (and a socket that never answers) to check that tracing never waits for a
hanging, failing or unreachable server.

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
