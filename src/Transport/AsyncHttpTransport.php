<?php

namespace Smartness\TraceFlow\Transport;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Promise\Utils;

class AsyncHttpTransport extends AbstractHttpTransport
{
    /** @var \GuzzleHttp\Promise\PromiseInterface[] */
    private array $promises = [];

    /**
     * Last pending promise per entity order key. Used to serialize requests for
     * the same entity (e.g. a step's create then update) so an update can never
     * be transferred before its create completes, which would 404 on the server.
     *
     * @var array<string, \GuzzleHttp\Promise\PromiseInterface>
     */
    private array $chains = [];

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

    protected function dispatch(string $method, string $uri, array $payload, ?string $orderKey = null): void
    {
        $this->executeAsync($method, $uri, $payload, $orderKey);
    }

    protected function logPrefix(): string
    {
        return '[TraceFlow Async]';
    }

    private function executeAsync(string $method, string $uri, array $data, ?string $orderKey = null, int $attempt = 0): void
    {
        // The actual request is wrapped in a closure so it can be deferred until
        // any earlier request for the same entity has settled (see $chains).
        $send = function () use ($method, $uri, $data, $orderKey, $attempt) {
            return $this->client->requestAsync($method, $uri, ['json' => $data])
                ->then(
                    function ($response) {
                        // Request succeeded
                    },
                    function (GuzzleException $exception) use ($method, $uri, $data, $orderKey, $attempt) {
                        if ($attempt < $this->maxRetries) {
                            // Intentionally no delay between async retries: sleeping inside a promise
                            // rejection handler would block the event loop. The flush() while-loop
                            // naturally adds a small gap between retry batches. retry_delay config
                            // applies only to the synchronous HttpTransport.
                            $this->executeAsync($method, $uri, $data, $orderKey, $attempt + 1);
                        } else {
                            $this->recordFailure();
                            if ($this->silentErrors) {
                                error_log($this->logPrefix()." Failed after {$this->maxRetries} retries: {$exception->getMessage()}");
                            } else {
                                throw $exception;
                            }
                        }
                    }
                );
        };

        if ($orderKey !== null && isset($this->chains[$orderKey])) {
            // Serialize behind the previous request for this entity. Run regardless
            // of whether that request fulfilled or rejected so a failed create does
            // not permanently stall the entity's later requests.
            $promise = $this->chains[$orderKey]->then($send, $send);
        } else {
            $promise = $send();
        }

        if ($orderKey !== null) {
            $this->chains[$orderKey] = $promise;
        }

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

        // All promises have settled; drop references to settled per-entity chains
        // so the map does not grow for the lifetime of the process.
        $this->chains = [];

        $this->drainQueue();
    }

    public function shutdown(): void
    {
        $this->flush();
    }
}
