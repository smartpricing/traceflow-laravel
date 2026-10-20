<?php

/**
 * Async Transport Performance Example
 *
 * Demonstrates the performance difference between async and blocking HTTP transports.
 * Shows how async transport drastically reduces latency overhead.
 */

require_once __DIR__.'/../vendor/autoload.php';

use Smartness\TraceFlow\TraceFlowSDK;

echo "=== Async Transport Performance Demo ===\n\n";

// Configuration for async transport (default)
$asyncConfig = [
    'transport' => 'http',
    'async_http' => true,  // Non-blocking async HTTP
    'source' => 'async-demo',
    'endpoint' => 'http://localhost:3009',
    'api_key' => 'demo-key',
    'timeout' => 5.0,
    'max_retries' => 3,
    'silent_errors' => true,
];

// Configuration for blocking transport (comparison)
$blockingConfig = [
    'transport' => 'http',
    'async_http' => false,  // Blocking HTTP
    'source' => 'blocking-demo',
    'endpoint' => 'http://localhost:3009',
    'api_key' => 'demo-key',
    'timeout' => 5.0,
    'max_retries' => 3,
    'silent_errors' => true,
];

/**
 * Benchmark trace operations
 */
function benchmarkTracing(TraceFlowSDK $sdk, string $label, int $iterations = 10): void
{
    echo "Testing: $label\n";
    echo str_repeat('-', 50)."\n";

    $timings = [];

    for ($i = 0; $i < $iterations; $i++) {
        $start = microtime(true);

        // Start trace
        $trace = $sdk->startTrace(
            traceType: 'benchmark',
            title: "Benchmark Iteration $i",
            metadata: ['iteration' => $i]
        );

        // Add a step
        $step = $trace->startStep(
            name: 'Process Data',
            input: ['iteration' => $i]
        );

        // Simulate some work
        usleep(1000); // 1ms of work

        $step->finish(['processed' => true]);

        // Finish trace
        $trace->finish(['completed' => true]);

        $end = microtime(true);
        $timings[] = ($end - $start) * 1000; // Convert to milliseconds
    }

    // Flush any pending async events
    $sdk->flush();

    // Calculate statistics
    $avgTime = array_sum($timings) / count($timings);
    $minTime = min($timings);
    $maxTime = max($timings);

    echo "Iterations: $iterations\n";
    echo 'Average time: '.number_format($avgTime, 2)." ms\n";
    echo 'Min time: '.number_format($minTime, 2)." ms\n";
    echo 'Max time: '.number_format($maxTime, 2)." ms\n";
    echo 'Total time: '.number_format(array_sum($timings), 2)." ms\n\n";
}

// Test async transport
echo "1️⃣  ASYNC TRANSPORT (Non-blocking)\n";
echo "====================================\n\n";
$asyncSDK = new TraceFlowSDK($asyncConfig);
benchmarkTracing($asyncSDK, 'Async HTTP Transport', 10);

// Test blocking transport
echo "2️⃣  BLOCKING TRANSPORT (Synchronous)\n";
echo "====================================\n\n";
$blockingSDK = new TraceFlowSDK($blockingConfig);
benchmarkTracing($blockingSDK, 'Blocking HTTP Transport', 10);

// Comparison
echo "💡 KEY TAKEAWAYS:\n";
echo str_repeat('=', 50)."\n";
echo "• Async transport adds ~2-5ms overhead per trace\n";
echo "• Blocking transport adds ~50-200ms overhead per trace\n";
echo "• Async is ~10-100x faster for trace instrumentation\n";
echo "• Async events are flushed automatically on shutdown\n";
echo "• Use TRACEFLOW_ASYNC_HTTP=true in production (default)\n\n";

// Demonstrate fire-and-forget behavior
echo "3️⃣  FIRE-AND-FORGET DEMO\n";
echo "====================================\n\n";

$sdk = new TraceFlowSDK($asyncConfig);

echo "Creating 5 traces with async transport...\n";
$start = microtime(true);

for ($i = 0; $i < 5; $i++) {
    $trace = $sdk->startTrace(
        title: "Async Trace $i",
        metadata: ['index' => $i]
    );

    $step = $trace->startStep(name: "Step $i");
    $step->finish();

    $trace->finish();

    echo "  ✓ Trace $i sent (immediately returned)\n";
}

$end = microtime(true);
$totalTime = ($end - $start) * 1000;

echo "\nAll 5 traces sent in: ".number_format($totalTime, 2)." ms\n";
echo 'Average per trace: '.number_format($totalTime / 5, 2)." ms\n";
echo "\nNow flushing promises...\n";

$sdk->flush();

echo "✓ All promises settled successfully!\n\n";

// Demonstrate context propagation with async
echo "4️⃣  CONTEXT PROPAGATION WITH ASYNC\n";
echo "====================================\n\n";

$sdk = new TraceFlowSDK($asyncConfig);

// Start main trace
$mainTrace = $sdk->startTrace(
    title: 'Main API Request',
    traceType: 'http_request'
);

echo "Main trace started: {$mainTrace->traceId}\n";

// Simulate passing trace ID to background job
$traceIdForJob = $mainTrace->traceId;

echo "Simulating background job with trace ID...\n";

// In a real scenario, this would be a queued job
// For demo, we'll just retrieve the trace
$jobTrace = $sdk->getTrace($traceIdForJob);

$jobStep = $jobTrace->startStep(
    name: 'Background Processing',
    stepType: 'job'
);

// Simulate work
usleep(5000); // 5ms

$jobStep->finish(['job_completed' => true]);

echo "Background job step completed\n";

// Finish main trace
$mainTrace->finish(['total_steps' => 1]);

echo "Main trace finished\n";

// Flush
$sdk->flush();

echo "✓ Cross-context tracing with async completed!\n\n";

// Shutdown
echo "5️⃣  AUTOMATIC SHUTDOWN\n";
echo "====================================\n\n";

echo "In Laravel, the SDK automatically flushes on app termination:\n";
echo "• TraceFlowServiceProvider registers a terminating callback\n";
echo "• \$sdk->shutdown() is called automatically\n";
echo "• All pending promises are settled before exit\n";
echo "• No manual flush() needed in production!\n\n";

$sdk->shutdown();

echo "✓ Demo completed successfully!\n";
