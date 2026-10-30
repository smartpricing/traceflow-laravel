<?php

namespace Smartness\TraceFlow\Transport;

/**
 * Thrown by flush() / shutdown() when silent_errors is false and trace events
 * were not delivered since the previous flush. Tracing calls themselves never
 * throw delivery errors: with the async transport nothing waits for the server
 * when they run.
 */
class DeliveryException extends \RuntimeException
{
    /**
     * @param  array<string, int>  $undelivered  count per reason (failed, rejected, dropped_queue_full, ...)
     */
    public function __construct(
        public readonly array $undelivered,
        public readonly ?string $lastError = null,
    ) {
        $parts = [];
        foreach ($undelivered as $reason => $count) {
            $parts[] = "{$reason}={$count}";
        }

        $total = array_sum($undelivered);
        $message = "TraceFlow: {$total} trace event(s) not delivered since the last flush (".implode(', ', $parts).')';

        if ($lastError !== null) {
            $message .= "; last error: {$lastError}";
        }

        parent::__construct($message);
    }
}
