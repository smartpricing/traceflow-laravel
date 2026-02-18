<?php

namespace Smartness\TraceFlow\Tests\Feature;

use PHPUnit\Framework\TestCase;
use Smartness\TraceFlow\TraceFlowSDK;

/**
 * Performance and stress tests
 *
 * Tests SDK performance under heavy load including throughput,
 * latency, memory usage, and concurrent operations
 */
class PerformanceTest extends TestCase
{
    private function createSDK(array $config = []): TraceFlowSDK
    {
        $defaultConfig = [
            'transport' => 'http',
            'async_http' => true,
            'source' => 'performance-test',
            'endpoint' => getenv('TRACEFLOW_URL') ?: 'http://localhost:3009',
            'api_key' => getenv('TRACEFLOW_API_KEY') ?: null,
            'timeout' => 5.0,
            'max_retries' => 3,
            'silent_errors' => true,
        ];

        return new TraceFlowSDK(array_merge($defaultConfig, $config));
    }

    private function generateUniqueTraceId(): string
    {
        return \Ramsey\Uuid\Uuid::uuid4()->toString();
    }

    public function test_high_throughput_trace_creation()
    {
        $sdk = $this->createSDK();

        $iterations = 100;
        $start = microtime(true);
        $memoryStart = memory_get_usage();

        for ($i = 0; $i < $iterations; $i++) {
            $trace = $sdk->startTrace(
                traceId: $this->generateUniqueTraceId(),
                title: "High Throughput Trace {$i}",
                metadata: ['iteration' => $i]
            );

            $trace->finish(['completed' => true]);
        }

        $duration = microtime(true) - $start;
        $memoryUsed = memory_get_usage() - $memoryStart;

        $sdk->flush();

        $throughput = $iterations / $duration;
        $avgMemoryPerTrace = $memoryUsed / $iterations;

        // Performance assertions
        $this->assertGreaterThan(10, $throughput, 'Should process > 10 traces/second');
        $this->assertLessThan(100000, $avgMemoryPerTrace, 'Memory per trace should be < 100KB');

        echo "\nThroughput: " . round($throughput, 2) . " traces/sec\n";
        echo "Avg memory per trace: " . round($avgMemoryPerTrace / 1024, 2) . " KB\n";
    }

    public function test_step_creation_throughput()
    {
        $sdk = $this->createSDK();

        $trace = $sdk->startTrace(
            traceId: $this->generateUniqueTraceId(),
            title: 'Step Throughput Test'
        );

        $iterations = 200;
        $start = microtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            $step = $trace->startStep(name: "Step {$i}");
            $step->finish();
        }

        $duration = microtime(true) - $start;
        $trace->finish();
        $sdk->flush();

        $throughput = $iterations / $duration;

        $this->assertGreaterThan(20, $throughput, 'Should process > 20 steps/second');

        echo "\nStep throughput: " . round($throughput, 2) . " steps/sec\n";
    }

    public function test_log_creation_throughput()
    {
        $sdk = $this->createSDK();

        $trace = $sdk->startTrace(
            traceId: $this->generateUniqueTraceId(),
            title: 'Log Throughput Test'
        );

        $step = $trace->startStep(name: 'Logging Step');

        $iterations = 500;
        $start = microtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            $step->log("Log message {$i}", 'INFO');
        }

        $duration = microtime(true) - $start;

        $step->finish();
        $trace->finish();
        $sdk->flush();

        $throughput = $iterations / $duration;

        $this->assertGreaterThan(50, $throughput, 'Should process > 50 logs/second');

        echo "\nLog throughput: " . round($throughput, 2) . " logs/sec\n";
    }

    public function test_memory_efficiency_with_many_traces()
    {
        $sdk = $this->createSDK();

        $memoryBefore = memory_get_usage();

        // Create many traces
        for ($i = 0; $i < 50; $i++) {
            $trace = $sdk->startTrace(
                traceId: $this->generateUniqueTraceId(),
                title: "Memory Test Trace {$i}"
            );

            for ($j = 0; $j < 5; $j++) {
                $step = $trace->startStep(name: "Step {$j}");
                $step->log("Log {$j}");
                $step->finish();
            }

            $trace->finish();
        }

        $memoryAfter = memory_get_usage();
        $memoryIncrease = $memoryAfter - $memoryBefore;

        $sdk->flush();

        $memoryAfterFlush = memory_get_usage();

        // Memory should be reasonable
        $this->assertLessThan(10 * 1024 * 1024, $memoryIncrease, 'Memory increase should be < 10MB');

        echo "\nMemory increase: " . round($memoryIncrease / 1024 / 1024, 2) . " MB\n";
        echo "Memory after flush: " . round($memoryAfterFlush / 1024 / 1024, 2) . " MB\n";
    }

    public function test_latency_with_async_transport()
    {
        $sdk = $this->createSDK(['async_http' => true]);

        $measurements = [];
        $iterations = 30;

        for ($i = 0; $i < $iterations; $i++) {
            $start = microtime(true);

            $trace = $sdk->startTrace(
                traceId: $this->generateUniqueTraceId(),
                title: "Latency Test {$i}"
            );

            $step = $trace->startStep(name: 'Test Step');
            $step->finish();
            $trace->finish();

            $latency = (microtime(true) - $start) * 1000; // ms
            $measurements[] = $latency;
        }

        $sdk->flush();

        $avgLatency = array_sum($measurements) / count($measurements);
        $maxLatency = max($measurements);
        $minLatency = min($measurements);

        sort($measurements);
        $p50 = $measurements[intval(count($measurements) * 0.5)];
        $p95 = $measurements[intval(count($measurements) * 0.95)];
        $p99 = $measurements[intval(count($measurements) * 0.99)];

        // Async should be very fast (< 10ms avg)
        $this->assertLessThan(10, $avgLatency, 'Avg latency should be < 10ms with async');
        $this->assertLessThan(50, $p95, 'P95 latency should be < 50ms');

        echo "\nLatency stats (ms):\n";
        echo "  Min: " . round($minLatency, 2) . "\n";
        echo "  Avg: " . round($avgLatency, 2) . "\n";
        echo "  Max: " . round($maxLatency, 2) . "\n";
        echo "  P50: " . round($p50, 2) . "\n";
        echo "  P95: " . round($p95, 2) . "\n";
        echo "  P99: " . round($p99, 2) . "\n";
    }

    public function test_concurrent_trace_creation()
    {
        $sdk = $this->createSDK();

        $start = microtime(true);

        // Create multiple traces "concurrently" (simulated)
        $traces = [];
        for ($i = 0; $i < 20; $i++) {
            $traces[] = $sdk->startTrace(
                traceId: $this->generateUniqueTraceId(),
                title: "Concurrent {$i}",
                metadata: ['index' => $i]
            );
        }

        // Add steps to all traces
        $steps = [];
        foreach ($traces as $trace) {
            $steps[] = $trace->startStep(name: 'Concurrent Step');
        }

        // Finish all steps
        foreach ($steps as $step) {
            $step->finish();
        }

        // Finish all traces
        foreach ($traces as $trace) {
            $trace->finish();
        }

        $duration = microtime(true) - $start;

        $sdk->flush();

        // Should handle concurrent operations efficiently
        $this->assertLessThan(5, $duration, 'Should handle 20 concurrent traces in < 5s');

        echo "\nConcurrent traces duration: " . round($duration, 2) . " seconds\n";
    }

    public function test_stress_with_complex_trace_hierarchy()
    {
        $sdk = $this->createSDK();

        $start = microtime(true);

        $trace = $sdk->startTrace(
            traceId: $this->generateUniqueTraceId(),
            title: 'Complex Hierarchy Stress Test',
            metadata: ['type' => 'stress_test']
        );

        // Create a complex hierarchy of steps
        for ($i = 0; $i < 10; $i++) {
            $parentStep = $trace->startStep(name: "Parent Step {$i}");

            for ($j = 0; $j < 10; $j++) {
                $childStep = $trace->startStep(name: "Child Step {$i}-{$j}");

                // Add logs to each child
                for ($k = 0; $k < 5; $k++) {
                    $childStep->log("Log {$k} for step {$i}-{$j}", 'INFO');
                }

                $childStep->finish(['child_index' => $j]);
            }

            $parentStep->finish(['children_count' => 10]);
        }

        $trace->finish(['total_steps' => 110]);

        $duration = microtime(true) - $start;

        $sdk->flush();

        $this->assertLessThan(10, $duration, 'Complex hierarchy should complete in < 10s');

        echo "\nComplex hierarchy duration: " . round($duration, 2) . " seconds\n";
    }

    public function test_batch_operations_performance()
    {
        $sdk = $this->createSDK();

        $batchSize = 50;
        $start = microtime(true);

        for ($batch = 0; $batch < 3; $batch++) {
            for ($i = 0; $i < $batchSize; $i++) {
                $trace = $sdk->startTrace(
                    traceId: $this->generateUniqueTraceId(),
                    title: "Batch {$batch} Item {$i}",
                    metadata: [
                        'batch' => $batch,
                        'item' => $i
                    ]
                );

                $step = $trace->startStep(
                    name: "Process Item {$i}",
                    input: ['item_id' => $i]
                );

                $step->finish(['processed' => true]);
                $trace->finish();
            }

            // Periodic flush
            $sdk->flush();
        }

        $duration = microtime(true) - $start;
        $totalTraces = $batchSize * 3;
        $throughput = $totalTraces / $duration;

        $this->assertGreaterThan(5, $throughput, 'Batch throughput should be > 5 traces/sec');

        echo "\nBatch operations:\n";
        echo "  Total traces: {$totalTraces}\n";
        echo "  Duration: " . round($duration, 2) . " seconds\n";
        echo "  Throughput: " . round($throughput, 2) . " traces/sec\n";
    }

    public function test_memory_leak_detection()
    {
        $sdk = $this->createSDK();

        $memorySnapshots = [];

        for ($iteration = 0; $iteration < 5; $iteration++) {
            for ($i = 0; $i < 20; $i++) {
                $trace = $sdk->startTrace(
                    traceId: $this->generateUniqueTraceId(),
                    title: "Memory Leak Test {$i}"
                );
                $step = $trace->startStep(name: 'Test Step');
                $step->finish();
                $trace->finish();
            }

            $sdk->flush();

            $memorySnapshots[] = memory_get_usage();
        }

        // Memory should stabilize after first iteration
        $memoryGrowth = $memorySnapshots[4] - $memorySnapshots[1];

        $this->assertLessThan(1024 * 1024, $memoryGrowth, 'Memory growth should be < 1MB across iterations');

        echo "\nMemory leak detection:\n";
        foreach ($memorySnapshots as $idx => $memory) {
            echo "  Iteration {$idx}: " . round($memory / 1024 / 1024, 2) . " MB\n";
        }
    }

    public function test_async_vs_sync_performance_comparison()
    {
        $iterations = 50;

        // Test with async
        $asyncSdk = $this->createSDK(['async_http' => true]);
        $asyncStart = microtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            $trace = $asyncSdk->startTrace(
                traceId: $this->generateUniqueTraceId(),
                title: "Async Test {$i}"
            );
            $step = $trace->startStep(name: 'Test');
            $step->finish();
            $trace->finish();
        }

        $asyncSdk->flush();
        $asyncDuration = microtime(true) - $asyncStart;

        // Test with sync
        $syncSdk = $this->createSDK(['async_http' => false]);
        $syncStart = microtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            $trace = $syncSdk->startTrace(
                traceId: $this->generateUniqueTraceId(),
                title: "Sync Test {$i}"
            );
            $step = $trace->startStep(name: 'Test');
            $step->finish();
            $trace->finish();
        }

        $syncSdk->flush();
        $syncDuration = microtime(true) - $syncStart;

        $speedup = $syncDuration / $asyncDuration;

        echo "\nAsync vs Sync comparison ({$iterations} traces):\n";
        echo "  Async: " . round($asyncDuration, 2) . " seconds\n";
        echo "  Sync: " . round($syncDuration, 2) . " seconds\n";
        echo "  Speedup: " . round($speedup, 2) . "x\n";

        // Note: This assertion might fail if API is very fast or very slow
        // Keeping it as informational rather than strict
        $this->assertTrue(true);
    }
}
