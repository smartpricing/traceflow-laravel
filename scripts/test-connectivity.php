<?php

/**
 * TraceFlow SDK Connectivity Test
 *
 * Tests real connectivity, authentication, and creates a full trace with steps and logs.
 *
 * Required environment variables:
 *   TRACEFLOW_URL     - TraceFlow service endpoint (e.g. http://localhost:3000)
 *   TRACEFLOW_API_KEY - API key for authentication
 *
 * Optional:
 *   TRACEFLOW_SOURCE  - Source identifier (default: sdk-test)
 *
 * Usage:
 *   composer test:connectivity
 *   TRACEFLOW_URL=http://localhost:3000 TRACEFLOW_API_KEY=xxx composer test:connectivity
 */

require __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;

// --- Config ---

$endpoint = getenv('TRACEFLOW_URL') ?: 'http://localhost:3000';
$apiKey = getenv('TRACEFLOW_API_KEY') ?: '';
$source = getenv('TRACEFLOW_SOURCE') ?: 'sdk-test';

$passed = 0;
$failed = 0;

function info(string $msg): void { echo "\033[32m{$msg}\033[0m"; }
function warn(string $msg): void { echo "\033[33m{$msg}\033[0m"; }
function fail(string $msg): void { echo "\033[31m{$msg}\033[0m"; }
function dim(string $msg): void { echo "\033[90m{$msg}\033[0m"; }

echo PHP_EOL;
info("  TraceFlow SDK — Connectivity Test\n");
echo PHP_EOL;
dim("  Endpoint:  {$endpoint}\n");
dim("  API Key:   " . ($apiKey ? substr($apiKey, 0, 8) . '...' : '<not set>') . "\n");
dim("  Source:    {$source}\n");
echo PHP_EOL;

if (! $apiKey) {
    fail("  TRACEFLOW_API_KEY is not set.\n");
    echo "  Export it or pass inline: TRACEFLOW_API_KEY=xxx composer test:connectivity\n";
    exit(1);
}

$client = new Client([
    'base_uri' => $endpoint,
    'timeout' => 5,
    'headers' => [
        'Content-Type' => 'application/json',
        'X-API-Key' => $apiKey,
    ],
]);

function step(string $label, callable $fn): void
{
    global $passed, $failed;

    echo "  {$label} ";

    try {
        $result = $fn();
        info("OK");
        if ($result) {
            dim(" ({$result})");
        }
        echo PHP_EOL;
        $passed++;
    } catch (\Throwable $e) {
        fail("FAILED\n");
        fail("    {$e->getMessage()}\n");
        $failed++;
    }
}

$traceId = sprintf(
    '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
    mt_rand(0, 0xffff), mt_rand(0, 0xffff),
    mt_rand(0, 0xffff),
    mt_rand(0, 0x0fff) | 0x4000,
    mt_rand(0, 0x3fff) | 0x8000,
    mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
);

$stepId = sprintf(
    '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
    mt_rand(0, 0xffff), mt_rand(0, 0xffff),
    mt_rand(0, 0xffff),
    mt_rand(0, 0x0fff) | 0x4000,
    mt_rand(0, 0x3fff) | 0x8000,
    mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
);

$now = (new DateTimeImmutable())->format('c');

// 1. Connectivity
step('Connectivity', function () use ($client) {
    try {
        $r = $client->get('/api/v1/health', ['http_errors' => false]);
        return "HTTP {$r->getStatusCode()}";
    } catch (ConnectException $e) {
        throw new RuntimeException("Cannot reach server: {$e->getMessage()}");
    }
});

// 2. Create trace
step('Create trace', function () use ($client, $traceId, $source, $now) {
    $r = $client->post('/api/v1/traces', [
        'json' => [
            'trace_id' => $traceId,
            'trace_type' => 'sdk_connectivity_test',
            'status' => 'pending',
            'source' => $source,
            'title' => 'SDK Connectivity Test',
            'created_at' => $now,
            'updated_at' => $now,
            'last_activity_at' => $now,
            'metadata' => [
                'php_version' => PHP_VERSION,
                'test' => 'connectivity',
            ],
        ],
    ]);
    return "HTTP {$r->getStatusCode()}, trace={$traceId}";
});

// 3. Create step
step('Create step', function () use ($client, $traceId, $stepId, $now) {
    $r = $client->post('/api/v1/steps', [
        'json' => [
            'trace_id' => $traceId,
            'step_id' => $stepId,
            'step_type' => 'test',
            'name' => 'Connectivity Verification',
            'status' => 'started',
            'started_at' => $now,
            'updated_at' => $now,
            'metadata' => ['automated' => true],
        ],
    ]);
    return "HTTP {$r->getStatusCode()}, step={$stepId}";
});

// 4. Create log
step('Create log', function () use ($client, $traceId, $now) {
    $r = $client->post('/api/v1/logs', [
        'json' => [
            'trace_id' => $traceId,
            'log_id' => sprintf('%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff)),
            'log_time' => $now,
            'level' => 'info',
            'event_type' => 'test',
            'message' => 'SDK connectivity test passed',
            'source' => 'sdk-test',
            'details' => ['php_version' => PHP_VERSION],
        ],
    ]);
    return "HTTP {$r->getStatusCode()}";
});

// 5. Complete step
step('Complete step', function () use ($client, $traceId, $stepId, $now) {
    $r = $client->patch("/api/v1/steps/{$traceId}/{$stepId}", [
        'json' => [
            'status' => 'completed',
            'updated_at' => $now,
            'finished_at' => $now,
            'output' => ['result' => 'all checks passed'],
        ],
    ]);
    return "HTTP {$r->getStatusCode()}";
});

// 6. Complete trace
step('Complete trace', function () use ($client, $traceId, $now) {
    $r = $client->patch("/api/v1/traces/{$traceId}", [
        'json' => [
            'status' => 'completed',
            'updated_at' => $now,
            'finished_at' => $now,
            'last_activity_at' => $now,
            'result' => ['test' => 'passed'],
            'metadata' => ['test' => 'connectivity'],
        ],
    ]);
    return "HTTP {$r->getStatusCode()}";
});

// Summary
echo PHP_EOL;
dim("  Trace ID: {$traceId}\n");
echo PHP_EOL;

if ($failed === 0) {
    info("  All {$passed} checks passed!\n");
} else {
    fail("  {$failed}/{$passed} checks failed.\n");
}

echo PHP_EOL;
exit($failed > 0 ? 1 : 0);
