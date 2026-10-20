<?php

/**
 * Custom Timeouts Example for Laravel
 *
 * This example demonstrates how to use custom timeouts for traces and steps.
 */

use Illuminate\Support\Facades\Route;
use Smartness\TraceFlow\Facades\TraceFlow;

// ============================================================================
// Example 1: Quick API Call (Short Timeout)
// ============================================================================

Route::get('/api/quick-check', function () {
    // Quick health check with 5-second timeout
    $trace = TraceFlow::startTrace(
        traceType: 'health_check',
        title: 'Quick Health Check',
        traceTimeoutMs: 5000,  // 5 seconds total
        stepTimeoutMs: 2000    // 2 seconds per step
    );

    $step = $trace->startStep(name: 'Check Database');
    // Perform quick DB check...
    $step->finish(['status' => 'healthy']);

    $trace->finish(['overall' => 'healthy']);

    return response()->json(['status' => 'ok']);
});

// ============================================================================
// Example 2: Long-Running Export (Extended Timeout)
// ============================================================================

Route::post('/api/export/users', function () {
    // Data export with 10-minute timeout
    $trace = TraceFlow::startTrace(
        traceType: 'data_export',
        title: 'Export Users to CSV',
        traceTimeoutMs: 600000,  // 10 minutes
        stepTimeoutMs: 120000    // 2 minutes per step
    );

    // Step 1: Query database
    $queryStep = $trace->startStep(
        name: 'Query Database',
        stepType: 'database'
    );
    $users = \App\Models\User::all();
    $queryStep->finish(['count' => $users->count()]);

    // Step 2: Generate CSV
    $exportStep = $trace->startStep(
        name: 'Generate CSV',
        stepType: 'file_generation'
    );
    $filePath = storage_path('exports/users.csv');
    // Generate CSV...
    $exportStep->finish(['path' => $filePath]);

    // Step 3: Upload to S3
    $uploadStep = $trace->startStep(
        name: 'Upload to S3',
        stepType: 'cloud_storage'
    );
    // Upload...
    $uploadStep->finish(['url' => 's3://bucket/users.csv']);

    $trace->finish(['exported' => $users->count()]);

    return response()->json(['file' => $filePath]);
});

// ============================================================================
// Example 3: Real-Time Webhook (Very Short Timeout)
// ============================================================================

Route::post('/webhook/process', function (\Illuminate\Http\Request $request) {
    // Real-time webhook with 1-second timeout
    $trace = TraceFlow::startTrace(
        traceType: 'webhook_processing',
        title: 'Process Webhook Event',
        traceTimeoutMs: 1000,  // 1 second max
        stepTimeoutMs: 300     // 300ms per step
    );

    $validateStep = $trace->startStep(name: 'Validate Payload');
    // Quick validation...
    $validateStep->finish(['valid' => true]);

    $processStep = $trace->startStep(name: 'Process Event');
    // Quick processing...
    $processStep->finish(['processed' => true]);

    $trace->finish(['latency_ms' => 250]);

    return response()->json(['status' => 'processed']);
});

// ============================================================================
// Example 4: Background Job with Custom Timeout
// ============================================================================

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Smartness\TraceFlow\TraceFlowSDK;

class ProcessLargeDataset implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(
        public int $datasetId,
        public string $traceId
    ) {}

    public function handle(TraceFlowSDK $sdk): void
    {
        // Long-running job with 30-minute timeout
        $trace = $sdk->startTrace(
            traceId: $this->traceId,
            traceType: 'batch_processing',
            title: 'Process Large Dataset',
            traceTimeoutMs: 1800000,  // 30 minutes
            stepTimeoutMs: 300000     // 5 minutes per step
        );

        $loadStep = $trace->startStep(name: 'Load Dataset');
        // Load data...
        $loadStep->finish(['records' => 1000000]);

        $processStep = $trace->startStep(name: 'Process Records');
        // Process with heartbeat
        for ($i = 0; $i < 10; $i++) {
            // Process batch...
            if ($i % 3 === 0) {
                $sdk->heartbeat($this->traceId);
            }
        }
        $processStep->finish(['processed' => 1000000]);

        $trace->finish(['success' => true]);
    }
}

Route::post('/api/process-dataset/{id}', function (string $id) {
    $trace = TraceFlow::startTrace(
        title: 'Queue Dataset Processing',
        traceTimeoutMs: 1800000
    );

    ProcessLargeDataset::dispatch($id, $trace->traceId);

    $trace->finish(['queued' => true]);

    return response()->json(['status' => 'queued']);
});

// ============================================================================
// Example 5: Artisan Command with Long Timeout
// ============================================================================

use Illuminate\Console\Command;

class ImportDataCommand extends Command
{
    protected $signature = 'data:import {file}';

    protected $description = 'Import data from file';

    public function handle(TraceFlowSDK $sdk): void
    {
        // ML training or data import with 2-hour timeout
        $trace = $sdk->startTrace(
            traceType: 'data_import',
            title: 'Import Data from File',
            traceTimeoutMs: 7200000,  // 2 hours
            stepTimeoutMs: 1800000,   // 30 minutes per step
            metadata: [
                'file' => $this->argument('file'),
            ]
        );

        $step1 = $trace->startStep(name: 'Validate File');
        $this->info('Validating file...');
        // Validation...
        $step1->finish(['valid' => true]);

        $step2 = $trace->startStep(name: 'Import Records');
        $this->info('Importing records...');

        $bar = $this->output->createProgressBar(100);
        for ($i = 0; $i < 100; $i++) {
            // Import batch...
            $bar->advance();

            // Heartbeat every 10%
            if ($i % 10 === 0) {
                $sdk->heartbeat($trace->traceId);
            }
        }
        $bar->finish();

        $step2->finish(['imported' => 10000]);

        $trace->finish(['total_records' => 10000]);

        $this->info("\nImport completed!");
    }
}

// ============================================================================
// Example 6: Using Default Timeouts
// ============================================================================

Route::get('/api/standard-process', function () {
    // No custom timeout - uses service defaults
    $trace = TraceFlow::startTrace(
        traceType: 'standard_process',
        title: 'Standard API Process'
        // trace_timeout_ms and step_timeout_ms not specified
    );

    $step = $trace->startStep(name: 'Process Request');
    // Standard processing...
    $step->finish(['success' => true]);

    $trace->finish();

    return response()->json(['status' => 'completed']);
});

// ============================================================================
// Example 7: Per-Environment Timeouts
// ============================================================================

Route::post('/api/flexible-process', function () {
    // Different timeouts per environment
    $timeouts = match (config('app.env')) {
        'local' => [
            'trace' => 300000,  // 5 minutes in local
            'step' => 60000,    // 1 minute per step
        ],
        'staging' => [
            'trace' => 600000,  // 10 minutes in staging
            'step' => 120000,   // 2 minutes per step
        ],
        'production' => [
            'trace' => 1800000, // 30 minutes in production
            'step' => 300000,   // 5 minutes per step
        ],
        default => [
            'trace' => null,    // Use service defaults
            'step' => null,
        ],
    };

    $trace = TraceFlow::startTrace(
        traceType: 'flexible_process',
        title: 'Environment-Specific Process',
        traceTimeoutMs: $timeouts['trace'],
        stepTimeoutMs: $timeouts['step']
    );

    // Processing...

    $trace->finish();

    return response()->json(['status' => 'completed']);
});

/**
 * TIMEOUT GUIDELINES
 *
 * 1. Quick API Calls: 5-30 seconds
 *    - Health checks
 *    - Simple CRUD operations
 *    - Cache lookups
 *
 * 2. Background Jobs: 1-5 minutes
 *    - Email sending
 *    - File processing
 *    - Report generation
 *
 * 3. Batch Processing: 10-60 minutes
 *    - Data imports/exports
 *    - Bulk operations
 *    - Database migrations
 *
 * 4. Long-Running Tasks: 1-24 hours
 *    - ML training
 *    - Video processing
 *    - Large-scale analytics
 *
 * 5. Default (No timeout specified):
 *    - Uses service-level configuration
 *    - Typically 30 minutes trace / 5 minutes step
 *
 * BEST PRACTICES:
 *
 * - Set realistic timeouts based on expected execution time
 * - Add 20-30% buffer for network delays and retries
 * - Use heartbeats for very long-running processes
 * - Monitor timeout events to tune your settings
 * - Different environments may need different timeouts
 * - Consider using config/traceflow.php for default values
 */
