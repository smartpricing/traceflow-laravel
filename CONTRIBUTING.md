# Contributing to TraceFlow Laravel SDK

Thank you for considering contributing to the TraceFlow Laravel SDK! This document provides guidelines and setup instructions for local development.

## Table of Contents

- [Development Setup](#development-setup)
- [Security Guidelines](#security-guidelines)
- [Running Tests](#running-tests)
- [Code Quality](#code-quality)
- [Pull Request Process](#pull-request-process)

## Development Setup

### Prerequisites

- PHP 8.1 or higher
- Composer
- A local TraceFlow server for testing (optional but recommended)

### Installation

1. Clone the repository:
   ```bash
   git clone https://github.com/smartpricing/traceflow-sdk.git
   cd traceflow-sdk/php/laravel
   ```

2. Install dependencies:
   ```bash
   composer install
   ```

3. Configure local testing environment:
   ```bash
   # Copy PHPUnit configuration template
   cp phpunit.xml.dist phpunit.xml

   # Edit phpunit.xml and set your test credentials:
   # - TRACEFLOW_URL: Your local TraceFlow server (e.g., http://localhost:3009)
   # - TRACEFLOW_API_KEY: Your development API key
   ```

4. (Optional) Set up environment file for manual testing:
   ```bash
   # Copy example file
   cp test-live.php.example test-live.php

   # Edit test-live.php and add your credentials
   ```

## Security Guidelines

**⚠️ CRITICAL: Never commit secrets or API keys to the repository!**

### Protected Files

The following files are git-ignored and should NEVER be committed:

- `phpunit.xml` - Contains test credentials (use `phpunit.xml.dist` as template)
- `.env` - Environment configuration
- `test-live.php` - Live testing script with credentials
- `*.log` - Test output files
- `*.bak` - Backup files

### Pre-commit Hook

A pre-commit hook is automatically installed to scan for secrets. It will:
- ✅ Scan staged files for API keys, tokens, and passwords
- ❌ Block commits containing potential secrets
- 💡 Provide guidance on fixing issues

If you encounter a false positive, ensure you're using placeholder values like:
- `demo-key`
- `test-key`
- `your-api-key-here`
- `example-value`

### Best Practices

1. **Use Templates**: Always use `.example` or `.dist` files as templates
2. **Environment Variables**: Store credentials in `.env` or `phpunit.xml` (both git-ignored)
3. **Code Examples**: Use dummy values in code examples (e.g., `'demo-key'`)
4. **Never Override**: Avoid using `git commit --no-verify` unless absolutely necessary

## Running Tests

### All Tests
```bash
composer test
```

### Unit Tests Only
```bash
composer test:unit
```

### Integration Tests Only
```bash
composer test:feature
```

### With Coverage
```bash
composer test:coverage
```

### Running Specific Tests
```bash
vendor/bin/phpunit --filter TestClassName
```

## Code Quality

### Static Analysis
```bash
composer analyse
```

### Code Formatting
```bash
# Check formatting
composer format:check

# Auto-fix formatting
composer format
```

### Pre-release Checks
Run all quality checks before submitting:
```bash
composer ci
```

This runs:
- Code formatting validation
- PHPStan static analysis
- Full test suite with coverage

## Pull Request Process

1. **Fork & Branch**: Create a feature branch from `master`
   ```bash
   git checkout -b feature/your-feature-name
   ```

2. **Develop**: Make your changes following PSR-12 coding standards

3. **Test**: Ensure all tests pass
   ```bash
   composer ci
   ```

4. **Commit**: Write clear, descriptive commit messages
   ```bash
   git commit -m "Add feature: description of changes"
   ```

5. **Push**: Push to your fork
   ```bash
   git push origin feature/your-feature-name
   ```

6. **Pull Request**: Open a PR with:
   - Clear description of changes
   - Link to related issues
   - Test coverage details
   - Breaking changes (if any)

### Commit Message Guidelines

- Use present tense ("Add feature" not "Added feature")
- Use imperative mood ("Move cursor to..." not "Moves cursor to...")
- Limit first line to 72 characters
- Reference issues and pull requests where appropriate

### Code Review

All submissions require review. We use GitHub pull requests for this purpose:

- Respond to feedback promptly
- Keep discussions constructive
- Update code based on review comments
- Ensure CI passes before requesting re-review

## Testing Guidelines

### Writing Tests

- **Unit Tests**: Test individual classes in isolation (use mocks)
- **Integration Tests**: Test SDK integration with TraceFlow server
- **Coverage**: Aim for >80% code coverage on new features

### Test Structure

```php
public function test_descriptive_name_of_what_is_tested(): void
{
    // Arrange: Set up test data and dependencies
    $sdk = new TraceFlowSDK(['api_key' => 'test-key']);

    // Act: Execute the code being tested
    $result = $sdk->startTrace('Test Trace');

    // Assert: Verify the outcome
    $this->assertNotNull($result->traceId);
}
```

## Monorepo & Package Distribution

This package is part of the TraceFlow SDK monorepo but is automatically split to a separate repository for Packagist distribution:

- **Development**: Work in `traceflow-sdk/php/laravel` (monorepo)
- **Distribution**: Auto-synced to `traceflow-laravel` (split repo)
- **Installation**: Users install from Packagist via split repo

Changes pushed to the monorepo automatically trigger the split to the distribution repository.

## Questions?

- **Issues**: https://github.com/smartpricing/traceflow-sdk/issues
- **Discussions**: https://github.com/smartpricing/traceflow-sdk/discussions

Thank you for contributing! 🎉
