# TraceFlow SDK for Laravel

> **📦 Packagist Package:** This package is automatically synchronized to [`smartpricing/traceflow-laravel`](https://github.com/smartpricing/traceflow-laravel) for Packagist distribution. Install via Composer from the split repository.

[![Packagist Version](https://img.shields.io/packagist/v/smartness/traceflow-laravel.svg)](https://packagist.org/packages/smartness/traceflow-laravel)
[![PHP Version](https://img.shields.io/badge/PHP-8.1+-blue.svg)](https://www.php.net/)
[![Laravel Version](https://img.shields.io/badge/Laravel-10%20%7C%2011-orange.svg)](https://laravel.com/)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![Tests](https://img.shields.io/badge/Tests-Passing-brightgreen.svg)](tests/)

**Production-ready, stateless distributed tracing SDK for Laravel applications with event-sourced architecture.**

TraceFlow SDK for Laravel provides enterprise-grade distributed tracing capabilities with zero local state dependencies. Built on an event-sourced architecture, it delivers comprehensive observability across microservices using HTTP or Kafka transport, without compromising application reliability or performance.

## Table of Contents

- [Features](#features)
- [Installation](#installation)
- [Configuration](#configuration)
- [Quick Start](#quick-start)
- [Pattern Examples](#pattern-examples)
- [API Reference](#api-reference)
- [Cross-Service Tracing](#cross-service-tracing)
- [Testing](#testing)
- [Performance & Async Transport](#performance--async-transport)
- [Production Best Practices](#production-best-practices)
- [Examples](#examples)
- [Contributing](#contributing)
- [License](#license)

## ✨ Features

- 📦 **Stateless Architecture** - No Redis, no databases, pure event streaming
- ⚡ **Non-Blocking Performance** - Async HTTP transport with <2ms overhead per event
- 🔀 **Transport Agnostic** - Use HTTP REST API or Kafka with identical API
- 🧵 **Context-Aware** - Automatic context propagation across service boundaries
- 🔄 **Retry Logic** - Built-in exponential backoff and circuit breaker patterns
- 🛡️ **Production-Ready** - Silent error mode ensures tracing never fails your application
- 🎯 **Type-Safe** - Full PHP 8.1+ support with typed properties and enums
- 📝 **Event-Based** - Append-only event model for audit trails and replay capabilities
- 🚀 **Laravel Integration** - Seamless integration via Middleware, Facade, and Service Provider
- 🌐 **Cross-Service Tracing** - Propagate trace context across distributed systems
- 📨 **Queue Context Propagation** - Automatic trace context flow through queue jobs
- ✅ **90%+ Test Coverage** - Comprehensive unit and integration test suite

## 📦 Installation

```bash
composer require smartness/traceflow-laravel
```

## ⚙️ Configuration

Publish configuration:

```bash
php artisan vendor:publish --tag=traceflow-config
```

Configure in `.env`:

```env
TRACEFLOW_TRANSPORT=http
TRACEFLOW_SOURCE=my-laravel-app
TRACEFLOW_URL=http://localhost:3009
TRACEFLOW_API_KEY=your-api-key

# Optional
TRACEFLOW_TIMEOUT=5.0
TRACEFLOW_MAX_RETRIES=3
TRACEFLOW_SILENT_ERRORS=true

# Performance (Async HTTP enabled by default)
TRACEFLOW_ASYNC_HTTP=true

# Queue context propagation (enabled by default)
TRACEFLOW_QUEUE_PROPAGATE=true
```

## 🚀 Quick Start

### Using Facade

```php
use Smartness\TraceFlow\Facades\TraceFlow;

// Start a trace
$trace = TraceFlow::startTrace(
    traceType: 'api_request',
    title: 'Process User Request'
);

// Start a step
$step = $trace->startStep(name: 'Validate Input');
$step->log('Validation successful');
$step->finish(['valid' => true]);

// Finish trace
$trace->finish(['success' => true]);
```

### Using Middleware (Recommended)

Add middleware to `app/Http/Kernel.php`:

```php
protected $middleware = [
    // ...
    \Smartness\TraceFlow\Middleware\TraceFlowMiddleware::class,
];
```

Now all HTTP requests are automatically traced!

```php
// In your controller
public function show(Request $request, string $id)
{
    // Get trace from request
    $trace = $request->attributes->get('trace');
    
    // Add steps
    $step = $trace->startStep(name: 'Fetch User from DB');
    $user = User::find($id);
    $step->finish(['user_id' => $user->id]);
    
    return response()->json($user);
    // Trace auto-completes after response
}
```

### Using Dependency Injection

```php
use Smartness\TraceFlow\TraceFlowSDK;

class UserController extends Controller
{
    public function __construct(private TraceFlowSDK $sdk)
    {
    }
    
    public function index()
    {
        $trace = $this->sdk->startTrace(
            traceType: 'list_users',
            title: 'List Users'
        );
        
        $step = $trace->startStep(name: 'Query Database');
        $users = User::all();
        $step->finish(['count' => $users->count()]);
        
        $trace->finish(['users' => $users]);
        
        return response()->json($users);
    }
}
```

## 💡 Pattern Examples

### Pattern 1: HTTP Request with Custom ID

```php
use Smartness\TraceFlow\Facades\TraceFlow;

Route::post('/orders', function (Request $request) {
    // Start trace with custom ID
    $traceId = $request->header('X-Request-ID') ?? Str::uuid();
    
    $trace = TraceFlow::startTrace(
        traceId: $traceId,
        traceType: 'create_order',
        title: 'Create Order'
    );
    
    // Process order...
    $step1 = $trace->startStep(name: 'Validate Order');
    $step1->finish();
    
    $step2 = $trace->startStep(name: 'Save to Database');
    $order = Order::create($request->all());
    $step2->finish(['order_id' => $order->id]);
    
    $trace->finish(['order' => $order]);
    
    return response()->json($order);
});
```

### Pattern 2: Service Layer Integration

```php
class OrderService
{
    public function __construct(private TraceFlowSDK $sdk)
    {
    }
    
    public function createOrder(array $data, string $traceId): Order
    {
        // Retrieve trace in service layer
        $trace = $this->sdk->getTrace($traceId);
        
        $trace->log('Starting order creation');
        
        $step = $trace->startStep(
            name: 'Create Order',
            stepType: 'database',
            input: $data
        );
        
        try {
            $order = Order::create($data);
            
            // Send notification (nested operation)
            $this->sendOrderNotification($order, $traceId);
            
            $step->finish(['order_id' => $order->id]);
            
            return $order;
        } catch (\Exception $e) {
            $step->fail($e);
            throw $e;
        }
    }
    
    private function sendOrderNotification(Order $order, string $traceId): void
    {
        $trace = $this->sdk->getTrace($traceId);
        
        $step = $trace->startStep(name: 'Send Email Notification');
        
        // Send email...
        Mail::to($order->user)->send(new OrderCreated($order));
        
        $step->finish(['sent' => true]);
    }
}
```

### Pattern 3: Static Context Access

Access the current trace from anywhere without DI:

```php
use Smartness\TraceFlow\Context\TraceFlowContext;

class DeeplyNestedService
{
    public function doWork(): void
    {
        // No SDK injection needed — works anywhere during the request
        $traceId = TraceFlowContext::currentTraceId();

        if (TraceFlowContext::hasActiveTrace()) {
            // Use $traceId for logging, external API calls, etc.
            Log::info('Processing', ['trace_id' => $traceId]);
        }
    }
}
```

### Pattern 4: Queue Jobs with Automatic Context

Use the `TracedJob` trait for automatic trace propagation through queue jobs:

```php
use Smartness\TraceFlow\Queue\TracedJob;

class ProcessOrderJob implements ShouldQueue
{
    use TracedJob;

    public function __construct(public Order $order)
    {
        $this->initializeTracedJob(); // Captures current trace context
    }

    public function handle(): void
    {
        // Trace context is automatically restored!
        $trace = TraceFlow::getCurrentTrace();

        $step = $trace->startStep(
            name: 'Background Processing',
            stepType: 'job'
        );

        try {
            $this->order->process();

            // Dispatching another job? Context propagates automatically.
            SendConfirmationEmail::dispatch($this->order);

            $step->finish(['processed' => true]);
        } catch (\Exception $e) {
            $step->fail($e);
            throw $e;
        }
    }
}

// Dispatch — no need to pass trace ID manually
Route::post('/orders', function (Request $request) {
    $trace = TraceFlow::startTrace(title: 'Create Order');

    $order = Order::create($request->all());
    ProcessOrderJob::dispatch($order); // Context captured automatically

    return response()->json($order);
});
```

The trace context chains through any depth of job dispatches: Job A -> Job B -> Job C all share the same trace ID.

### Pattern 5: Long-Running Processes

```php
use Smartness\TraceFlow\Facades\TraceFlow;

class ImportUsersCommand extends Command
{
    public function handle(): void
    {
        $trace = TraceFlow::startTrace(
            traceType: 'batch_import',
            title: 'Import Users from CSV'
        );
        
        $users = $this->loadUsersFromCSV();
        
        foreach ($users as $index => $userData) {
            $step = $trace->startStep(
                name: "Import User #{$index}",
                input: $userData
            );
            
            User::create($userData);
            
            $step->finish();
            
            // Send heartbeat every 100 users
            if ($index % 100 === 0) {
                TraceFlow::heartbeat($trace->traceId);
            }
        }
        
        $trace->finish(['imported' => count($users)]);
    }
}
```

## 📚 API Reference

### TraceFlowSDK Methods

```php
// Start trace
$trace = TraceFlow::startTrace(
    traceType: 'process_type',     // Optional
    title: 'Human readable title', // Optional
    description: 'Description',    // Optional
    owner: 'team-name',           // Optional
    tags: ['tag1', 'tag2'],       // Optional
    metadata: ['key' => 'value'], // Optional
    params: $inputData,           // Optional
    traceTimeoutMs: 5000,         // Optional - custom timeout
    stepTimeoutMs: 2000           // Optional - custom step timeout
);

// Get existing trace
$trace = $sdk->getTrace('trace-id');

// Get current trace from context (checks SDK state + TraceFlowContext)
$trace = $sdk->getCurrentTrace();

// Set trace ID manually (used by queue middleware)
$sdk->setCurrentTraceId('trace-id');

// Send heartbeat
$sdk->heartbeat('trace-id');

// Start step (requires active trace)
$step = $sdk->startStep(
    name: 'Step Name',
    stepType: 'database',
    input: ['data'],
    metadata: ['key' => 'value']
);

// Log message
$sdk->log('Message', 'INFO', 'event_type', ['details']);
```

### TraceHandle Methods

```php
$trace->finish(result: ['data'], metadata: ['key' => 'value']);
$trace->fail(error: 'Error message');
$trace->cancel();
$trace->startStep(name: 'Step Name', ...);
$trace->log(message: 'Message', level: 'INFO', ...);
```

### StepHandle Methods

```php
$step->finish(output: ['data'], metadata: ['key' => 'value']);
$step->fail(error: 'Error message');
$step->log(message: 'Message', level: 'INFO', ...);
```

## 🌐 Cross-Service Tracing

### Service A (API Gateway)

```php
// Service A: Start trace
$trace = TraceFlow::startTrace(title: 'User Registration');

// Call Service B with trace ID
Http::withHeaders([
    'X-Trace-Id' => $trace->traceId,
])->post('http://service-b/api/endpoint', $data);

$trace->finish();
```

### Service B (Email Service)

```php
// Service B: Retrieve existing trace
$traceId = request()->header('X-Trace-Id');

// Get the trace started by Service A
$trace = TraceFlow::getTrace($traceId);

// Add steps to the same trace
$trace->startStep(name: 'Send Welcome Email');

// Process...
$trace->finish();
```

## 🧪 Testing

The SDK includes comprehensive test coverage for async transport:

```bash
# Run all tests
composer test

# Run unit tests only (async transport, SDK)
composer test:unit

# Run integration tests (end-to-end scenarios)
composer test:feature

# Generate coverage report
composer test:coverage

# Run static analysis
composer analyse
```

### Test Coverage

The SDK maintains comprehensive test coverage:

- **AsyncHttpTransport**: Non-blocking behavior, retries, promise handling
- **TraceFlowSDK**: Configuration, context propagation, lifecycle
- **Integration**: Complete workflows, performance benchmarks
- **90%+ code coverage** with unit and feature tests

See `tests/README.md` for detailed testing documentation.

### Example Test

```php
use Smartness\TraceFlow\Facades\TraceFlow;

class UserControllerTest extends TestCase
{
    public function test_creates_user()
    {
        // TraceFlow::fake(); // Coming soon

        $response = $this->post('/users', ['name' => 'John']);

        $response->assertStatus(201);
        $response->assertHeader('X-Trace-Id');
    }
}
```

## 📋 Configuration Reference

```php
// config/traceflow.php
return [
    'transport' => 'http',                    // or 'kafka'
    'async_http' => true,                     // Use async HTTP (default: true)
    'source' => env('APP_NAME'),
    'endpoint' => 'http://localhost:3009',  // env: TRACEFLOW_URL
    'api_key' => 'your-api-key',
    'username' => 'user',
    'password' => 'pass',
    'timeout' => 5.0,
    'max_retries' => 3,
    'retry_delay' => 1000,
    'silent_errors' => true,

    'middleware' => [
        'enabled' => true,
        'header_name' => 'X-Trace-Id',
    ],

    'queue' => [
        'propagate_context' => true,
    ],
];
```

## ⚡ Performance & Async Transport

**By default, the SDK uses non-blocking async HTTP** for maximum performance:

### Performance Comparison

| Transport | Overhead per Event | Blocking |
|-----------|-------------------|----------|
| **Async HTTP** (default) | **~2ms** | ❌ No |
| Blocking HTTP | ~50-200ms | ✅ Yes |

### How Async Works

1. **Fire-and-forget**: `send()` returns immediately without waiting for HTTP response
2. **Promise-based**: Uses Guzzle async promises under the hood
3. **Auto-flush**: Promises automatically settled on Laravel shutdown
4. **Retry logic**: Exponential backoff handled asynchronously

### Configuration

```env
# Enabled by default (recommended)
TRACEFLOW_ASYNC_HTTP=true

# Disable for debugging or compatibility
TRACEFLOW_ASYNC_HTTP=false
```

### Trade-offs

**Async (default)**:
- **Pros**: Minimal latency impact (~2ms), no additional infrastructure needed
- **Cons**: Events lost if PHP crashes before shutdown, slightly higher memory usage

**Blocking**:
- **Pros**: Guaranteed delivery before request completes
- **Cons**: High latency impact (50-200ms per event), slows down user requests

## 🔒 Production Best Practices

1. **Always use silent errors in production**
   ```env
   TRACEFLOW_SILENT_ERRORS=true
   ```

2. **Use middleware for automatic HTTP tracing**
3. **Use the `TracedJob` trait** for automatic queue context propagation
4. **Send heartbeats for long-running processes**
5. **Use environment variables for configuration**

## 📖 Examples

See `examples/` directory for:
- **BasicExample.php** - Fundamental SDK usage patterns
- **CustomTimeouts.php** - Configuring trace and step timeouts
- **AsyncPerformance.php** - Performance comparison and async transport demo
- Laravel API integration
- Background jobs
- Distributed tracing
- Long-running processes

## 🤝 Contributing

Contributions are welcome! Please follow these steps:

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

Please ensure:
- All tests pass (`composer test`)
- Code follows PSR-12 standards (`composer format`)
- Static analysis passes (`composer analyse`)
- You've added tests for new features

## 📄 License

MIT License - see [LICENSE](LICENSE) file for details.

Copyright © 2025 Smartness




