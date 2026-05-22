<?php

namespace Smartness\TraceFlow\Transport;

use Smartness\TraceFlow\DTO\TraceEvent;

/**
 * No-op transport.
 *
 * Used when the SDK is disabled (e.g. local development without a running
 * TraceFlow collector). All public methods of TraceFlowSDK remain usable —
 * traces and steps still produce valid handles — but events are dropped
 * silently and no HTTP traffic is generated. This also suppresses the
 * AsyncHttpTransport circuit-breaker `error_log()` spam that otherwise
 * occurs when the collector is unreachable.
 */
class NullTransport implements TransportInterface
{
    public function send(TraceEvent $event): void
    {
    }

    public function flush(): void
    {
    }

    public function shutdown(): void
    {
    }
}
