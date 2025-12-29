<?php

namespace Smartness\TraceFlow\Tests\Feature;

use PHPUnit\Framework\TestCase;
use Smartness\TraceFlow\TraceFlowSDK;

/**
 * Integration tests for async transport behavior
 *
 * These tests verify end-to-end async functionality
 */
class AsyncTransportIntegrationTest extends TestCase
{
    private function createSDK(array $config = []): TraceFlowSDK
    {
        $defaultConfig = [
            'transport' => 'http',
            'async_http' => true,
            'source' => 'integration-test',
            'endpoint' => 'http://localhost:3009',
            'timeout' => 5.0,
            'max_retries' => 3,
            'silent_errors' => true,
        ];

        return new TraceFlowSDK(array_merge($defaultConfig, $config));
    }

    public function test_complete_trace_lifecycle_with_async_transport(): void
    {
        $sdk = $this->createSDK();

        // Start trace
        $trace = $sdk->startTrace(
            traceType: 'integration_test',
            title: 'Complete Lifecycle Test',
            description: 'Testing async transport end-to-end',
            metadata: ['test' => true]
        );

        $this->assertNotNull($trace);
        $this->assertNotEmpty($trace->traceId);

        // Add a step
        $step = $trace->startStep(
            name: 'Test Step',
            stepType: 'test',
            input: ['data' => 'test']
        );

        $this->assertNotNull($step);

        // Log from step
        $step->log('Processing test data', 'INFO');

        // Finish step
        $step->finish(['processed' => true]);

        // Log from trace
        $trace->log('Test completed', 'INFO');

        // Finish trace
        $trace->finish(['success' => true]);

        // Flush and shutdown
        $sdk->flush();
        $sdk->shutdown();

        // Test passes if no exceptions thrown
        $this->assertTrue(true);
    }

    public function test_multiple_concurrent_traces(): void
    {
        $sdk = $this->createSDK();

        $traces = [];

        // Start 5 concurrent traces
        for ($i = 0; $i < 5; $i++) {
            $traces[] = $sdk->startTrace(
                title: "Concurrent Trace $i",
                metadata: ['index' => $i]
            );
        }

        // Add steps to each trace
        foreach ($traces as $i => $trace) {
            $step = $trace->startStep(name: "Step for trace $i");
            $step->finish(['index' => $i]);
        }

        // Finish all traces
        foreach ($traces as $trace) {
            $trace->finish(['completed' => true]);
        }

        $sdk->flush();

        $this->assertCount(5, $traces);
    }

    public function test_trace_with_failure(): void
    {
        $sdk = $this->createSDK();

        $trace = $sdk->startTrace(title: 'Failing Trace');

        $step = $trace->startStep(name: 'Failing Step');

        try {
            throw new \RuntimeException('Simulated error');
        } catch (\Exception $e) {
            $step->fail($e);
            $trace->fail($e);
        }

        $sdk->flush();

        $this->assertTrue(true);
    }

    public function test_trace_cancellation(): void
    {
        $sdk = $this->createSDK();

        $trace = $sdk->startTrace(title: 'Cancelled Trace');

        $step = $trace->startStep(name: 'Step');

        // Cancel trace
        $trace->cancel();

        $sdk->flush();

        $this->assertTrue(true);
    }

    public function test_nested_steps(): void
    {
        $sdk = $this->createSDK();

        $trace = $sdk->startTrace(title: 'Nested Steps Test');

        // Parent step
        $parentStep = $trace->startStep(name: 'Parent Step');
        $parentStep->log('Starting parent operation');

        // Child step 1
        $childStep1 = $trace->startStep(name: 'Child Step 1');
        $childStep1->finish(['result' => 'child1']);

        // Child step 2
        $childStep2 = $trace->startStep(name: 'Child Step 2');
        $childStep2->finish(['result' => 'child2']);

        // Finish parent
        $parentStep->finish(['children' => 2]);

        $trace->finish();

        $sdk->flush();

        $this->assertTrue(true);
    }

    public function test_performance_overhead_is_minimal(): void
    {
        $sdk = $this->createSDK();

        $iterations = 50;
        $timings = [];

        for ($i = 0; $i < $iterations; $i++) {
            $start = microtime(true);

            $trace = $sdk->startTrace(title: "Performance Test $i");
            $step = $trace->startStep(name: 'Step');
            $step->finish();
            $trace->finish();

            $duration = microtime(true) - $start;
            $timings[] = $duration * 1000; // Convert to ms
        }

        $avgTime = array_sum($timings) / count($timings);

        // Average overhead should be very low with async
        $this->assertLessThan(5, $avgTime, 'Average overhead should be <5ms with async transport');

        $sdk->flush();
    }

    public function test_context_propagation_across_sdk_methods(): void
    {
        $sdk = $this->createSDK();

        // Start trace
        $trace = $sdk->startTrace(title: 'Context Test');

        // getCurrentTrace should return the active trace
        $currentTrace = $sdk->getCurrentTrace();
        $this->assertNotNull($currentTrace);
        $this->assertEquals($trace->traceId, $currentTrace->traceId);

        // startStep should use current trace
        $step = $sdk->startStep(name: 'Context Step');
        $this->assertNotNull($step);
        $step->finish();

        // log should use current trace
        $sdk->log('Context log message');

        $trace->finish();

        $sdk->flush();

        $this->assertTrue(true);
    }

    public function test_get_trace_by_id(): void
    {
        $sdk = $this->createSDK();

        // Start initial trace
        $originalTrace = $sdk->startTrace(title: 'Original Trace');
        $traceId = $originalTrace->traceId;

        // Simulate retrieving trace in another context
        $retrievedTrace = $sdk->getTrace($traceId);

        $this->assertNotNull($retrievedTrace);
        $this->assertEquals($traceId, $retrievedTrace->traceId);

        // Should be able to add steps to retrieved trace
        $step = $retrievedTrace->startStep(name: 'Retrieved Context Step');
        $step->finish();

        $retrievedTrace->finish();

        $sdk->flush();

        $this->assertTrue(true);
    }

    public function test_run_with_trace_helper(): void
    {
        $sdk = $this->createSDK();

        $result = $sdk->runWithTrace(
            function ($trace) {
                $step = $trace->startStep(name: 'Helper Step');
                $step->finish();

                return 'operation_result';
            },
            [
                'title' => 'Helper Trace',
                'traceType' => 'helper_test',
            ]
        );

        $this->assertEquals('operation_result', $result);

        $sdk->flush();
    }

    public function test_heartbeat_functionality(): void
    {
        $sdk = $this->createSDK();

        $trace = $sdk->startTrace(title: 'Heartbeat Test');

        // Send heartbeat
        $sdk->heartbeat($trace->traceId);

        // Send another heartbeat using current trace
        $sdk->heartbeat();

        $trace->finish();

        $sdk->flush();

        $this->assertTrue(true);
    }

    public function test_async_vs_blocking_transport_selection(): void
    {
        // Test async (default)
        $asyncSDK = $this->createSDK(['async_http' => true]);

        $reflection = new \ReflectionClass($asyncSDK);
        $transport = $reflection->getProperty('transport');
        $transport->setAccessible(true);

        $this->assertInstanceOf(
            \Smartness\TraceFlow\Transport\AsyncHttpTransport::class,
            $transport->getValue($asyncSDK)
        );

        // Test blocking
        $blockingSDK = $this->createSDK(['async_http' => false]);

        $transport = $reflection->getProperty('transport');
        $transport->setAccessible(true);

        $this->assertInstanceOf(
            \Smartness\TraceFlow\Transport\HttpTransport::class,
            $transport->getValue($blockingSDK)
        );
    }

    public function test_silent_errors_prevents_exceptions(): void
    {
        // Create SDK with invalid endpoint
        $sdk = $this->createSDK([
            'endpoint' => 'http://invalid-host-that-does-not-exist:99999',
            'silent_errors' => true,
            'timeout' => 1.0,
            'max_retries' => 1,
        ]);

        // Should not throw exceptions
        $trace = $sdk->startTrace(title: 'Silent Error Test');
        $trace->finish();

        $sdk->flush();

        // Test passes if no exception thrown
        $this->assertTrue(true);
    }
}
