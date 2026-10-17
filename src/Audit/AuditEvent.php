<?php

namespace Smartness\TraceFlow\Audit;

use Illuminate\Contracts\Support\Arrayable;
use InvalidArgumentException;
use JsonSerializable;
use Ramsey\Uuid\Uuid;

final class AuditEvent implements JsonSerializable
{
    private function __construct(private readonly string $json) {}

    public static function create(string $source, array $input): self
    {
        $payload = array_merge($input, [
            'schema_version' => 1,
            'kind' => 'audit',
            'source' => $source,
            'event_id' => $input['event_id'] ?? Uuid::uuid4()->toString(),
            'occurred_at' => $input['occurred_at'] ?? (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z'),
        ]);
        if (isset($payload['changes'])) {
            $payload['changes']['before'] = (object) $payload['changes']['before'];
            $payload['changes']['after'] = (object) $payload['changes']['after'];
        }
        if (isset($payload['metadata'])) {
            $payload['metadata'] = (object) $payload['metadata'];
        }

        return self::fromJson(json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION, 34));
    }

    public static function fromJson(string $json): self
    {
        if (strlen($json) > 262144) {
            throw new InvalidArgumentException('Audit event exceeds 256 KiB');
        }
        $payload = json_decode($json, false, 34, JSON_THROW_ON_ERROR);
        if (! is_object($payload) || ($payload->schema_version ?? null) !== 1 || ($payload->kind ?? null) !== 'audit'
            || ! is_string($payload->source ?? null) || trim($payload->source) === ''
            || ! Uuid::isValid($payload->event_id ?? '') || ! is_string($payload->action ?? null) || trim($payload->action) === '') {
            throw new InvalidArgumentException('Invalid audit envelope');
        }
        $allowed = ['schema_version', 'kind', 'source', 'event_id', 'occurred_at', 'subject', 'action', 'category', 'description', 'actor', 'changes', 'origin', 'metadata', 'redacted_fields', 'request_id', 'trace_id', 'batch_id', 'subject_version'];
        if (array_diff(array_keys(get_object_vars($payload)), $allowed)) {
            throw new InvalidArgumentException('Unknown audit envelope field');
        }
        foreach (['source', 'action', 'category', 'request_id', 'trace_id', 'batch_id', 'subject_version'] as $field) {
            if (property_exists($payload, $field)) {
                self::identifier($payload->{$field});
            }
        }
        foreach (['subject' => ['type', 'id'], 'actor' => ['type', 'id', 'label']] as $field => $keys) {
            if (property_exists($payload, $field)) {
                if (! is_object($payload->{$field}) || array_diff(array_keys(get_object_vars($payload->{$field})), $keys)) {
                    throw new InvalidArgumentException("Invalid audit {$field}");
                }
                self::identifier($payload->{$field}->type ?? null);
                self::identifier($payload->{$field}->id ?? null);
            }
        }
        if (property_exists($payload, 'description')) {
            self::text($payload->description, 2000, 'description');
        }
        if (property_exists($payload->actor ?? new \stdClass, 'label')) {
            self::text($payload->actor->label, 300, 'actor.label');
        }
        if (property_exists($payload, 'redacted_fields')) {
            $fields = $payload->redacted_fields;
            if (! is_array($fields) || ! array_is_list($fields) || count($fields) > 200) {
                throw new InvalidArgumentException('Audit redacted_fields must be a list of at most 200 field paths');
            }
            foreach ($fields as $path) {
                if (! is_string($path) || $path === '') {
                    throw new InvalidArgumentException('Audit redacted_fields must be a list of at most 200 field paths');
                }
                self::text($path, 300, 'redacted_fields');
            }
        }
        if (! is_string($payload->occurred_at ?? null) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/', $payload->occurred_at) || strtotime($payload->occurred_at) === false) {
            throw new InvalidArgumentException('Audit occurred_at must be an ISO timestamp with timezone');
        }
        if (in_array($payload->action, ['created', 'updated', 'deleted', 'restored'], true) && (! isset($payload->subject) || ! isset($payload->changes))) {
            throw new InvalidArgumentException('An audit mutation requires subject and changes');
        }
        if (property_exists($payload, 'changes')) {
            $changes = $payload->changes;
            if (! is_object($changes) || array_diff(array_keys(get_object_vars($changes)), ['mode', 'before', 'after'])
                || ! in_array($changes->mode ?? null, ['delta', 'snapshot'], true) || ! is_object($changes->before ?? null) || ! is_object($changes->after ?? null)) {
                throw new InvalidArgumentException('Audit changes require object images and delta or snapshot mode');
            }
            if ($payload->action === 'created' && (array) $changes->before !== [] || $payload->action === 'deleted' && (array) $changes->after !== []) {
                throw new InvalidArgumentException('Creation/deletion must have an empty before/after image');
            }
            if ($payload->action === 'updated' && self::canonical($changes->before) === self::canonical($changes->after)) {
                throw new InvalidArgumentException('An audit update must contain a change');
            }
        }
        if (property_exists($payload, 'metadata') && ! is_object($payload->metadata)) {
            throw new InvalidArgumentException('Audit metadata must be an object');
        }
        if (property_exists($payload, 'origin')) {
            if (! is_object($payload->origin) || array_diff(array_keys(get_object_vars($payload->origin)), ['channel', 'client_source', 'release'])) {
                throw new InvalidArgumentException('Invalid audit origin');
            }
            self::identifier($payload->origin->channel ?? null);
            foreach (['client_source', 'release'] as $field) {
                if (property_exists($payload->origin, $field)) {
                    self::identifier($payload->origin->{$field});
                }
            }
        }
        self::validateNumbers($payload);

        $payload->event_id = strtolower($payload->event_id);
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION, 34);

        return new self($json);
    }

    private static function identifier(mixed $value): void
    {
        if (! is_string($value) || trim($value) === '' || self::utf16Length($value) > 200) {
            throw new InvalidArgumentException('Audit identifiers must be non-empty strings of at most 200 characters');
        }
    }

    /** Free text accepted by the backend contract; the limit mirrors its character count. */
    private static function text(mixed $value, int $max, string $field): void
    {
        if (! is_string($value) || self::utf16Length($value) > $max) {
            throw new InvalidArgumentException("Audit {$field} must be a string of at most {$max} characters");
        }
    }

    /** The backend counts UTF-16 code units, as JavaScript string length does. */
    private static function utf16Length(string $value): int
    {
        $encoded = mb_convert_encoding($value, 'UTF-16LE', 'UTF-8');

        return intdiv(strlen($encoded), 2);
    }

    private static function validateNumbers(mixed $value): void
    {
        if ((is_int($value) || is_float($value) && floor($value) === $value) && abs($value) > 9007199254740991) {
            throw new InvalidArgumentException('Encode large audit integers as strings');
        }
        if (is_object($value) || is_array($value)) {
            foreach ($value as $item) {
                self::validateNumbers($item);
            }
        }
    }

    public function id(): string
    {
        return $this->jsonSerialize()->event_id;
    }

    public function source(): string
    {
        return $this->jsonSerialize()->source;
    }

    public function toJson(): string
    {
        return $this->json;
    }

    public function jsonSerialize(): object
    {
        return json_decode($this->json, false, 34, JSON_THROW_ON_ERROR);
    }

    /** Nested fields are atomic; key absence is deliberately different from null. */
    public static function diff(array $before, array $after): array
    {
        $old = [];
        $new = [];
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
            $hasOld = array_key_exists($key, $before);
            $hasNew = array_key_exists($key, $after);
            if ($hasOld && $hasNew && self::canonical($before[$key]) === self::canonical($after[$key])) {
                continue;
            }
            if ($hasOld) {
                $old[$key] = $before[$key];
            }
            if ($hasNew) {
                $new[$key] = $after[$key];
            }
        }

        return ['mode' => 'delta', 'before' => (object) $old, 'after' => (object) $new];
    }

    public static function canonical(mixed $value, int $depth = 0): string
    {
        if ($depth > 32) {
            throw new InvalidArgumentException('Audit JSON exceeds depth 32');
        }
        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        } elseif ($value instanceof \UnitEnum) {
            $value = $value->name;
        } elseif ($value instanceof Arrayable) {
            $value = $value->toArray();
        } elseif ($value instanceof JsonSerializable) {
            $value = $value->jsonSerialize();
        }
        if (is_object($value) && ! $value instanceof \stdClass) {
            throw new InvalidArgumentException('Serialize audit objects and dates explicitly');
        }
        if (is_object($value)) {
            $value = get_object_vars($value);
            ksort($value);
            $value = (object) $value;
        } elseif (is_array($value) && ! array_is_list($value)) {
            ksort($value);
        }
        if (is_array($value) || is_object($value)) {
            $values = [];
            foreach ($value as $key => $child) {
                $values[$key] = json_decode(self::canonical($child, $depth + 1), false, 34, JSON_THROW_ON_ERROR);
            }
            $value = is_object($value) ? (object) $values : $values;
        }

        return json_encode($value, JSON_THROW_ON_ERROR, 34);
    }
}
