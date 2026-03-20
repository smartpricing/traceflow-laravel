<?php

namespace Smartness\TraceFlow\Transport;

use GuzzleHttp\Exception\GuzzleException;

class HttpTransport extends AbstractHttpTransport
{
    protected function dispatch(string $method, string $uri, array $payload): void
    {
        $this->executeWithRetry($method, $uri, $payload);
    }

    protected function logPrefix(): string
    {
        return '[TraceFlow HTTP]';
    }

    private function executeWithRetry(string $method, string $uri, array $data, int $attempt = 0): void
    {
        try {
            $this->client->request($method, $uri, ['json' => $data]);
        } catch (GuzzleException $e) {
            if ($attempt < $this->maxRetries) {
                usleep($this->retryDelay * 1000 * (int) pow(2, $attempt));
                $this->executeWithRetry($method, $uri, $data, $attempt + 1);
            } else {
                throw $e;
            }
        }
    }

    public function flush(): void
    {
        // HTTP is synchronous, nothing to flush
    }

    public function shutdown(): void
    {
        // Nothing to cleanup
    }
}
