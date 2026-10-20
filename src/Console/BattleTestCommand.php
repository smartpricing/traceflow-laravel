<?php

namespace Smartness\TraceFlow\Console;

use Illuminate\Console\Command;
use Smartness\TraceFlow\TraceFlowSDK;

class BattleTestCommand extends Command
{
    protected $signature = 'traceflow:battle-test
        {--url= : TraceFlow service URL}
        {--key= : API key}
        {--traces=100 : Number of traces to create}
        {--steps=3 : Steps per trace}
        {--logs=2 : Logs per step + per trace}
        {--error-rate=20 : Percentage of traces that simulate errors (0-100)}
        {--concurrency=10 : Batch size (traces processed per flush cycle)}
        {--async : Use async transport (default)}
        {--sync : Use sync transport}
        {--source=battle-test : Source identifier}';

    protected $description = 'Battle test: fire thousands of traces/steps/logs to stress-test the SDK';

    private int $eventsSent = 0;
    private int $tracesOk = 0;
    private int $tracesFailed = 0;
    private int $stepsOk = 0;
    private int $stepsFailed = 0;
    private int $logsSent = 0;
    private int $errors = 0;
    private array $latencies = [];

    public function handle(): int
    {
        $url = $this->option('url');
        $apiKey = $this->option('key');

        if (! $url || ! $apiKey) {
            $this->error('Both --url and --key are required.');
            $this->line('  Usage: php artisan traceflow:battle-test --url=https://... --key=your-api-key');
            return self::FAILURE;
        }

        $totalTraces = (int) $this->option('traces');
        $stepsPerTrace = (int) $this->option('steps');
        $logsPerEntity = (int) $this->option('logs');
        $errorRate = (int) $this->option('error-rate');
        $batchSize = (int) $this->option('concurrency');
        $useAsync = ! $this->option('sync');
        $source = $this->option('source');

        $expectedEvents = $totalTraces * (
            1 +                                           // trace_started
            1 +                                           // trace_finished/failed
            $logsPerEntity +                              // trace-level logs
            $stepsPerTrace * (1 + 1 + $logsPerEntity)     // step_started + step_finished/failed + step logs
        );

        $this->printBanner($url, $totalTraces, $stepsPerTrace, $logsPerEntity, $errorRate, $batchSize, $useAsync, $source, $expectedEvents);

        // Connectivity check
        if (! $this->checkConnectivity($url, $apiKey)) {
            return self::FAILURE;
        }

        $sdk = new TraceFlowSDK([
            'source' => $source,
            'transport' => 'http',
            'endpoint' => $url,
            'async_http' => $useAsync,
            'api_key' => $apiKey,
            'timeout' => 10.0,
            'max_retries' => 2,
            'retry_delay' => 500,
            'silent_errors' => false,
            'circuit_breaker_threshold' => 50,
            'circuit_breaker_timeout_ms' => 5000,
        ]);

        $this->line('');
        $globalStart = microtime(true);
        $bar = $this->output->createProgressBar($totalTraces);
        $bar->setFormat(" %current%/%max% [%bar%] %percent:3s%% | %elapsed:6s% | OK: %ok% ERR: %err%");
        $bar->setMessage('0', 'ok');
        $bar->setMessage('0', 'err');
        $bar->start();

        $batchCount = 0;

        for ($i = 0; $i < $totalTraces; $i++) {
            $shouldFail = (mt_rand(1, 100) <= $errorRate);

            try {
                $this->runSingleTrace($sdk, $i, $stepsPerTrace, $logsPerEntity, $shouldFail);
            } catch (\Throwable $e) {
                $this->errors++;
            }

            $batchCount++;

            if ($batchCount >= $batchSize) {
                try {
                    $sdk->flush();
                } catch (\Throwable $e) {
                    $this->errors++;
                }
                $batchCount = 0;
            }

            $bar->setMessage((string) ($this->tracesOk + $this->stepsOk), 'ok');
            $bar->setMessage((string) $this->errors, 'err');
            $bar->advance();
        }

        // Final flush
        $this->output->write("\n\n  Flushing remaining events... ");
        $flushStart = microtime(true);

        try {
            $sdk->shutdown();
            $flushTime = (microtime(true) - $flushStart) * 1000;
            $this->info(sprintf('done (%.0fms)', $flushTime));
        } catch (\Throwable $e) {
            $this->errors++;
            $this->error("flush error: {$e->getMessage()}");
        }

        $totalTime = microtime(true) - $globalStart;

        $bar->finish();
        $this->line('');

        $this->printResults($totalTime, $totalTraces, $stepsPerTrace, $logsPerEntity, $expectedEvents);

        return $this->errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function runSingleTrace(TraceFlowSDK $sdk, int $index, int $stepsPerTrace, int $logsPerEntity, bool $shouldFail): void
    {
        $traceStart = microtime(true);

        $traceTypes = ['api_request', 'job_processing', 'cron_task', 'webhook', 'user_action', 'batch_import'];
        $traceType = $traceTypes[$index % count($traceTypes)];

        // Start trace
        $trace = $sdk->startTrace(
            traceType: $traceType,
            title: "Battle trace #{$index}",
            description: $shouldFail ? 'This trace will simulate a failure' : 'Normal trace execution',
            owner: 'battle-test',
            tags: ['battle-test', $traceType, $shouldFail ? 'error' : 'success'],
            metadata: [
                'batch_index' => $index,
                'will_fail' => $shouldFail,
                'php_version' => PHP_VERSION,
                'memory_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            ],
        );
        $this->eventsSent++;

        // Trace-level logs
        for ($l = 0; $l < $logsPerEntity; $l++) {
            $levels = ['DEBUG', 'INFO', 'WARN'];
            $trace->log(
                "Trace #{$index} log entry {$l}",
                $levels[$l % count($levels)],
                'trace_activity',
                ['log_index' => $l, 'timestamp' => microtime(true)]
            );
            $this->eventsSent++;
            $this->logsSent++;
        }

        // Steps
        $failAtStep = $shouldFail ? mt_rand(0, $stepsPerTrace - 1) : -1;

        for ($s = 0; $s < $stepsPerTrace; $s++) {
            $stepShouldFail = ($s === $failAtStep);
            $stepNames = ['validate_input', 'fetch_data', 'transform', 'persist', 'notify', 'cleanup'];
            $stepName = $stepNames[$s % count($stepNames)];

            $step = $trace->startStep(
                name: "{$stepName}_{$s}",
                stepType: $stepName,
                input: [
                    'trace_index' => $index,
                    'step_index' => $s,
                    'payload_size' => mt_rand(100, 10000),
                ],
                metadata: ['attempt' => 1, 'step_will_fail' => $stepShouldFail],
            );
            $this->eventsSent++;

            // Step-level logs
            for ($l = 0; $l < $logsPerEntity; $l++) {
                $step->log(
                    "Step {$stepName}_{$s} log {$l}",
                    $stepShouldFail && $l === $logsPerEntity - 1 ? 'ERROR' : 'INFO',
                    'step_activity',
                    ['detail' => "Processing item {$l}", 'step_index' => $s]
                );
                $this->eventsSent++;
                $this->logsSent++;
            }

            // Finish or fail step
            if ($stepShouldFail) {
                $errorMessages = [
                    'Connection timeout after 5000ms',
                    'Invalid response format from upstream',
                    'Rate limit exceeded (429)',
                    'Database deadlock detected',
                    'Out of memory in worker process',
                    'Validation failed: missing required field "id"',
                ];
                $step->fail($errorMessages[mt_rand(0, count($errorMessages) - 1)]);
                $this->eventsSent++;
                $this->stepsFailed++;
            } else {
                $step->finish(
                    output: ['records_processed' => mt_rand(1, 1000), 'duration_ms' => mt_rand(10, 500)],
                    metadata: ['cache_hit' => (bool) mt_rand(0, 1)]
                );
                $this->eventsSent++;
                $this->stepsOk++;
            }
        }

        // Finish or fail trace
        if ($shouldFail) {
            $trace->fail("Simulated failure at step {$failAtStep}");
            $this->eventsSent++;
            $this->tracesFailed++;
        } else {
            $trace->finish(
                result: ['total_steps' => $stepsPerTrace, 'status' => 'all_ok'],
                metadata: ['completion_time_ms' => round((microtime(true) - $traceStart) * 1000, 2)]
            );
            $this->eventsSent++;
            $this->tracesOk++;
        }

        $this->latencies[] = (microtime(true) - $traceStart) * 1000;
    }

    private function checkConnectivity(string $url, string $apiKey): bool
    {
        $this->output->write('  Checking connectivity... ');

        try {
            $client = new \GuzzleHttp\Client([
                'base_uri' => $url,
                'timeout' => 5,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-API-Key' => $apiKey,
                ],
            ]);

            $response = $client->get('/api/v1/health', ['http_errors' => false]);
            $status = $response->getStatusCode();

            if ($status >= 200 && $status < 400) {
                $this->info("OK (HTTP {$status})");
                return true;
            }

            // Health endpoint may not exist but server is up
            if ($status === 404) {
                $this->warn("OK (no health endpoint)");
                return true;
            }

            $this->error("FAILED (HTTP {$status})");
            return false;
        } catch (\GuzzleHttp\Exception\ConnectException $e) {
            $this->error('FAILED - cannot connect');
            $this->error("  {$e->getMessage()}");
            return false;
        } catch (\Throwable $e) {
            $this->error("FAILED - {$e->getMessage()}");
            return false;
        }
    }

    private function printBanner(string $url, int $traces, int $steps, int $logs, int $errorRate, int $batch, bool $async, string $source, int $expected): void
    {
        $this->line('');
        $this->info('  =======================================');
        $this->info('    TraceFlow SDK - Battle Test');
        $this->info('  =======================================');
        $this->line('');
        $this->line("  Target:       {$url}");
        $this->line("  Source:       {$source}");
        $this->line("  Transport:    " . ($async ? 'async' : 'sync'));
        $this->line("  Traces:       {$traces}");
        $this->line("  Steps/trace:  {$steps}");
        $this->line("  Logs/entity:  {$logs}");
        $this->line("  Error rate:   {$errorRate}%");
        $this->line("  Batch size:   {$batch}");
        $this->line("  Expected events: ~{$expected}");
        $this->line('');
    }

    private function printResults(float $totalTime, int $totalTraces, int $stepsPerTrace, int $logsPerEntity, int $expectedEvents): void
    {
        $this->line('');
        $this->info('  =======================================');
        $this->info('    Results');
        $this->info('  =======================================');
        $this->line('');

        $totalSteps = $this->stepsOk + $this->stepsFailed;

        $this->line(sprintf('  Total time:         %.2fs', $totalTime));
        $this->line(sprintf('  Events sent:        %d / %d expected', $this->eventsSent, $expectedEvents));
        $this->line(sprintf('  Throughput:         %.0f events/sec', $this->eventsSent / max($totalTime, 0.001)));
        $this->line('');
        $this->line(sprintf('  Traces OK:          %d', $this->tracesOk));
        $this->line(sprintf('  Traces Failed:      %d (simulated)', $this->tracesFailed));
        $this->line(sprintf('  Steps OK:           %d', $this->stepsOk));
        $this->line(sprintf('  Steps Failed:       %d (simulated)', $this->stepsFailed));
        $this->line(sprintf('  Logs sent:          %d', $this->logsSent));
        $this->line('');

        if ($this->errors > 0) {
            $this->error(sprintf('  SDK/Transport errors: %d', $this->errors));
        } else {
            $this->info('  SDK/Transport errors: 0');
        }

        // Latency stats
        if (count($this->latencies) > 0) {
            sort($this->latencies);
            $count = count($this->latencies);
            $avg = array_sum($this->latencies) / $count;
            $p50 = $this->latencies[(int) ($count * 0.50)];
            $p95 = $this->latencies[(int) min($count * 0.95, $count - 1)];
            $p99 = $this->latencies[(int) min($count * 0.99, $count - 1)];
            $min = $this->latencies[0];
            $max = $this->latencies[$count - 1];

            $this->line('');
            $this->line('  Per-trace latency (SDK-side, ms):');
            $this->line(sprintf('    min: %.1f  avg: %.1f  p50: %.1f  p95: %.1f  p99: %.1f  max: %.1f', $min, $avg, $p50, $p95, $p99, $max));
        }

        $this->line('');
        $this->line(sprintf('  Memory peak: %.1f MB', memory_get_peak_usage(true) / 1024 / 1024));
        $this->line('');

        if ($this->errors === 0) {
            $this->info('  Battle test PASSED - all events sent successfully.');
        } else {
            $this->error(sprintf('  Battle test COMPLETED WITH ERRORS - %d transport/SDK errors detected.', $this->errors));
        }

        $this->line('');
    }
}
