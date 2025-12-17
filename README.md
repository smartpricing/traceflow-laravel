# TraceFlow SDK for Laravel

🚀 **Production-ready, stateless SDK for distributed tracing in Laravel applications**

Trace your Laravel APIs with confidence using HTTP or Kafka transport.

## ✨ Features

- **📦 Stateless** - No Redis, no databases, pure event streaming
- **🔀 Transport Agnostic** - Use HTTP REST API or Kafka
- **🧵 Context-Aware** - Automatic context propagation
- **🔄 Retry Logic** - Built-in exponential backoff
- **🛡️ Production-Ready** - Never fails your app
- **🎯 Type-Safe** - Full PHP 8.1+ support with enums
- **📝 Event-Based** - Append-only event model
- **🌊 Laravel Integration** - Middleware, Facade, Service Provider

## 📦 Installation

```bash
composer require smartpricing/traceflow-laravel
```

## 🔧 Configuration

Publish configuration:

```bash
php artisan vendor:publish --tag=traceflow-config
```

Configure in `.env`:

```env
TRACEFLOW_TRANSPORT=http
TRACEFLOW_SOURCE=my-laravel-app
TRACEFLOW_ENDPOINT=http://localhost:3009
TRACEFLOW_API_KEY=your-api-key

# Optional
TRACEFLOW_TIMEOUT=5.0
TRACEFLOW_MAX_RETRIES=3
TRACEFLOW_SILENT_ERRORS=true
```

## 🚀 Quick Start

### Using Facade

```php
use Smartpricing\TraceFlow\Facades\TraceFlow;

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
    \Smartpricing\TraceFlow\Middleware\TraceFlowMiddleware::class,
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
use Smartpricing\TraceFlow\TraceFlowSDK;

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

## 🎯 Pattern Examples

### Pattern 1: HTTP Request with Custom ID

```php
use Smartpricing\TraceFlow\Facades\TraceFlow;

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

### Pattern 3: Background Jobs

```php
use Smartpricing\TraceFlow\Facades\TraceFlow;

class ProcessOrderJob implements ShouldQueue
{
    public function __construct(
        public Order $order,
        public string $traceId
    ) {
    }
    
    public function handle(): void
    {
        // Retrieve trace in job
        $trace = TraceFlow::getTrace($this->traceId);
        
        $step = $trace->startStep(
            name: 'Background Processing',
            stepType: 'job'
        );
        
        try {
            // Process order...
            $this->order->process();
            
            $step->finish(['processed' => true]);
        } catch (\Exception $e) {
            $step->fail($e);
            throw $e;
        }
    }
}

// Dispatch job with trace ID
Route::post('/orders', function (Request $request) {
    $trace = TraceFlow::startTrace(title: 'Create Order');
    
    $order = Order::create($request->all());
    
    // Pass trace ID to job
    ProcessOrderJob::dispatch($order, $trace->traceId);
    
    return response()->json($order);
});
```

### Pattern 4: Long-Running Processes

```php
use Smartpricing\TraceFlow\Facades\TraceFlow;

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

## 🔧 API Reference

### TraceFlowSDK Methods

```php
// Start trace
$trace = $sdk->startTrace(
    traceId: 'custom-id',          // Optional
    traceType: 'process_type',     // Optional
    title: 'Human readable title', // Optional
    description: 'Description',    // Optional
    owner: 'team-name',           // Optional
    tags: ['tag1', 'tag2'],       // Optional
    metadata: ['key' => 'value'], // Optional
    params: $inputData,           // Optional
    parentTraceId: 'parent-id'    // Optional for distributed tracing
);

// Get existing trace
$trace = $sdk->getTrace('trace-id');

// Get current trace from context
$trace = $sdk->getCurrentTrace();

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

## 🌐 Distributed Tracing

### Service A (API Gateway)

```php
// Service A: Start trace
$trace = TraceFlow::startTrace(title: 'User Registration');

// Call Service B
Http::withHeaders([
    'X-Trace-Id' => $trace->traceId,
])->post('http://service-b/api/endpoint', $data);

$trace->finish();
```

### Service B (Email Service)

```php
// Service B: Continue trace
$parentTraceId = request()->header('X-Trace-Id');

$trace = TraceFlow::startTrace(
    title: 'Send Welcome Email',
    parentTraceId: $parentTraceId
);

// Process...
$trace->finish();
```

## 🧪 Testing

```php
use Smartpricing\TraceFlow\Facades\TraceFlow;

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

## 📊 Configuration Reference

```php
// config/traceflow.php
return [
    'transport' => 'http',                    // or 'kafka'
    'source' => env('APP_NAME'),
    'endpoint' => 'http://localhost:3009',
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
];
```

## 🔒 Production Best Practices

1. **Always use silent errors in production**
   ```env
   TRACEFLOW_SILENT_ERRORS=true
   ```

2. **Use middleware for automatic HTTP tracing**
3. **Pass trace IDs to queued jobs**
4. **Send heartbeats for long-running processes**
5. **Use environment variables for configuration**

## 📖 Examples

See `examples/` directory for:
- Basic usage
- Laravel API integration
- Background jobs
- Distributed tracing
- Long-running processes

## 🆚 TypeScript SDK

This is the Laravel/PHP implementation. For Node.js/TypeScript, see the main SDK in `../../`

## 🤝 Contributing

Contributions are welcome! Please open an issue or PR.

## 📄 License

MIT © Smartpricing

---

Built with ❤️ by Smartpricing

