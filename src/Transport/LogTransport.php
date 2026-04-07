<?php

namespace Smartness\TraceFlow\Transport;

use Illuminate\Support\Facades\Log;
use Smartness\TraceFlow\DTO\TraceEvent;
use Smartness\TraceFlow\Enums\TraceEventType;

class LogTransport implements TransportInterface
{
    private string $channel;

    private string $level;

    public function __construct(array $config)
    {
        $this->channel = $config['log_channel'] ?? config('logging.default', 'stack');
        $this->level = $config['log_level'] ?? 'info';
    }

    public function send(TraceEvent $event): void
    {
        $context = array_filter([
            'event_id' => $event->eventId,
            'event_type' => $event->eventType->value,
            'trace_id' => $event->traceId,
            'step_id' => $event->stepId,
            'source' => $event->source,
            'timestamp' => $event->timestamp,
            'payload' => $event->payload,
        ], fn ($value) => $value !== null);

        $message = $this->buildMessage($event);

        Log::channel($this->channel)->log($this->level, $message, $context);
    }

    private function buildMessage(TraceEvent $event): string
    {
        $parts = ["[TraceFlow] {$event->eventType->value}"];
        $parts[] = "trace={$event->traceId}";

        if ($event->stepId) {
            $parts[] = "step={$event->stepId}";
        }

        // Add human-readable summary based on event type
        switch ($event->eventType) {
            case TraceEventType::TRACE_STARTED:
                if (isset($event->payload['title'])) {
                    $parts[] = "title=\"{$event->payload['title']}\"";
                }
                if (isset($event->payload['trace_type'])) {
                    $parts[] = "type={$event->payload['trace_type']}";
                }
                break;

            case TraceEventType::TRACE_FINISHED:
            case TraceEventType::TRACE_FAILED:
            case TraceEventType::TRACE_CANCELLED:
                if (isset($event->payload['error'])) {
                    $parts[] = "error=\"{$event->payload['error']}\"";
                }
                break;

            case TraceEventType::STEP_STARTED:
                if (isset($event->payload['name'])) {
                    $parts[] = "name=\"{$event->payload['name']}\"";
                }
                if (isset($event->payload['step_type'])) {
                    $parts[] = "type={$event->payload['step_type']}";
                }
                break;

            case TraceEventType::STEP_FINISHED:
            case TraceEventType::STEP_FAILED:
                if (isset($event->payload['error'])) {
                    $parts[] = "error=\"{$event->payload['error']}\"";
                }
                break;

            case TraceEventType::LOG_EMITTED:
                $level = $event->payload['level'] ?? 'INFO';
                $msg = $event->payload['message'] ?? '';
                $parts[] = "level={$level} message=\"{$msg}\"";
                break;
        }

        return implode(' ', $parts);
    }

    public function flush(): void
    {
        // Nothing to flush — logs are written immediately
    }

    public function shutdown(): void
    {
        // Nothing to clean up
    }
}
