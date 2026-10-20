#!/usr/bin/env php
<?php

/**
 * TraceFlow SDK - Battle Test (standalone)
 *
 * Usage:
 *   php battle-test.php --url=http://localhost:3000/api/traceflow --key=YOUR_KEY
 *   php battle-test.php --url=... --key=... --traces=1000 --steps=5 --logs=3 --concurrency=20
 */

require_once __DIR__ . '/vendor/autoload.php';

// Minimal Laravel bootstrap for the SDK (needs Carbon's now() and config helpers)
// We'll bypass Laravel entirely and use the SDK directly.

// ── Parse CLI args ──────────────────────────────────────────────────────────────

$opts = getopt('', [
    'url:', 'key:', 'traces::', 'steps::', 'logs::', 'error-rate::',
    'concurrency::', 'sync', 'source::', 'help',
]);

if (isset($opts['help']) || !isset($opts['url']) || !isset($opts['key'])) {
    echo <<<USAGE

  TraceFlow SDK — Battle Test

  Usage:
    php battle-test.php --url=<traceflow_url> --key=<api_key> [options]

  Options:
    --traces=N        Number of traces (default: 100)
    --steps=N         Steps per trace (default: 3)
    --logs=N          Logs per entity (default: 2)
    --error-rate=N    Percentage of traces that fail (default: 20)
    --concurrency=N   Batch size per flush (default: 10)
    --sync            Use synchronous transport
    --source=NAME     Source identifier (default: battle-test)

USAGE;
    exit(1);
}

$url         = $opts['url'];
$apiKey      = $opts['key'];
$totalTraces = (int) ($opts['traces'] ?? 100);
$stepsPerTrace = (int) ($opts['steps'] ?? 3);
$logsPerEntity = (int) ($opts['logs'] ?? 2);
$errorRate   = (int) ($opts['error-rate'] ?? 20);
$batchSize   = (int) ($opts['concurrency'] ?? 10);
$useAsync    = !isset($opts['sync']);
$source      = $opts['source'] ?? 'battle-test';

// ── Bootstrap: fake now() helper if not available (package uses it) ─────────

if (!function_exists('now')) {
    function now(?string $tz = null): \DateTimeImmutable {
        return new \DateTimeImmutable('now', $tz ? new \DateTimeZone($tz) : null);
    }
}

use Smartness\TraceFlow\TraceFlowSDK;

// ── Stats ───────────────────────────────────────────────────────────────────

$stats = [
    'events_sent' => 0,
    'traces_ok' => 0,
    'traces_failed' => 0,
    'steps_ok' => 0,
    'steps_failed' => 0,
    'logs_sent' => 0,
    'errors' => 0,
    'error_messages' => [],
    'latencies' => [],
];

$expectedEvents = $totalTraces * (
    1 +                                             // trace_started
    1 +                                             // trace_finished/failed
    $logsPerEntity +                                // trace-level logs
    $stepsPerTrace * (1 + 1 + $logsPerEntity)       // step_started + step_finished/failed + step logs
);

// ── Banner ──────────────────────────────────────────────────────────────────

echo "\n";
echo "  =======================================\n";
echo "    TraceFlow SDK — Battle Test\n";
echo "  =======================================\n\n";
echo "  Target:          {$url}\n";
echo "  Source:          {$source}\n";
echo "  Transport:       " . ($useAsync ? 'async' : 'sync') . "\n";
echo "  Traces:          {$totalTraces}\n";
echo "  Steps/trace:     {$stepsPerTrace}\n";
echo "  Logs/entity:     {$logsPerEntity}\n";
echo "  Error rate:      {$errorRate}%\n";
echo "  Batch size:      {$batchSize}\n";
echo "  Expected events: ~{$expectedEvents}\n\n";

// ── Connectivity check ──────────────────────────────────────────────────────

echo "  Checking connectivity... ";

try {
    $client = new \GuzzleHttp\Client([
        'base_uri' => $url,
        'timeout' => 5,
        'headers' => [
            'Content-Type' => 'application/json',
            'X-API-Key' => $apiKey,
        ],
    ]);

    $response = $client->get('/api/v1/health', ['http_errors' => false]);
    $status = $response->getStatusCode();

    if ($status >= 200 && $status < 400) {
        echo "\033[32mOK (HTTP {$status})\033[0m\n";
    } elseif ($status === 404) {
        echo "\033[33mOK (no health endpoint)\033[0m\n";
    } else {
        echo "\033[31mFAILED (HTTP {$status})\033[0m\n";
        exit(1);
    }
} catch (\GuzzleHttp\Exception\ConnectException $e) {
    echo "\033[31mFAILED — cannot connect\033[0m\n";
    echo "  {$e->getMessage()}\n";
    exit(1);
} catch (\Throwable $e) {
    echo "\033[31mFAILED — {$e->getMessage()}\033[0m\n";
    exit(1);
}

// ── Create SDK instance ─────────────────────────────────────────────────────

$sdk = new TraceFlowSDK([
    'source' => $source,
    'transport' => 'http',
    'endpoint' => $url,
    'async_http' => false, // Force sync to guarantee event ordering and delivery
    'api_key' => $apiKey,
    'timeout' => 10.0,
    'max_retries' => 3,
    'retry_delay' => 500,
    'silent_errors' => false,
    'circuit_breaker_threshold' => 100,
    'circuit_breaker_timeout_ms' => 3000,
]);

// ── Run battle test ─────────────────────────────────────────────────────────

echo "\n";
$globalStart = microtime(true);
$batchCount = 0;

$traceTypes = ['api_request', 'job_processing', 'cron_task', 'webhook', 'user_action', 'batch_import'];
$stepNames  = ['validate_input', 'fetch_data', 'transform', 'persist', 'notify', 'cleanup'];
$logLevels  = ['DEBUG', 'INFO', 'WARN'];
$errorMsgs  = [
    'Connection timeout after 5000ms',
    'Invalid response format from upstream',
    'Rate limit exceeded (429)',
    'Database deadlock detected',
    'Out of memory in worker process',
    'Validation failed: missing required field "id"',
];

for ($i = 0; $i < $totalTraces; $i++) {
    $shouldFail = (mt_rand(1, 100) <= $errorRate);
    $traceStart = microtime(true);

    try {
        // Start trace
        $trace = $sdk->startTrace(
            traceType: $traceTypes[$i % count($traceTypes)],
            title: "Battle trace #{$i}",
            description: $shouldFail ? 'Will simulate failure' : 'Normal execution',
            owner: 'battle-test',
            tags: ['battle-test', $shouldFail ? 'error' : 'success'],
            metadata: [
                'batch_index' => $i,
                'will_fail' => $shouldFail,
                'php_version' => PHP_VERSION,
                'memory_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            ],
        );
        $stats['events_sent']++;

        // Trace-level logs
        for ($l = 0; $l < $logsPerEntity; $l++) {
            $trace->log(
                "Trace #{$i} log entry {$l}",
                $logLevels[$l % count($logLevels)],
                'trace_activity',
                ['log_index' => $l]
            );
            $stats['events_sent']++;
            $stats['logs_sent']++;
        }

        // Steps
        $failAtStep = $shouldFail ? mt_rand(0, $stepsPerTrace - 1) : -1;

        for ($s = 0; $s < $stepsPerTrace; $s++) {
            $stepShouldFail = ($s === $failAtStep);
            $stepName = $stepNames[$s % count($stepNames)];

            $step = $trace->startStep(
                name: "{$stepName}_{$s}",
                stepType: $stepName,
                input: ['trace_index' => $i, 'step_index' => $s, 'payload_size' => mt_rand(100, 10000)],
                metadata: ['attempt' => 1],
            );
            $stats['events_sent']++;

            // Step-level logs
            for ($l = 0; $l < $logsPerEntity; $l++) {
                $step->log(
                    "Step {$stepName}_{$s} log {$l}",
                    $stepShouldFail && $l === $logsPerEntity - 1 ? 'ERROR' : 'INFO',
                    'step_activity',
                    ['detail' => "Processing item {$l}"]
                );
                $stats['events_sent']++;
                $stats['logs_sent']++;
            }

            // Finish or fail step
            if ($stepShouldFail) {
                $step->fail($errorMsgs[mt_rand(0, count($errorMsgs) - 1)]);
                $stats['events_sent']++;
                $stats['steps_failed']++;
            } else {
                $step->finish(
                    output: ['records_processed' => mt_rand(1, 1000)],
                    metadata: ['cache_hit' => (bool) mt_rand(0, 1)]
                );
                $stats['events_sent']++;
                $stats['steps_ok']++;
            }
        }

        // Finish or fail trace
        if ($shouldFail) {
            $trace->fail("Simulated failure at step {$failAtStep}");
            $stats['events_sent']++;
            $stats['traces_failed']++;
        } else {
            $trace->finish(
                result: ['total_steps' => $stepsPerTrace, 'status' => 'all_ok'],
                metadata: ['completion_time_ms' => round((microtime(true) - $traceStart) * 1000, 2)]
            );
            $stats['events_sent']++;
            $stats['traces_ok']++;
        }

        $stats['latencies'][] = (microtime(true) - $traceStart) * 1000;

    } catch (\Throwable $e) {
        $stats['errors']++;
        $msg = $e->getMessage();
        $stats['error_messages'][] = $msg;
        // Print first occurrence of each error type
        if ($stats['errors'] <= 5) {
            echo "\033[31m  ! Error on trace #{$i}: {$msg}\033[0m\n";
        }
    }

    // Flush after every trace to guarantee event ordering (all step events
    // are delivered before trace_finished reaches the server).
    try {
        $sdk->flush();
    } catch (\Throwable $e) {
        $stats['errors']++;
    }

    // Progress
    if (($i + 1) % 10 === 0 || $i === $totalTraces - 1) {
        $pct = round(($i + 1) / $totalTraces * 100);
        $elapsed = round(microtime(true) - $globalStart, 1);
        echo "\r  [{$pct}%] {$i}/{$totalTraces} traces | OK: {$stats['traces_ok']} | FAIL(sim): {$stats['traces_failed']} | ERR: {$stats['errors']} | {$elapsed}s";
    }
}

echo "\n\n  Flushing remaining events... ";
$flushStart = microtime(true);

try {
    $sdk->shutdown();
    $flushTime = round((microtime(true) - $flushStart) * 1000);
    echo "\033[32mdone ({$flushTime}ms)\033[0m\n";
} catch (\Throwable $e) {
    $stats['errors']++;
    echo "\033[31merror: {$e->getMessage()}\033[0m\n";
}

$totalTime = microtime(true) - $globalStart;

// ── Results ─────────────────────────────────────────────────────────────────

echo "\n";
echo "  =======================================\n";
echo "    Results\n";
echo "  =======================================\n\n";

printf("  Total time:         %.2fs\n", $totalTime);
printf("  Events sent:        %d / %d expected\n", $stats['events_sent'], $expectedEvents);
printf("  Throughput:         %.0f events/sec\n", $stats['events_sent'] / max($totalTime, 0.001));
echo "\n";
printf("  Traces OK:          %d\n", $stats['traces_ok']);
printf("  Traces Failed:      %d (simulated)\n", $stats['traces_failed']);
printf("  Steps OK:           %d\n", $stats['steps_ok']);
printf("  Steps Failed:       %d (simulated)\n", $stats['steps_failed']);
printf("  Logs sent:          %d\n", $stats['logs_sent']);
echo "\n";

if ($stats['errors'] > 0) {
    echo "\033[31m" . sprintf("  SDK/Transport errors: %d\n", $stats['errors']) . "\033[0m";
    // Show unique error messages
    $unique = array_unique($stats['error_messages']);
    foreach (array_slice($unique, 0, 10) as $msg) {
        echo "\033[31m    - {$msg}\033[0m\n";
    }
} else {
    echo "\033[32m  SDK/Transport errors: 0\033[0m\n";
}

// Latency stats
if (count($stats['latencies']) > 0) {
    sort($stats['latencies']);
    $count = count($stats['latencies']);
    $avg = array_sum($stats['latencies']) / $count;
    $p50 = $stats['latencies'][(int) ($count * 0.50)];
    $p95 = $stats['latencies'][(int) min($count * 0.95, $count - 1)];
    $p99 = $stats['latencies'][(int) min($count * 0.99, $count - 1)];
    $min = $stats['latencies'][0];
    $max = $stats['latencies'][$count - 1];

    echo "\n  Per-trace latency (SDK-side, ms):\n";
    printf("    min: %.1f  avg: %.1f  p50: %.1f  p95: %.1f  p99: %.1f  max: %.1f\n", $min, $avg, $p50, $p95, $p99, $max);
}

printf("\n  Memory peak: %.1f MB\n\n", memory_get_peak_usage(true) / 1024 / 1024);

if ($stats['errors'] === 0) {
    echo "\033[32m  ✓ Battle test PASSED — all events sent successfully.\033[0m\n\n";
    exit(0);
} else {
    echo "\033[31m  ✗ Battle test COMPLETED WITH ERRORS — {$stats['errors']} transport/SDK errors.\033[0m\n\n";
    exit(1);
}
