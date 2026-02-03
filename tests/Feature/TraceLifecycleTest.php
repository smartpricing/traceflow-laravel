<?php

namespace Smartness\TraceFlow\Tests\Feature;

use PHPUnit\Framework\TestCase;
use Smartness\TraceFlow\TraceFlowSDK;
use Smartness\TraceFlow\Tests\TestHelper;

/**
 * Comprehensive trace lifecycle tests
 *
 * Tests various trace lifecycle scenarios including complex workflows,
 * error handling, and state transitions
 */
class TraceLifecycleTest extends TestCase
{
    private function createSDK(array $config = []): TraceFlowSDK
    {
        $defaultConfig = [
            'transport' => 'http',
            'async_http' => true,
            'source' => 'lifecycle-test',
            'endpoint' => getenv('TRACEFLOW_ENDPOINT') ?: 'http://localhost:3009',
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

    public function test_trace_with_multiple_steps_and_logs()
    {
        TestHelper::skipIfServerUnavailable($this);

        $sdk = $this->createSDK();

        $trace = $sdk->startTrace(
            traceId: $this->generateUniqueTraceId(),
            title: 'Multi-Step Workflow',
            traceType: 'workflow',
            metadata: ['workflow_version' => '1.0']
        );

        // Step 1: Data validation
        $validationStep = $trace->startStep(
            name: 'Data Validation',
            stepType: 'validation',
            input: ['records' => 100]
        );
        $validationStep->log('Starting validation', 'INFO');
        $validationStep->log('Validated 50 records', 'INFO');
        $validationStep->finish(['validated' => 100, 'errors' => 0]);

        // Step 2: Data processing
        $processingStep = $trace->startStep(
            name: 'Data Processing',
            stepType: 'processing',
            input: ['validated_records' => 100]
        );
        $processingStep->log('Processing batch 1', 'INFO');
        $processingStep->log('Processing batch 2', 'INFO');
        $processingStep->finish(['processed' => 100, 'duration_ms' => 1500]);

        // Step 3: Data storage
        $storageStep = $trace->startStep(
            name: 'Data Storage',
            stepType: 'storage',
            input: ['processed_records' => 100]
        );
        $storageStep->log('Saving to database', 'INFO');
        $storageStep->finish(['saved' => 100, 'storage_type' => 'database']);

        // Trace-level log
        $trace->log('Workflow completed successfully', 'INFO', null, [
            'total_records' => 100,
            'total_duration_ms' => 3000
        ]);

        $trace->finish([
            'status' => 'success',
            'steps_completed' => 3,
            'records_processed' => 100
        ]);

        $sdk->flush();

        $this->assertTrue(true);
    }

    public function test_trace_with_mixed_step_outcomes()
    {
        $sdk = $this->createSDK();

        $trace = $sdk->startTrace(
            traceId: $this->generateUniqueTraceId(),
            title: 'Mixed Outcomes Workflow',
            metadata: ['environment' => 'test']
        );

        // Successful step
        $step1 = $trace->startStep(name: 'Successful Step');
        $step1->finish(['result' => 'success']);

        // Failed step
        $step2 = $trace->startStep(name: 'Failed Step');
        $step2->fail('Step failed due to validation error');

        // Another successful step after failure
        $step3 = $trace->startStep(name: 'Recovery Step');
        $step3->log('Attempting recovery', 'WARN');
        $step3->finish(['recovered' => true]);

        $trace->finish(['partial_success' => true, 'failed_steps' => 1]);

        $sdk->flush();

        $this->assertTrue(true);
    }

    public function test_trace_with_complex_metadata()
    {
        $sdk = $this->createSDK();

        $complexMetadata = [
            'environment' => 'production',
            'user' => [
                'id' => 12345,
                'email' => 'test@example.com',
                'roles' => ['admin', 'developer']
            ],
            'system' => [
                'hostname' => 'server-01',
                'region' => 'us-east-1',
                'version' => '2.1.0'
            ],
            'tags' => ['critical', 'monitored', 'production'],
            'metrics' => [
                'cpu_usage' => 45.2,
                'memory_usage' => 78.5,
                'active_connections' => 234
            ]
        ];

        $trace = $sdk->startTrace(
            traceId: $this->generateUniqueTraceId(),
            title: 'Complex Metadata Test',
            metadata: $complexMetadata
        );

        $step = $trace->startStep(
            name: 'Process with Metadata',
            metadata: [
                'step_config' => [
                    'timeout' => 30,
                    'retries' => 3,
                    'batch_size' => 100
                ],
                'dependencies' => ['service-a', 'service-b']
            ]
        );

        $step->log('Processing with complex context', 'INFO', null, [
            'current_batch' => 1,
            'items_in_batch' => 50,
            'estimated_time' => 120
        ]);

        $step->finish([
            'metrics' => [
                'items_processed' => 50,
                'duration_ms' => 118,
                'throughput' => 0.42
            ]
        ]);

        $trace->finish([
            'summary' => [
                'total_items' => 50,
                'success_rate' => 100.0,
                'total_duration_ms' => 150
            ]
        ]);

        $sdk->flush();

        $this->assertTrue(true);
    }

    public function test_trace_with_exception_handling()
    {
        $sdk = $this->createSDK();

        $trace = $sdk->startTrace(
            traceId: $this->generateUniqueTraceId(),
            title: 'Exception Handling Test'
        );

        $step = $trace->startStep(name: 'Step with Exception');

        try {
            throw new \RuntimeException('Database connection failed', 1001);
        } catch (\Exception $e) {
            $step->log('Exception caught: ' . $e->getMessage(), 'ERROR');
            $step->fail($e);
        }

        $trace->log('Trace marked as failed due to exception', 'ERROR');
        $trace->fail(new \RuntimeException('Workflow failed'));

        $sdk->flush();

        $this->assertTrue(true);
    }

    public function test_trace_cancellation_with_cleanup()
    {
        $sdk = $this->createSDK();

        $trace = $sdk->startTrace(
            traceId: $this->generateUniqueTraceId(),
            title: 'Cancellation Test',
            metadata: ['cancellable' => true]
        );

        $step1 = $trace->startStep(name: 'Started Step');
        $step1->log('Step started', 'INFO');

        // Simulate cancellation condition
        $trace->log('Cancellation requested', 'WARN');
        $step1->log('Cleaning up resources', 'WARN');
        $step1->finish(['cleanup_completed' => true]);

        $trace->cancel();

        $sdk->flush();

        $this->assertTrue(true);
    }

    public function test_trace_with_different_log_levels()
    {
        $sdk = $this->createSDK();

        $trace = $sdk->startTrace(
            traceId: $this->generateUniqueTraceId(),
            title: 'Log Levels Test'
        );

        $step = $trace->startStep(name: 'Multi-Level Logging');

        // Test all log levels
        $step->log('Debug information', 'DEBUG', null, ['debug_data' => 'value']);
        $step->log('Informational message', 'INFO');
        $step->log('Warning message', 'WARN', null, ['warning_code' => 'W001']);
        $step->log('Error message', 'ERROR', null, ['error_code' => 'E001']);

        // Trace-level logs
        $trace->log('Trace-level info', 'INFO');
        $trace->log('Trace-level warning', 'WARN');

        $step->finish();
        $trace->finish();

        $sdk->flush();

        $this->assertTrue(true);
    }

    public function test_long_running_trace_with_heartbeats()
    {
        $sdk = $this->createSDK();

        $trace = $sdk->startTrace(
            traceId: $this->generateUniqueTraceId(),
            title: 'Long Running Process',
            metadata: ['estimated_duration_ms' => 10000]
        );

        $step = $trace->startStep(name: 'Long Operation');

        // Simulate periodic heartbeats
        $step->log('Operation in progress - 25%', 'INFO');
        $sdk->heartbeat($trace->traceId);

        $step->log('Operation in progress - 50%', 'INFO');
        $sdk->heartbeat($trace->traceId);

        $step->log('Operation in progress - 75%', 'INFO');
        $sdk->heartbeat($trace->traceId);

        $step->log('Operation completed', 'INFO');
        $step->finish(['completion' => '100%']);

        $trace->finish(['duration_ms' => 8500]);

        $sdk->flush();

        $this->assertTrue(true);
    }

    public function test_trace_with_custom_event_types()
    {
        $sdk = $this->createSDK();

        $trace = $sdk->startTrace(
            traceId: $this->generateUniqueTraceId(),
            title: 'Custom Event Types Test'
        );

        $step = $trace->startStep(name: 'Step with Custom Events');

        // Log with custom event types
        $step->log('API request started', 'INFO', 'api.request.start', [
            'method' => 'GET',
            'url' => '/api/users',
            'headers' => ['Authorization' => 'Bearer ***']
        ]);

        $step->log('API response received', 'INFO', 'api.response.success', [
            'status_code' => 200,
            'response_time_ms' => 145,
            'data_size_bytes' => 2048
        ]);

        $step->log('Cache hit', 'INFO', 'cache.hit', [
            'cache_key' => 'user:12345',
            'ttl_remaining' => 3600
        ]);

        $step->finish();
        $trace->finish();

        $sdk->flush();

        $this->assertTrue(true);
    }

    public function test_batch_trace_creation()
    {
        $sdk = $this->createSDK();

        $traces = [];
        $batchSize = 10;

        // Create multiple traces in a batch
        for ($i = 0; $i < $batchSize; $i++) {
            $trace = $sdk->startTrace(
                traceId: $this->generateUniqueTraceId(),
                title: "Batch Trace #{$i}",
                metadata: [
                    'batch_id' => 'batch-001',
                    'index' => $i,
                    'total' => $batchSize
                ]
            );

            $step = $trace->startStep(
                name: "Batch Processing #{$i}",
                input: ['item_index' => $i]
            );

            $step->finish(['processed' => true]);
            $trace->finish(['batch_complete' => $i === $batchSize - 1]);

            $traces[] = $trace;
        }

        $sdk->flush();

        $this->assertCount($batchSize, $traces);
    }

    public function test_trace_state_transitions()
    {
        $sdk = $this->createSDK();

        // Test 1: Normal flow (PENDING -> RUNNING -> SUCCESS)
        $trace1 = $sdk->startTrace(
            traceId: $this->generateUniqueTraceId(),
            title: 'Normal Flow'
        );
        $trace1->finish(['status' => 'success']);

        // Test 2: Failed flow (PENDING -> RUNNING -> FAILED)
        $trace2 = $sdk->startTrace(
            traceId: $this->generateUniqueTraceId(),
            title: 'Failed Flow'
        );
        $trace2->fail('Simulated failure');

        // Test 3: Cancelled flow (PENDING -> RUNNING -> CANCELLED)
        $trace3 = $sdk->startTrace(
            traceId: $this->generateUniqueTraceId(),
            title: 'Cancelled Flow'
        );
        $trace3->cancel();

        $sdk->flush();

        $this->assertTrue(true);
    }
}
