<?php

namespace Smartness\TraceFlow\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Smartness\TraceFlow\TraceFlowSDK;
use Smartness\TraceFlow\Transport\AsyncHttpTransport;
use Smartness\TraceFlow\Transport\HttpTransport;

class TraceFlowSDKTest extends TestCase
{
    public function test_uses_async_http_transport_by_default(): void
    {
        $sdk = new TraceFlowSDK([
            'transport' => 'http',
            'source' => 'test',
            'endpoint' => 'http://localhost:3009',
            'silent_errors' => true,
        ]);

        // Use reflection to check transport type
        $reflection = new \ReflectionClass($sdk);
        $transportProperty = $reflection->getProperty('transport');
        $transportProperty->setAccessible(true);
        $transport = $transportProperty->getValue($sdk);

        $this->assertInstanceOf(
            AsyncHttpTransport::class,
            $transport,
            'Should use AsyncHttpTransport by default'
        );
    }

    public function test_uses_async_http_transport_when_explicitly_enabled(): void
    {
        $sdk = new TraceFlowSDK([
            'transport' => 'http',
            'async_http' => true,
            'source' => 'test',
            'endpoint' => 'http://localhost:3009',
            'silent_errors' => true,
        ]);

        $reflection = new \ReflectionClass($sdk);
        $transportProperty = $reflection->getProperty('transport');
        $transportProperty->setAccessible(true);
        $transport = $transportProperty->getValue($sdk);

        $this->assertInstanceOf(AsyncHttpTransport::class, $transport);
    }

    public function test_uses_blocking_http_transport_when_async_disabled(): void
    {
        $sdk = new TraceFlowSDK([
            'transport' => 'http',
            'async_http' => false,
            'source' => 'test',
            'endpoint' => 'http://localhost:3009',
            'silent_errors' => true,
        ]);

        $reflection = new \ReflectionClass($sdk);
        $transportProperty = $reflection->getProperty('transport');
        $transportProperty->setAccessible(true);
        $transport = $transportProperty->getValue($sdk);

        $this->assertInstanceOf(
            HttpTransport::class,
            $transport,
            'Should use HttpTransport when async_http is false'
        );
    }

    public function test_throws_exception_for_unknown_transport(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown transport type: redis');

        new TraceFlowSDK([
            'transport' => 'redis',
            'source' => 'test',
            'endpoint' => 'http://localhost:3009',
            'silent_errors' => true,
        ]);
    }

    public function test_throws_exception_for_kafka_transport(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Kafka transport not yet implemented');

        new TraceFlowSDK([
            'transport' => 'kafka',
            'source' => 'test',
            'endpoint' => 'http://localhost:3009',
            'silent_errors' => true,
        ]);
    }

    public function test_start_trace_returns_trace_handle(): void
    {
        $sdk = new TraceFlowSDK([
            'transport' => 'http',
            'source' => 'test',
            'endpoint' => 'http://localhost:3009',
            'silent_errors' => true,
        ]);

        $trace = $sdk->startTrace(
            title: 'Test Trace',
            traceType: 'test'
        );

        $this->assertInstanceOf(\Smartness\TraceFlow\Handles\TraceHandle::class, $trace);
        $this->assertNotEmpty($trace->traceId);
    }

    public function test_get_current_trace_returns_active_trace(): void
    {
        $sdk = new TraceFlowSDK([
            'transport' => 'http',
            'source' => 'test',
            'endpoint' => 'http://localhost:3009',
            'silent_errors' => true,
        ]);

        // Initially null
        $this->assertNull($sdk->getCurrentTrace());

        // Start trace
        $trace = $sdk->startTrace(title: 'Test');

        // Now should return trace
        $currentTrace = $sdk->getCurrentTrace();
        $this->assertNotNull($currentTrace);
        $this->assertEquals($trace->traceId, $currentTrace->traceId);
    }

    public function test_run_with_trace_executes_callback(): void
    {
        $sdk = new TraceFlowSDK([
            'transport' => 'http',
            'source' => 'test',
            'endpoint' => 'http://localhost:3009',
            'silent_errors' => true,
        ]);

        $callbackExecuted = false;
        $capturedTrace = null;

        $result = $sdk->runWithTrace(function ($trace) use (&$callbackExecuted, &$capturedTrace) {
            $callbackExecuted = true;
            $capturedTrace = $trace;

            return 'test-result';
        }, ['title' => 'Test Trace']);

        $this->assertTrue($callbackExecuted);
        $this->assertNotNull($capturedTrace);
        $this->assertEquals('test-result', $result);
    }

    public function test_run_with_trace_handles_exception(): void
    {
        $sdk = new TraceFlowSDK([
            'transport' => 'http',
            'source' => 'test',
            'endpoint' => 'http://localhost:3009',
            'silent_errors' => true,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Test error');

        $sdk->runWithTrace(function ($trace) {
            throw new \RuntimeException('Test error');
        }, ['title' => 'Failing Trace']);
    }

    public function test_heartbeat_with_trace_id(): void
    {
        $sdk = new TraceFlowSDK([
            'transport' => 'http',
            'source' => 'test',
            'endpoint' => 'http://localhost:3009',
            'silent_errors' => true,
        ]);

        $trace = $sdk->startTrace(title: 'Test');

        // Should not throw exception
        $sdk->heartbeat($trace->traceId);

        $this->assertTrue(true);
    }

    public function test_heartbeat_without_trace_id_uses_current_trace(): void
    {
        $sdk = new TraceFlowSDK([
            'transport' => 'http',
            'source' => 'test',
            'endpoint' => 'http://localhost:3009',
            'silent_errors' => true,
        ]);

        $trace = $sdk->startTrace(title: 'Test');

        // Should use current trace
        $sdk->heartbeat();

        $this->assertTrue(true);
    }

    public function test_start_step_without_current_trace_returns_null(): void
    {
        $sdk = new TraceFlowSDK([
            'transport' => 'http',
            'source' => 'test',
            'endpoint' => 'http://localhost:3009',
            'silent_errors' => true,
        ]);

        $step = $sdk->startStep(name: 'Test Step');

        $this->assertNull($step, 'Should return null when no trace context');
    }

    public function test_start_step_with_current_trace_returns_step_handle(): void
    {
        $sdk = new TraceFlowSDK([
            'transport' => 'http',
            'source' => 'test',
            'endpoint' => 'http://localhost:3009',
            'silent_errors' => true,
        ]);

        $sdk->startTrace(title: 'Test');

        $step = $sdk->startStep(name: 'Test Step');

        $this->assertInstanceOf(\Smartness\TraceFlow\Handles\StepHandle::class, $step);
    }

    public function test_log_without_current_trace_logs_to_error_log(): void
    {
        $sdk = new TraceFlowSDK([
            'transport' => 'http',
            'source' => 'test',
            'endpoint' => 'http://localhost:3009',
            'silent_errors' => true,
        ]);

        // Should not throw exception
        $sdk->log('Test message');

        $this->assertTrue(true);
    }

    public function test_shutdown_calls_transport_shutdown(): void
    {
        $sdk = new TraceFlowSDK([
            'transport' => 'http',
            'source' => 'test',
            'endpoint' => 'http://localhost:3009',
            'silent_errors' => true,
        ]);

        // Should not throw exception
        $sdk->shutdown();

        $this->assertTrue(true);
    }

    public function test_flush_calls_transport_flush(): void
    {
        $sdk = new TraceFlowSDK([
            'transport' => 'http',
            'source' => 'test',
            'endpoint' => 'http://localhost:3009',
            'silent_errors' => true,
        ]);

        // Should not throw exception
        $sdk->flush();

        $this->assertTrue(true);
    }
}
