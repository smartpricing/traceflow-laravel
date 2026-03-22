<?php

namespace Smartness\TraceFlow\Transport;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Promise\Utils;

class AsyncHttpTransport extends AbstractHttpTransport
{
    /** @var \GuzzleHttp\Promise\PromiseInterface[] */
    private array $promises = [];

    protected function buildClient(array $options): Client
    {
        try {
            return new Client($options);
        } catch (\Exception $e) {
            if ($this->silentErrors) {
                error_log("[TraceFlow Async] Error initializing client (silenced): {$e->getMessage()}");
                unset($options['base_uri']);

                return new Client($options);
            }
            throw $e;
        }
    }

    protected function dispatch(string $method, string $uri, array $payload): void
    {
        $this->executeAsync($method, $uri, $payload);
    }

    protected function logPrefix(): string
    {
        return '[TraceFlow Async]';
    }

    private function executeAsync(string $method, string $uri, array $data, int $attempt = 0): void
    {
        $promise = $this->client->requestAsync($method, $uri, ['json' => $data])
            ->then(
                function ($response) {
                    // Request succeeded
                },
                function (GuzzleException $exception) use ($method, $uri, $data, $attempt) {
                    if ($attempt < $this->maxRetries) {
                        // Schedule retry without blocking — the flush() loop will pick up the new promise
                        $this->executeAsync($method, $uri, $data, $attempt + 1);
                    } else {
                        if ($this->silentErrors) {
                            error_log($this->logPrefix()." Failed after {$this->maxRetries} retries: {$exception->getMessage()}");
                        } else {
                            throw $exception;
                        }
                    }
                }
            );

        $this->promises[] = $promise;
    }

    /**
     * Flush all pending async requests, including any retry promises created during settlement.
     */
    public function flush(): void
    {
        while (! empty($this->promises)) {
            $batch = $this->promises;
            $this->promises = [];

            try {
                Utils::settle($batch)->wait();
            } catch (\Exception $e) {
                if ($this->silentErrors) {
                    error_log($this->logPrefix()." Error during flush (silenced): {$e->getMessage()}");
                } else {
                    throw $e;
                }
            }
        }
    }

    public function shutdown(): void
    {
        $this->flush();
    }
}
