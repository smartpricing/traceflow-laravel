# TraceFlow SDK Tests

Comprehensive test suite for the TraceFlow Laravel SDK, with focus on async transport functionality.

## Running Tests

### All Tests
```bash
composer test
# or
vendor/bin/phpunit
```

### Unit Tests Only
```bash
composer test:unit
# or
vendor/bin/phpunit --testsuite=Unit
```

### Feature/Integration Tests Only
```bash
composer test:feature
# or
vendor/bin/phpunit --testsuite=Feature
```

### With Coverage Report
```bash
composer test:coverage
# Opens HTML report in coverage/ directory
```

### Static Analysis
```bash
composer analyse
# or
vendor/bin/phpstan analyse src --level=5
```

## Test Structure

```
tests/
├── Unit/                              # Unit tests (isolated components)
│   ├── Transport/
│   │   └── AsyncHttpTransportTest.php # Async transport tests
│   └── TraceFlowSDKTest.php           # SDK initialization tests
└── Feature/                           # Integration tests (end-to-end)
    └── AsyncTransportIntegrationTest.php
```

## Test Coverage

### AsyncHttpTransportTest (Unit)

Tests the async HTTP transport implementation:
- ✅ Non-blocking send operations
- ✅ Promise-based async behavior
- ✅ Retry logic with exponential backoff
- ✅ Silent error handling
- ✅ Flush and shutdown mechanisms
- ✅ All event types (trace, step, log)
- ✅ Authentication (API key, Basic auth)
- ✅ Performance overhead validation

### TraceFlowSDKTest (Unit)

Tests SDK initialization and configuration:
- ✅ Async transport selection (default)
- ✅ Blocking transport when disabled
- ✅ Transport configuration options
- ✅ Trace and step lifecycle
- ✅ Context propagation
- ✅ Error handling

### AsyncTransportIntegrationTest (Feature)

End-to-end integration tests:
- ✅ Complete trace lifecycle
- ✅ Multiple concurrent traces
- ✅ Trace failures and cancellations
- ✅ Nested steps
- ✅ Performance benchmarks
- ✅ Context propagation
- ✅ Heartbeat functionality
- ✅ Silent errors in production

## Key Test Scenarios

### 1. Async Performance

```php
public function test_performance_overhead_is_minimal(): void
{
    // Validates that async transport adds <1ms overhead per event
    // Sends 100 events and measures average time
}
```

### 2. Retry Logic

```php
public function test_async_retry_on_failure(): void
{
    // Mocks failures, validates exponential backoff retries
    // Ensures eventual success after transient failures
}
```

### 3. Non-Blocking Behavior

```php
public function test_send_trace_started_event_async(): void
{
    // Validates send() returns in <10ms (non-blocking)
    // Confirms promises are settled on flush()
}
```

## Mocking

Tests use `GuzzleHttp\Handler\MockHandler` to simulate HTTP responses:

```php
$mock = new MockHandler([
    new Response(201),  // Success
    new RequestException(...), // Failure
]);
```

This allows testing without actual HTTP calls.

## CI/CD Integration

Add to your CI pipeline:

```yaml
# .github/workflows/tests.yml
- name: Run Tests
  run: composer test

- name: Static Analysis
  run: composer analyse
```

## Writing New Tests

### Unit Test Template

```php
namespace Smartness\TraceFlow\Tests\Unit;

use PHPUnit\Framework\TestCase;

class MyComponentTest extends TestCase
{
    public function test_something(): void
    {
        // Arrange
        $component = new MyComponent();

        // Act
        $result = $component->doSomething();

        // Assert
        $this->assertTrue($result);
    }
}
```

### Integration Test Template

```php
namespace Smartness\TraceFlow\Tests\Feature;

use PHPUnit\Framework\TestCase;
use Smartness\TraceFlow\TraceFlowSDK;

class MyFeatureTest extends TestCase
{
    public function test_end_to_end_scenario(): void
    {
        $sdk = new TraceFlowSDK([...]);

        // Test complete workflow
        $trace = $sdk->startTrace(...);
        // ... workflow
        $trace->finish();

        $sdk->flush();

        $this->assertTrue(true);
    }
}
```

## Debugging Failed Tests

### Enable verbose output:
```bash
vendor/bin/phpunit --verbose
```

### Run specific test:
```bash
vendor/bin/phpunit --filter test_async_retry_on_failure
```

### Debug with var_dump:
```php
public function test_something(): void
{
    var_dump($someVariable);
    $this->assertTrue(true);
}
```

## Performance Benchmarks

The test suite includes performance benchmarks:

- **Async overhead**: <2ms per event
- **Blocking overhead**: 50-200ms per event
- **100 events async**: <100ms total send time

Run benchmarks:
```bash
vendor/bin/phpunit --filter performance
```

## Notes

- Tests use `silent_errors: true` to avoid exceptions
- Mock handlers simulate network conditions
- Integration tests validate end-to-end workflows
- Coverage reports generated in `coverage/` directory

## Requirements

- PHP 8.1+
- PHPUnit 10.0+
- Guzzle 7.0+
- Laravel/Illuminate Support 10.0+

## Troubleshooting

### Tests fail with "Class not found"
```bash
composer dump-autoload
```

### PHPUnit not found
```bash
composer install --dev
```

### Mock handler issues
Ensure Guzzle is installed:
```bash
composer require --dev guzzlehttp/guzzle:^7.0
```
