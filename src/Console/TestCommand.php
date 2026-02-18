<?php

namespace Smartness\TraceFlow\Console;

use GuzzleHttp\Client;
use Illuminate\Console\Command;
use Smartness\TraceFlow\TraceFlowSDK;

class TestCommand extends Command
{
    protected $signature = 'traceflow:test';

    protected $description = 'Test TraceFlow connectivity and send a test trace';

    public function handle(TraceFlowSDK $sdk): int
    {
        $this->info('TraceFlow SDK Test');
        $this->line('');

        $endpoint = config('traceflow.endpoint');
        $source = config('traceflow.source');
        $transport = config('traceflow.transport');
        $async = config('traceflow.async_http') ? 'yes' : 'no';

        $this->line("  Endpoint:  {$endpoint}");
        $this->line("  Source:    {$source}");
        $this->line("  Transport: {$transport} (async: {$async})");
        $this->line('');

        // Step 1: Check connectivity
        $this->output->write('  Checking connectivity... ');

        try {
            $client = new Client(['timeout' => 5]);
            $response = $client->get($endpoint, ['http_errors' => false]);
            $statusCode = $response->getStatusCode();
            $this->info("OK (HTTP {$statusCode})");
        } catch (\Throwable $e) {
            $this->error('FAILED');
            $this->error("  Could not connect to {$endpoint}");
            $this->error("  Error: {$e->getMessage()}");
            $this->line('');
            $this->line('  Check your TRACEFLOW_URL environment variable.');

            return self::FAILURE;
        }

        // Step 2: Send a test trace
        $this->output->write('  Sending test trace... ');

        try {
            $trace = $sdk->startTrace(
                traceType: 'sdk_test',
                title: 'TraceFlow SDK Test',
                metadata: [
                    'command' => 'traceflow:test',
                    'php_version' => PHP_VERSION,
                    'laravel_version' => app()->version(),
                ],
            );

            $step = $trace->startStep(
                name: 'Connectivity Check',
                stepType: 'test',
            );
            $step->finish(['status' => 'ok']);

            $trace->finish(['test' => 'passed']);
            $sdk->flush();

            $this->info('OK');
            $this->line("  Trace ID: {$trace->traceId}");
        } catch (\Throwable $e) {
            $this->error('FAILED');
            $this->error("  Error: {$e->getMessage()}");
            $this->line('');
            $this->line('  Connection works but trace creation failed.');
            $this->line('  Check your TRACEFLOW_API_KEY and server configuration.');

            return self::FAILURE;
        }

        $this->line('');
        $this->info('  All checks passed!');

        return self::SUCCESS;
    }
}
