<?php

namespace Smartness\TraceFlow\Audit;

final class AuditDeliveryException extends \RuntimeException
{
    public function __construct(string $message, public readonly string $eventId, public readonly bool $retryable, public readonly ?int $statusCode = null)
    {
        parent::__construct($message);
    }
}
