<?php

namespace Smartness\TraceFlow\Console;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Console\Command;

class TestCommand extends Command
{
    protected $signature = 'traceflow:test';

    protected $description = 'Test TraceFlow connectivity and send a test trace';

    public function handle(): int
    {
        $this->info('TraceFlow SDK Test');
        $this->line('');

        $endpoint = config('traceflow.endpoint');
        $apiKey = config('traceflow.api_key');
        $source = config('traceflow.source');
        $transport = config('traceflow.transport');
        $async = config('traceflow.async_http') ? 'yes' : 'no';

        $this->line("  Endpoint:  {$endpoint}");
        $this->line("  API Key:   " . ($apiKey ? substr($apiKey, 0, 8) . '...' : '<not set>'));
        $this->line("  Source:    {$source}");
        $this->line("  Transport: {$transport} (async: {$async})");
        $this->line('');

        if (! $apiKey) {
            $this->error('  TRACEFLOW_API_KEY is not set.');
            $this->line('  Set it in your .env file: TRACEFLOW_API_KEY=your-key');

            return self::FAILURE;
        }

        $client = new Client([
            'base_uri' => $endpoint,
            'timeout' => 5,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-API-Key' => $apiKey,
            ],
        ]);

        // Step 1: Check connectivity
        $this->output->write('  Checking connectivity... ');

        try {
            $response = $client->get('/api/v1/health', ['http_errors' => false]);
            $statusCode = $response->getStatusCode();

            if ($statusCode >= 200 && $statusCode < 300) {
                $this->info("OK (HTTP {$statusCode})");
            } elseif ($statusCode === 404) {
                // Health endpoint may not exist, but server is reachable
                $this->warn("OK (no health endpoint, HTTP {$statusCode})");
            } else {
                $this->error("FAILED (HTTP {$statusCode})");
                $this->error("  Server returned unexpected status code.");

                return self::FAILURE;
            }
        } catch (ConnectException $e) {
            $this->error('FAILED');
            $this->error("  Could not connect to {$endpoint}");
            $this->error("  Error: {$e->getMessage()}");
            $this->line('');
            $this->line('  Check your TRACEFLOW_URL environment variable.');

            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->error('FAILED');
            $this->error("  Error: {$e->getMessage()}");

            return self::FAILURE;
        }

        // Step 2: Verify authentication by creating a test trace
        $this->output->write('  Verifying authentication... ');

        $traceId = $this->generateUuid();

        try {
            $response = $client->post('/api/v1/traces', [
                'json' => [
                    'trace_id' => $traceId,
                    'trace_type' => 'sdk_test',
                    'status' => 'pending',
                    'source' => $source,
                    'title' => 'TraceFlow SDK Test',
                    'created_at' => now()->toISOString(),
                    'updated_at' => now()->toISOString(),
                    'last_activity_at' => now()->toISOString(),
                    'metadata' => [
                        'command' => 'traceflow:test',
                        'php_version' => PHP_VERSION,
                        'laravel_version' => app()->version(),
                    ],
                ],
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode >= 200 && $statusCode < 300) {
                $this->info("OK (HTTP {$statusCode})");
            } else {
                $this->error("FAILED (HTTP {$statusCode})");

                return self::FAILURE;
            }
        } catch (ClientException $e) {
            $statusCode = $e->getResponse()->getStatusCode();
            $body = (string) $e->getResponse()->getBody();

            if ($statusCode === 403 || $statusCode === 401) {
                $this->error('FAILED');
                $this->error("  Authentication failed (HTTP {$statusCode})");
                $this->line("  Response: {$body}");
                $this->line('');
                $this->line('  Check your TRACEFLOW_API_KEY environment variable.');

                return self::FAILURE;
            }

            $this->error("FAILED (HTTP {$statusCode})");
            $this->line("  Response: {$body}");

            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->error('FAILED');
            $this->error("  Error: {$e->getMessage()}");

            return self::FAILURE;
        }

        // Step 3: Complete the trace
        $this->output->write('  Completing trace... ');

        try {
            $client->patch("/api/v1/traces/{$traceId}", [
                'json' => [
                    'status' => 'completed',
                    'updated_at' => now()->toISOString(),
                    'last_activity_at' => now()->toISOString(),
                    'metadata' => ['test' => 'passed'],
                ],
            ]);

            $this->info('OK');
        } catch (\Throwable $e) {
            $this->warn('FAILED (non-critical)');
            $this->line("  Could not complete trace: {$e->getMessage()}");
        }

        $this->line('');
        $this->line("  Trace ID: {$traceId}");
        $this->line('');
        $this->info('  All checks passed!');

        return self::SUCCESS;
    }

    private function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}
