<?php

namespace Smartness\TraceFlow\Transport;

use Smartness\TraceFlow\DTO\TraceEvent;

interface TransportInterface
{
    /**
     * Send event to transport
     */
    public function send(TraceEvent $event);

    /**
     * Flush any pending events
     */
    public function flush();

    /**
     * Shutdown transport gracefully
     */
    public function shutdown();
}
