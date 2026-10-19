<?php

namespace Smartness\TraceFlow\Audit;

final class AuditClient
{
    public function __construct(private readonly array $config) {}

    /** A validated envelope for this application's source; deliver it with AuditPublisher. */
    public function createEvent(array $input): AuditEvent
    {
        return AuditEvent::create($this->config['source'] ?? '', $input);
    }
}
