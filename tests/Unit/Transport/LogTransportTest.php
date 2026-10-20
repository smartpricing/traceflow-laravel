<?php

namespace Smartness\TraceFlow\Tests\Unit\Transport;

use Illuminate\Support\Facades\Log;
use Orchestra\Testbench\TestCase;
use Smartness\TraceFlow\DTO\TraceEvent;
use Smartness\TraceFlow\Enums\TraceEventType;
use Smartness\TraceFlow\Transport\LogTransport;

class LogTransportTest extends TestCase
{
    private const TIMESTAMP = '2026-03-24T12:00:00.000Z';

    // -------------------------------------------------------------------------
    // Factory helpers
    // -------------------------------------------------------------------------

    private function makeTransport(array $config = []): LogTransport
    {
        return new LogTransport(array_merge([
            'log_channel' => 'stack',
            'log_level' => 'info',
        ], $config));
    }

    private function makeEvent(
        TraceEventType $type,
        string $traceId = 'trace-abc',
        array $payload = [],
        ?string $stepId = null,
        string $eventId = 'event-1',
    ): TraceEvent {
        return new TraceEvent(
            eventId: $eventId,
            eventType: $type,
            traceId: $traceId,
            timestamp: self::TIMESTAMP,
            source: 'test-service',
            payload: $payload,
            stepId: $stepId,
        );
    }

    // -------------------------------------------------------------------------
    // Basic send behaviour
    // -------------------------------------------------------------------------

    public function test_send_writes_to_log(): void
    {
        Log::shouldReceive('channel')
            ->with('stack')
            ->once()
            ->andReturnSelf();

        Log::shouldReceive('log')
            ->withArgs(function (string $level, string $message, array $context) {
                return $level === 'info'
                    && str_contains($message, '[TraceFlow] trace_started')
                    && str_contains($message, 'trace=trace-abc')
                    && $context['trace_id'] === 'trace-abc'
                    && $context['event_type'] === 'trace_started'
                    && $context['event_id'] === 'event-1'
                    && $context['source'] === 'test-service';
            })
            ->once();

        $transport = $this->makeTransport();
        $transport->send($this->makeEvent(TraceEventType::TRACE_STARTED));
    }

    // -------------------------------------------------------------------------
    // Custom channel and level
    // -------------------------------------------------------------------------

    public function test_uses_custom_channel(): void
    {
        Log::shouldReceive('channel')
            ->with('daily')
            ->once()
            ->andReturnSelf();

        Log::shouldReceive('log')
            ->withArgs(fn (string $level) => $level === 'debug')
            ->once();

        $transport = $this->makeTransport([
            'log_channel' => 'daily',
            'log_level' => 'debug',
        ]);

        $transport->send($this->makeEvent(TraceEventType::TRACE_STARTED));
    }

    // -------------------------------------------------------------------------
    // Trace lifecycle messages
    // -------------------------------------------------------------------------

    public function test_trace_started_includes_title_and_type(): void
    {
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('log')
            ->withArgs(function (string $level, string $message) {
                return str_contains($message, 'title="My Trace"')
                    && str_contains($message, 'type=api_request');
            })
            ->once();

        $this->makeTransport()->send($this->makeEvent(
            TraceEventType::TRACE_STARTED,
            'trace-abc',
            ['title' => 'My Trace', 'trace_type' => 'api_request'],
        ));
    }

    public function test_trace_finished_message(): void
    {
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('log')
            ->withArgs(function (string $level, string $message) {
                return str_contains($message, '[TraceFlow] trace_finished')
                    && str_contains($message, 'trace=trace-abc');
            })
            ->once();

        $this->makeTransport()->send($this->makeEvent(TraceEventType::TRACE_FINISHED));
    }

    public function test_trace_failed_includes_error(): void
    {
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('log')
            ->withArgs(function (string $level, string $message) {
                return str_contains($message, 'trace_failed')
                    && str_contains($message, 'error="Something exploded"');
            })
            ->once();

        $this->makeTransport()->send($this->makeEvent(
            TraceEventType::TRACE_FAILED,
            'trace-abc',
            ['error' => 'Something exploded'],
        ));
    }

    public function test_trace_cancelled_message(): void
    {
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('log')
            ->withArgs(function (string $level, string $message) {
                return str_contains($message, 'trace_cancelled')
                    && str_contains($message, 'trace=trace-abc');
            })
            ->once();

        $this->makeTransport()->send($this->makeEvent(TraceEventType::TRACE_CANCELLED));
    }

    // -------------------------------------------------------------------------
    // Step lifecycle messages
    // -------------------------------------------------------------------------

    public function test_step_started_includes_name_and_type(): void
    {
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('log')
            ->withArgs(function (string $level, string $message) {
                return str_contains($message, 'step_started')
                    && str_contains($message, 'step=step-1')
                    && str_contains($message, 'name="Validate input"')
                    && str_contains($message, 'type=validation');
            })
            ->once();

        $this->makeTransport()->send($this->makeEvent(
            TraceEventType::STEP_STARTED,
            'trace-abc',
            ['name' => 'Validate input', 'step_type' => 'validation'],
            'step-1',
        ));
    }

    public function test_step_finished_message(): void
    {
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('log')
            ->withArgs(function (string $level, string $message) {
                return str_contains($message, 'step_finished')
                    && str_contains($message, 'step=step-1');
            })
            ->once();

        $this->makeTransport()->send($this->makeEvent(
            TraceEventType::STEP_FINISHED,
            'trace-abc',
            [],
            'step-1',
        ));
    }

    public function test_step_failed_includes_error(): void
    {
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('log')
            ->withArgs(function (string $level, string $message) {
                return str_contains($message, 'step_failed')
                    && str_contains($message, 'step=step-1')
                    && str_contains($message, 'error="step blew up"');
            })
            ->once();

        $this->makeTransport()->send($this->makeEvent(
            TraceEventType::STEP_FAILED,
            'trace-abc',
            ['error' => 'step blew up'],
            'step-1',
        ));
    }

    // -------------------------------------------------------------------------
    // Log emitted
    // -------------------------------------------------------------------------

    public function test_log_emitted_includes_level_and_message(): void
    {
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('log')
            ->withArgs(function (string $level, string $message) {
                return str_contains($message, 'log_emitted')
                    && str_contains($message, 'level=WARNING')
                    && str_contains($message, 'message="User logged in"');
            })
            ->once();

        $this->makeTransport()->send($this->makeEvent(
            TraceEventType::LOG_EMITTED,
            'trace-abc',
            ['message' => 'User logged in', 'level' => 'WARNING'],
        ));
    }

    public function test_log_emitted_defaults_level_to_info(): void
    {
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('log')
            ->withArgs(function (string $level, string $message) {
                return str_contains($message, 'level=INFO');
            })
            ->once();

        $this->makeTransport()->send($this->makeEvent(
            TraceEventType::LOG_EMITTED,
            'trace-abc',
            ['message' => 'hello'],
        ));
    }

    // -------------------------------------------------------------------------
    // Context array
    // -------------------------------------------------------------------------

    public function test_context_contains_full_event_data(): void
    {
        $payload = ['title' => 'Test', 'trace_type' => 'batch'];

        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('log')
            ->withArgs(function (string $level, string $message, array $context) use ($payload) {
                return $context['event_id'] === 'evt-42'
                    && $context['event_type'] === 'trace_started'
                    && $context['trace_id'] === 'trace-xyz'
                    && $context['source'] === 'test-service'
                    && $context['timestamp'] === self::TIMESTAMP
                    && $context['payload'] === $payload
                    && ! array_key_exists('step_id', $context); // null step_id filtered out
            })
            ->once();

        $this->makeTransport()->send($this->makeEvent(
            TraceEventType::TRACE_STARTED,
            'trace-xyz',
            $payload,
            null,
            'evt-42',
        ));
    }

    public function test_context_includes_step_id_when_present(): void
    {
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('log')
            ->withArgs(function (string $level, string $message, array $context) {
                return $context['step_id'] === 'step-99';
            })
            ->once();

        $this->makeTransport()->send($this->makeEvent(
            TraceEventType::STEP_STARTED,
            'trace-abc',
            ['name' => 'do stuff'],
            'step-99',
        ));
    }

    // -------------------------------------------------------------------------
    // Flush and shutdown are no-ops
    // -------------------------------------------------------------------------

    public function test_flush_does_not_throw(): void
    {
        $transport = $this->makeTransport();
        $transport->flush();
        $this->assertTrue(true); // no exception
    }

    public function test_shutdown_does_not_throw(): void
    {
        $transport = $this->makeTransport();
        $transport->shutdown();
        $this->assertTrue(true); // no exception
    }

    // -------------------------------------------------------------------------
    // Default config fallback
    // -------------------------------------------------------------------------

    public function test_defaults_to_stack_channel_and_info_level(): void
    {
        Log::shouldReceive('channel')
            ->with('stack')
            ->once()
            ->andReturnSelf();

        Log::shouldReceive('log')
            ->withArgs(fn (string $level) => $level === 'info')
            ->once();

        // No explicit log_channel or log_level
        $transport = new LogTransport([]);
        $transport->send($this->makeEvent(TraceEventType::TRACE_STARTED));
    }

    // -------------------------------------------------------------------------
    // Multiple events
    // -------------------------------------------------------------------------

    public function test_multiple_events_each_write_to_log(): void
    {
        Log::shouldReceive('channel')->times(3)->andReturnSelf();
        Log::shouldReceive('log')->times(3);

        $transport = $this->makeTransport();

        $transport->send($this->makeEvent(TraceEventType::TRACE_STARTED, 'trace-1', [], null, 'e1'));
        $transport->send($this->makeEvent(TraceEventType::STEP_STARTED, 'trace-1', ['name' => 'step'], 'step-1', 'e2'));
        $transport->send($this->makeEvent(TraceEventType::TRACE_FINISHED, 'trace-1', [], null, 'e3'));
    }

    // -------------------------------------------------------------------------
    // Implements TransportInterface
    // -------------------------------------------------------------------------

    public function test_implements_transport_interface(): void
    {
        $transport = $this->makeTransport();
        $this->assertInstanceOf(\Smartness\TraceFlow\Transport\TransportInterface::class, $transport);
    }
}
