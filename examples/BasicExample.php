<?php

/**
 * Basic Usage Example
 * 
 * This example shows basic TraceFlow SDK usage in Laravel
 */

use Illuminate\Support\Facades\Route;
use Smartpricing\TraceFlow\Facades\TraceFlow;
use Smartpricing\TraceFlow\Enums\LogLevel;

// ============================================================================
// Example 1: Basic API Endpoint
// ============================================================================

Route::get('/api/users/{id}', function (string $id) {
    // Start trace
    $trace = TraceFlow::startTrace(
        traceType: 'api_request',
        title: "GET /api/users/{$id}"
    );

    // Step 1: Validate
    $validateStep = $trace->startStep(name: 'Validate Request');
    if (!is_numeric($id)) {
        $validateStep->fail('Invalid user ID');
        $trace->fail('Validation failed');
        return response()->json(['error' => 'Invalid ID'], 400);
    }
    $validateStep->finish(['valid' => true]);

    // Step 2: Fetch from database
    $dbStep = $trace->startStep(
        name: 'Fetch User',
        stepType: 'database',
        input: ['user_id' => $id]
    );

    $user = \App\Models\User::find($id);

    if (!$user) {
        $dbStep->fail('User not found');
        $trace->fail('User not found');
        return response()->json(['error' => 'Not found'], 404);
    }

    $dbStep->finish(['user_id' => $user->id, 'email' => $user->email]);

    // Finish trace
    $trace->finish(['user' => $user->toArray()]);

    return response()->json($user);
});

// ============================================================================
// Example 2: Using Middleware (Recommended)
// ============================================================================

Route::middleware('traceflow')->group(function () {
    Route::post('/api/orders', function (\Illuminate\Http\Request $request) {
        // Trace automatically started by middleware
        $trace = $request->attributes->get('trace');

        // Just add your steps
        $step = $trace->startStep(name: 'Create Order');

        $order = \App\Models\Order::create([
            'user_id' => $request->user()->id,
            'total' => $request->input('total'),
        ]);

        $step->finish(['order_id' => $order->id]);

        // Trace automatically finished by middleware
        return response()->json($order, 201);
    });
});

// ============================================================================
// Example 3: Service Layer Pattern
// ============================================================================

class UserService
{
    public function __construct(
        private \Smartpricing\TraceFlow\TraceFlowSDK $sdk
    ) {
    }

    public function registerUser(array $data, string $traceId): \App\Models\User
    {
        // Retrieve trace in service
        $trace = $this->sdk->getTrace($traceId);

        $trace->log('Starting user registration', LogLevel::INFO);

        // Validation step
        $validationStep = $trace->startStep(
            name: 'Validate Registration Data',
            input: $data
        );

        validator($data, [
            'email' => 'required|email|unique:users',
            'name' => 'required|string|max:255',
        ])->validate();

        $validationStep->finish(['valid' => true]);

        // Create user step
        $createStep = $trace->startStep(
            name: 'Create User',
            stepType: 'database'
        );

        $user = \App\Models\User::create($data);

        $createStep->finish(['user_id' => $user->id]);

        // Send welcome email (nested)
        $this->sendWelcomeEmail($user, $traceId);

        $trace->log('User registration completed', LogLevel::INFO);

        return $user;
    }

    private function sendWelcomeEmail(\App\Models\User $user, string $traceId): void
    {
        $trace = $this->sdk->getTrace($traceId);

        $step = $trace->startStep(
            name: 'Send Welcome Email',
            stepType: 'notification'
        );

        try {
            // Mail::to($user)->send(new WelcomeEmail());
            $step->log('Email sent successfully');
            $step->finish(['sent' => true]);
        } catch (\Exception $e) {
            $step->fail($e);
            // Don't throw - email failure shouldn't fail registration
        }
    }
}

// Controller using service
Route::post('/api/register', function (\Illuminate\Http\Request $request, UserService $service) {
    $trace = TraceFlow::startTrace(
        traceType: 'user_registration',
        title: 'Register New User'
    );

    try {
        $user = $service->registerUser(
            $request->all(),
            $trace->traceId
        );

        $trace->finish(['user_id' => $user->id]);

        return response()->json($user, 201);
    } catch (\Exception $e) {
        $trace->fail($e);
        return response()->json(['error' => $e->getMessage()], 400);
    }
});

// ============================================================================
// Example 4: Background Jobs
// ============================================================================

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class ProcessOrderJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(
        public int $orderId,
        public string $traceId
    ) {
    }

    public function handle(\Smartpricing\TraceFlow\TraceFlowSDK $sdk): void
    {
        // Retrieve trace in job
        $trace = $sdk->getTrace($this->traceId);

        $step = $trace->startStep(
            name: 'Process Order in Background',
            stepType: 'job',
            input: ['order_id' => $this->orderId]
        );

        try {
            $order = \App\Models\Order::findOrFail($this->orderId);

            // Process order...
            $order->update(['status' => 'processed']);

            $step->finish(['status' => 'processed']);
        } catch (\Exception $e) {
            $step->fail($e);
            throw $e;
        }
    }
}

// Dispatch job with trace ID
Route::post('/api/orders/{id}/process', function (string $id) {
    $trace = TraceFlow::startTrace(title: 'Process Order');

    ProcessOrderJob::dispatch($id, $trace->traceId);

    return response()->json(['message' => 'Processing started']);
});

// ============================================================================
// Example 5: Long-Running Artisan Command
// ============================================================================

use Illuminate\Console\Command;

class ImportUsersCommand extends Command
{
    protected $signature = 'users:import {file}';
    protected $description = 'Import users from CSV';

    public function handle(\Smartpricing\TraceFlow\TraceFlowSDK $sdk): void
    {
        $trace = $sdk->startTrace(
            traceType: 'batch_import',
            title: 'Import Users from CSV',
            metadata: ['file' => $this->argument('file')]
        );

        $file = $this->argument('file');
        $users = $this->loadCSV($file);

        $this->info("Importing {count($users)} users...");

        foreach ($users as $index => $userData) {
            $step = $trace->startStep(
                name: "Import User #{$index}",
                input: $userData
            );

            try {
                \App\Models\User::create($userData);
                $step->finish(['created' => true]);

                // Send heartbeat every 100 users
                if ($index % 100 === 0) {
                    $sdk->heartbeat($trace->traceId);
                    $this->info("Imported {$index} users...");
                }
            } catch (\Exception $e) {
                $step->fail($e);
                $this->error("Failed to import user #{$index}: {$e->getMessage()}");
            }
        }

        $trace->finish(['imported' => count($users)]);

        $this->info('Import completed!');
    }

    private function loadCSV(string $file): array
    {
        // Load CSV logic...
        return [];
    }
}

// ============================================================================
// Example 6: Distributed Tracing Across Services
// ============================================================================

// Service A: API Gateway
Route::post('/api/complete-registration', function (\Illuminate\Http\Request $request) {
    $trace = TraceFlow::startTrace(
        traceType: 'complete_registration',
        title: 'Complete User Registration'
    );

    // Step 1: Create user locally
    $step1 = $trace->startStep(name: 'Create User Account');
    $user = \App\Models\User::create($request->all());
    $step1->finish(['user_id' => $user->id]);

    // Step 2: Call Email Service (Service B)
    $step2 = $trace->startStep(name: 'Call Email Service');

    $response = \Illuminate\Support\Facades\Http::withHeaders([
        'X-Trace-Id' => $trace->traceId, // Propagate trace
    ])->post('http://email-service/api/send-welcome', [
        'email' => $user->email,
        'name' => $user->name,
    ]);

    $step2->finish(['response' => $response->json()]);

    // Step 3: Call Analytics Service (Service C)
    $step3 = $trace->startStep(name: 'Track Analytics');

    \Illuminate\Support\Facades\Http::withHeaders([
        'X-Trace-Id' => $trace->traceId, // Propagate trace
    ])->post('http://analytics-service/api/track', [
        'event' => 'user_registered',
        'user_id' => $user->id,
    ]);

    $step3->finish();

    $trace->finish(['user' => $user]);

    return response()->json($user);
});

// Service B: Email Service (separate app)
// In Email Service middleware receives X-Trace-Id header
Route::post('/api/send-welcome', function (\Illuminate\Http\Request $request) {
    $parentTraceId = $request->header('X-Trace-Id');

    // Continue parent trace
    $trace = TraceFlow::startTrace(
        title: 'Send Welcome Email',
        parentTraceId: $parentTraceId
    );

    $step = $trace->startStep(name: 'Send Email');

    // Mail::to($request->input('email'))->send(...);

    $step->finish(['sent' => true]);
    $trace->finish();

    return response()->json(['status' => 'sent']);
});

