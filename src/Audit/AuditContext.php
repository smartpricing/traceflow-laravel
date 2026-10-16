<?php

namespace Smartness\TraceFlow\Audit;

/** Scoped container binding; explicit nesting also isolates jobs and long-lived workers. */
final class AuditContext
{
    private array $values = [];

    public function values(): array
    {
        return $this->values;
    }

    public function run(array $values, callable $operation): mixed
    {
        $previous = $this->values;
        $this->values = array_merge($previous, $values);
        try {
            return $operation();
        } finally {
            $this->values = $previous;
        }
    }
}
