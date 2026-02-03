<?php

namespace Smartness\TraceFlow\Tests\Feature;

use PHPUnit\Framework\TestCase;
use Smartness\TraceFlow\TraceFlowSDK;

/**
 * Error handling and resilience tests
 *
 * Tests SDK behavior under various error conditions including
 * network failures, API errors, and invalid data
 */
class ErrorHandlingTest extends TestCase
{
    private function createSDK(array $config = []): TraceFlowSDK
    {
        $defaultConfig = [
            'transport' => 'http',
            'async_http' => true,
            'source' => 'error-test',
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

    public function test_graceful_degradation_with_network_errors()
    {
        // SDK configured with invalid endpoint but silent errors enabled
        $sdk = $this->createSDK([
            'endpoint' => 'http://non-existent-host-12345.invalid:99999',
            'timeout' => 1.0,
            'max_retries' => 1,
            'silent_errors' => true,
        ]);

        // Should not throw exceptions despite network errors
        $trace = $sdk->startTrace(
            traceId: $this->generateUniqueTraceId(),
            title: 'Network Error Test'
        );

        $step = $trace->startStep(name: 'Test Step');
        $step->log('This should be logged even if network fails');
        $step->finish();

        $trace->finish();
        $sdk->flush();

        $this->assertTrue(true);
    }

    public function test_handling_of_large_payloads()
    {
        $sdk = $this->createSDK();

        // Create trace with large metadata
        $largeMetadata = [
            'description' => str_repeat('A', 1000),
            'items' => array_fill(0, 100, ['id' => rand(), 'data' => str_repeat('X', 100)]),
            'nested' => [
                'level1' => [
                    'level2' => [
                        'level3' => array_fill(0, 50, 'data')
                    ]
                ]
            ]
        ];

        $trace = $sdk->startTrace(
            traceId: $this->generateUniqueTraceId(),
            title: 'Large Payload Test',
            metadata: $largeMetadata
        );

        $step = $trace->startStep(
            name: 'Large Data Step',
            input: ['large_array' => array_fill(0, 500, rand())]
        );

        $step->log('Processing large data', 'INFO', null, [
            'data_size' => 'large',
            'items' => array_fill(0, 100, rand())
        ]);

        $step->finish([
            'output_data' => array_fill(0, 500, ['result' => rand()])
        ]);

        $trace->finish();
        $sdk->flush();

        $this->assertTrue(true);
    }

    public function test_special_characters_in_messages()
    {
        $sdk = $this->createSDK();

        $trace = $sdk->startTrace(
            traceId: $this->generateUniqueTraceId(),
            title: 'Special Characters Test: "quotes" & <tags> & émojis 🚀'
        );

        $step = $trace->startStep(
            name: 'Unicode & Special Chars: 日本語 中文 العربية 🎉'
        );

        $step->log(
            'Message with special chars: \n\t\r " \' < > & © ® ™ € £ ¥',
            'INFO',
            null,
            [
                'json_special' => '{"key": "value with \"quotes\""}',
                'unicode' => '🌍 🌎 🌏 Hello World',
                'emoji_sequence' => '👨‍👩‍👧‍👦',
                'rtl_text' => 'مرحبا',
                'mathematical' => '∑ ∫ ∂ ∇ ∞',
            ]
        );

        $step->finish();
        $trace->finish();
        $sdk->flush();

        $this->assertTrue(true);
    }

    public function test_empty_and_null_values()
    {
        $sdk = $this->createSDK();

        $trace = $sdk->startTrace(
            traceId: $this->generateUniqueTraceId(),
            title: 'Empty Values Test',
            metadata: []  // Empty metadata
        );

        // Step with minimal data
        $step = $trace->startStep(name: '');  // Empty name

        $step->log('', 'INFO');  // Empty message (should still have defaults)

        $step->finish();  // No output data

        $trace->finish();  // No result

        $sdk->flush();

        $this->assertTrue(true);
    }

    public function test_concurrent_operations_on_same_trace()
    {
        $sdk = $this->createSDK();

        $trace = $sdk->startTrace(
            traceId: $this->generateUniqueTraceId(),
            title: 'Concurrent Operations Test'
        );

        // Create multiple steps rapidly
        $steps = [];
        for ($i = 0; $i < 5; $i++) {
            $steps[] = $trace->startStep(name: "Concurrent Step {$i}");
        }

        // Log from all steps concurrently
        foreach ($steps as $index => $step) {
            $step->log("Log from step {$index}", 'INFO');
        }

        // Finish all steps
        foreach ($steps as $index => $step) {
            $step->finish(['step_index' => $index]);
        }

        $trace->finish();
        $sdk->flush();

        $this->assertCount(5, $steps);
    }

    public function test_step_operations_without_active_trace()
    {
        $sdk = $this->createSDK();

        // Try to create step without active trace context
        $step = $sdk->startStep(name: 'Orphan Step');

        // Should return null or handle gracefully
        $this->assertNull($step);
    }

    public function test_rapid_trace_creation_and_completion()
    {
        $sdk = $this->createSDK();

        $count = 20;
        $start = microtime(true);

        for ($i = 0; $i < $count; $i++) {
            $trace = $sdk->startTrace(
                traceId: $this->generateUniqueTraceId(),
                title: "Rapid Trace {$i}"
            );

            $step = $trace->startStep(name: "Rapid Step {$i}");
            $step->finish();
            $trace->finish();
        }

        $duration = (microtime(true) - $start) * 1000;

        $sdk->flush();

        // Verify it completes in reasonable time (should be < 100ms with async)
        $this->assertLessThan(100, $duration / $count, 'Average trace creation should be < 100ms');
    }

    public function test_exception_in_callback_doesnt_break_sdk()
    {
        $sdk = $this->createSDK();

        try {
            $sdk->runWithTrace(function ($trace) {
                $trace->log('Before exception');
                throw new \RuntimeException('Test exception in callback');
            }, [
                'traceId' => $this->generateUniqueTraceId(),
                'title' => 'Exception in Callback Test'
            ]);
        } catch (\RuntimeException $e) {
            // Exception should be thrown
            $this->assertEquals('Test exception in callback', $e->getMessage());
        }

        // SDK should still be functional after exception
        $trace = $sdk->startTrace(
            traceId: $this->generateUniqueTraceId(),
            title: 'After Exception Test'
        );
        $trace->finish();

        $sdk->flush();

        $this->assertTrue(true);
    }

    public function test_multiple_flush_calls()
    {
        $sdk = $this->createSDK();

        $trace = $sdk->startTrace(
            traceId: $this->generateUniqueTraceId(),
            title: 'Multiple Flush Test'
        );

        $step = $trace->startStep(name: 'Test Step');
        $step->finish();

        // Call flush multiple times
        $sdk->flush();
        $sdk->flush();
        $sdk->flush();

        $trace->finish();

        // Flush again after trace finish
        $sdk->flush();

        $this->assertTrue(true);
    }

    public function test_shutdown_and_flush_order()
    {
        $sdk = $this->createSDK();

        $trace = $sdk->startTrace(
            traceId: $this->generateUniqueTraceId(),
            title: 'Shutdown Order Test'
        );

        $step = $trace->startStep(name: 'Test Step');
        $step->finish();
        $trace->finish();

        // Test different shutdown sequences
        $sdk->flush();
        $sdk->shutdown();

        $this->assertTrue(true);
    }

    public function test_context_switching_between_multiple_traces()
    {
        $sdk = $this->createSDK();

        $trace1 = $sdk->startTrace(
            traceId: $this->generateUniqueTraceId(),
            title: 'Trace 1'
        );

        // Current trace should be trace1
        $current = $sdk->getCurrentTrace();
        $this->assertEquals($trace1->traceId, $current->traceId);

        $trace2 = $sdk->startTrace(
            traceId: $this->generateUniqueTraceId(),
            title: 'Trace 2'
        );

        // Current trace should now be trace2
        $current = $sdk->getCurrentTrace();
        $this->assertEquals($trace2->traceId, $current->traceId);

        // Finish both traces
        $trace1->finish();
        $trace2->finish();

        $sdk->flush();
    }

    public function test_numeric_and_boolean_values_in_metadata()
    {
        $sdk = $this->createSDK();

        $trace = $sdk->startTrace(
            traceId: $this->generateUniqueTraceId(),
            title: 'Data Types Test',
            metadata: [
                'integer' => 12345,
                'float' => 123.45,
                'boolean_true' => true,
                'boolean_false' => false,
                'zero' => 0,
                'negative' => -999,
                'large_number' => 9223372036854775807,
                'scientific' => 1.23e-10,
                'mixed_array' => [1, 'two', 3.0, true, null],
            ]
        );

        $step = $trace->startStep(
            name: 'Type Test Step',
            input: [
                'count' => 100,
                'enabled' => true,
                'rate' => 0.95,
            ]
        );

        $step->finish([
            'success' => true,
            'items_processed' => 100,
            'error_rate' => 0.05,
        ]);

        $trace->finish();
        $sdk->flush();

        $this->assertTrue(true);
    }
}
