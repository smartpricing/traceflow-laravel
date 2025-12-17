<?php

namespace Smartpricing\TraceFlow\Transport;

use Smartpricing\TraceFlow\DTO\TraceEvent;

interface TransportInterface
{
    /**
     * Send event to transport
     */
    public function send(TraceEvent $event): void;

    /**
     * Flush any pending events
     */
    public function flush(): void;

    /**
     * Shutdown transport gracefully
     */
    public function shutdown(): void;
}

