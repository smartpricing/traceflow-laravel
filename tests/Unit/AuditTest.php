<?php

namespace Smartness\TraceFlow\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Smartness\TraceFlow\Audit\AuditContext;
use Smartness\TraceFlow\Audit\AuditEvent;

class AuditTest extends TestCase
{
    public function test_diff_preserves_null_absence_and_json_types(): void
    {
        $diff = AuditEvent::diff(['note' => null, 'value' => 0, 'config' => ['b' => 1, 'a' => false]], ['added' => null, 'value' => false, 'config' => ['a' => false, 'b' => 1]]);
        $this->assertSame(['note' => null, 'value' => 0], (array) $diff['before']);
        $this->assertSame(['value' => false, 'added' => null], (array) $diff['after']);
        $event = AuditEvent::create('test', ['action' => 'created', 'subject' => ['type' => 'custom', 'id' => '12345'], 'changes' => ['mode' => 'delta', 'before' => [], 'after' => ['total' => '420.00']]]);
        $this->assertInstanceOf(\stdClass::class, $event->jsonSerialize()->changes->before);
        $this->assertSame('420.00', $event->jsonSerialize()->changes->after->total);
        $this->assertSame([], (array) AuditEvent::diff(['value' => 1], ['value' => 1.0])['before']);
        $this->assertSame([], (array) AuditEvent::diff(['value' => 1], ['value' => 1.0])['after']);
    }

    public function test_context_is_restored_even_after_an_exception(): void
    {
        $context = new AuditContext;
        try {
            $context->run(['actor' => ['type' => 'user', 'id' => '42']], function () use ($context) {
                $this->assertSame('42', $context->values()['actor']['id']);
                throw new \RuntimeException;
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame([], $context->values());
    }

    public function test_envelope_free_text_and_nested_fields_follow_the_backend_contract(): void
    {
        $valid = ['action' => 'viewed', 'description' => str_repeat('é', 2000), 'actor' => ['type' => 'user', 'id' => '1', 'label' => str_repeat('😀', 150)], 'redacted_fields' => ['guest.phone'], 'origin' => ['channel' => 'api', 'client_source' => 'cb3', 'release' => '1.0']];
        $this->assertSame('viewed', AuditEvent::create('test', $valid)->jsonSerialize()->action);
        foreach ([
            ['description' => str_repeat('a', 2001)],
            ['description' => 42],
            ['actor' => ['type' => 'user', 'id' => '1', 'label' => str_repeat('😀', 151)]],
            ['actor' => ['type' => 'user', 'id' => '1', 'email' => 'x']],
            ['redacted_fields' => ['']],
            ['redacted_fields' => 'guest.phone'],
            ['redacted_fields' => array_fill(0, 201, 'f')],
            ['origin' => ['channel' => 'api', 'ip' => '10.0.0.1']],
            ['origin' => ['channel' => 'api', 'release' => '']],
            ['subject' => ['type' => 'x', 'id' => '1', 'name' => 'n']],
            ['changes' => ['mode' => 'snapshot', 'before' => [], 'after' => [], 'extra' => 1]],
        ] as $invalid) {
            try {
                AuditEvent::create('test', array_merge(['action' => 'viewed'], $invalid));
                $this->fail('Expected rejection of '.json_encode($invalid));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_enums_are_recorded_as_their_stored_value_or_case_name(): void
    {
        $diff = AuditEvent::diff(['status' => AuditTestStatus::Draft, 'kind' => AuditTestKind::Room], ['status' => AuditTestStatus::Sent, 'kind' => AuditTestKind::Room]);
        $this->assertSame(['status' => 'draft'], json_decode(json_encode($diff['before']), true));
        $this->assertSame(['status' => 'sent'], json_decode(json_encode($diff['after']), true));
        $this->assertSame('"Room"', AuditEvent::canonical(AuditTestKind::Room));
    }
}

enum AuditTestStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
}

enum AuditTestKind
{
    case Room;
}
