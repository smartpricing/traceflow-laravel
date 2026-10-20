<?php

namespace Smartness\TraceFlow\Tests\Unit\Context;

use PHPUnit\Framework\TestCase;
use Smartness\TraceFlow\Context\TraceFlowContext;

class TraceFlowContextTest extends TestCase
{
    protected function tearDown(): void
    {
        TraceFlowContext::clear();
        parent::tearDown();
    }

    public function test_initially_has_no_active_trace(): void
    {
        $this->assertFalse(TraceFlowContext::hasActiveTrace());
        $this->assertNull(TraceFlowContext::currentTraceId());
        $this->assertNull(TraceFlowContext::currentStepId());
        $this->assertEmpty(TraceFlowContext::metadata());
    }

    public function test_set_and_retrieve_trace_id(): void
    {
        TraceFlowContext::set('trace-123');

        $this->assertTrue(TraceFlowContext::hasActiveTrace());
        $this->assertEquals('trace-123', TraceFlowContext::currentTraceId());
        $this->assertNull(TraceFlowContext::currentStepId());
    }

    public function test_set_with_step_id_and_metadata(): void
    {
        TraceFlowContext::set('trace-456', 'step-789', ['key' => 'value']);

        $this->assertEquals('trace-456', TraceFlowContext::currentTraceId());
        $this->assertEquals('step-789', TraceFlowContext::currentStepId());
        $this->assertEquals(['key' => 'value'], TraceFlowContext::metadata());
    }

    public function test_clear_resets_all_state(): void
    {
        TraceFlowContext::set('trace-123', 'step-456', ['foo' => 'bar']);
        TraceFlowContext::clear();

        $this->assertFalse(TraceFlowContext::hasActiveTrace());
        $this->assertNull(TraceFlowContext::currentTraceId());
        $this->assertNull(TraceFlowContext::currentStepId());
        $this->assertEmpty(TraceFlowContext::metadata());
    }

    public function test_to_array_serializes_context(): void
    {
        TraceFlowContext::set('trace-abc', 'step-def', ['env' => 'test']);

        $data = TraceFlowContext::toArray();

        $this->assertEquals([
            'trace_id' => 'trace-abc',
            'step_id' => 'step-def',
            'metadata' => ['env' => 'test'],
        ], $data);
    }

    public function test_restore_from_array(): void
    {
        $data = [
            'trace_id' => 'trace-restored',
            'step_id' => 'step-restored',
            'metadata' => ['source' => 'queue'],
        ];

        TraceFlowContext::restore($data);

        $this->assertTrue(TraceFlowContext::hasActiveTrace());
        $this->assertEquals('trace-restored', TraceFlowContext::currentTraceId());
        $this->assertEquals('step-restored', TraceFlowContext::currentStepId());
        $this->assertEquals(['source' => 'queue'], TraceFlowContext::metadata());
    }

    public function test_restore_with_partial_data(): void
    {
        TraceFlowContext::restore(['trace_id' => 'trace-only']);

        $this->assertEquals('trace-only', TraceFlowContext::currentTraceId());
        $this->assertNull(TraceFlowContext::currentStepId());
        $this->assertEmpty(TraceFlowContext::metadata());
    }

    public function test_roundtrip_serialize_and_restore(): void
    {
        TraceFlowContext::set('trace-round', 'step-round', ['key' => 'val']);

        $serialized = TraceFlowContext::toArray();
        TraceFlowContext::clear();

        $this->assertFalse(TraceFlowContext::hasActiveTrace());

        TraceFlowContext::restore($serialized);

        $this->assertEquals('trace-round', TraceFlowContext::currentTraceId());
        $this->assertEquals('step-round', TraceFlowContext::currentStepId());
        $this->assertEquals(['key' => 'val'], TraceFlowContext::metadata());
    }

    public function test_set_overwrites_previous_context(): void
    {
        TraceFlowContext::set('trace-1', 'step-1', ['a' => 1]);
        TraceFlowContext::set('trace-2', 'step-2', ['b' => 2]);

        $this->assertEquals('trace-2', TraceFlowContext::currentTraceId());
        $this->assertEquals('step-2', TraceFlowContext::currentStepId());
        $this->assertEquals(['b' => 2], TraceFlowContext::metadata());
    }
}
