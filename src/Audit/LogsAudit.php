<?php

namespace Smartness\TraceFlow\Audit;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

trait LogsAudit
{
    private array $traceflowAuditBefore = [];

    private bool $traceflowAuditRestoring = false;

    /** Explicit allowlist. Empty means no automatic fields are recorded. */
    abstract public function auditFields(): array;

    public function auditType(): string
    {
        return $this->getMorphClass();
    }

    /** Override to add safe metadata or transform selected fields before they leave the app. */
    public function customizeAudit(array $event): array
    {
        return $event;
    }

    public static function bootLogsAudit(): void
    {
        static::saving(function ($model) {
            AuditOutbox::requireTransaction($model->getConnection());
            $model->traceflowAuditBefore = $model->auditSnapshot(true);
        });
        static::deleting(function ($model) {
            AuditOutbox::requireTransaction($model->getConnection());
            $model->traceflowAuditBefore = $model->auditSnapshot();
        });
        static::created(fn ($model) => $model->writeAudit('created', [], $model->auditSnapshot()));
        static::updated(function ($model) {
            if (! $model->traceflowAuditRestoring) {
                $model->writeAudit('updated', $model->traceflowAuditBefore, $model->auditSnapshot());
            }
        });
        static::deleted(fn ($model) => $model->writeAudit('deleted', $model->traceflowAuditBefore, []));
        if (method_exists(static::class, 'restoring')) {
            static::restoring(function ($model) {
                AuditOutbox::requireTransaction($model->getConnection());
                $model->traceflowAuditRestoring = true;
            });
            static::restored(function ($model) {
                $model->traceflowAuditRestoring = false;
                $model->writeAudit('restored', [], $model->auditSnapshot());
            });
        }
    }

    private function auditSnapshot(bool $original = false): array
    {
        $values = [];
        foreach ($this->auditFields() as $field) {
            $value = $original ? $this->getOriginal($field) : $this->getAttribute($field);
            if ($value instanceof Arrayable) {
                $value = $value->toArray();
            } elseif ($value instanceof JsonSerializable) {
                $value = $value->jsonSerialize();
            } elseif ($value instanceof \DateTimeInterface) {
                $value = $value->format('Y-m-d\TH:i:s.uP');
            }
            $values[$field] = $value;
        }

        return $values;
    }

    private function writeAudit(string $action, array $before, array $after): void
    {
        $changes = AuditEvent::diff($before, $after);
        if ($action === 'updated' && (array) $changes['before'] === [] && (array) $changes['after'] === []) {
            return;
        }
        $context = app(AuditContext::class)->values();
        if (! isset($context['actor']) && ($user = auth()->user())) {
            $context['actor'] = ['type' => method_exists($user, 'getMorphClass') ? $user->getMorphClass() : 'user', 'id' => (string) $user->getAuthIdentifier()];
        }
        $payload = $this->customizeAudit(array_merge($context, [
            'subject' => ['type' => $this->auditType(), 'id' => (string) $this->getKey()],
            'action' => $action, 'category' => $this->getTable(), 'changes' => $changes,
        ]));
        $event = app(AuditClient::class)->createEvent($payload);
        app(AuditOutbox::class)->record($event, $this->getConnection());
    }
}
