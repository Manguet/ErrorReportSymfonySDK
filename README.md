# Error Reporter Bundle for Symfony

A Symfony bundle that automatically captures and reports exceptions to your Error Explorer monitoring platform.

## Features

- 🚀 **Automatic Exception Capture**: Listens to all unhandled exceptions in your Symfony application
- 🔧 **Zero Configuration**: Works out of the box with minimal setup
- 🛡️ **Secure**: Sanitizes sensitive data (passwords, tokens, etc.) before sending
- ⚡ **Non-blocking**: Asynchronous error reporting doesn't slow down your application
- 🎯 **Smart Filtering**: Configurable exception filtering to ignore common framework exceptions
- 📊 **Rich Context**: Captures request data, server info, stack traces, and more
- 🔄 **Wide Compatibility**: Supports Symfony 4.4+ and PHP 7.2+ for maximum compatibility

## Installation

### Step 1: Install the Bundle

```bash
composer require error-explorer/error-reporter
```

### Step 2: Register the Bundle

Add the bundle to your `config/bundles.php`:

```php
<?php

return [
    // ... other bundles
    ErrorExplorer\ErrorReporter\ErrorReporterBundle::class => ['all' => true],
];
```

### Step 3: Configure the Bundle

Create `config/packages/error_reporter.yaml`:

```yaml
error_reporter:
    webhook_url: '%env(ERROR_WEBHOOK_URL)%'
    token: '%env(ERROR_WEBHOOK_TOKEN)%'
    project_name: '%env(PROJECT_NAME)%'
    enabled: '%env(bool:ERROR_REPORTING_ENABLED)%'
    ignore_exceptions:
        - 'Symfony\Component\Security\Core\Exception\AccessDeniedException'
        - 'Symfony\Component\HttpKernel\Exception\NotFoundHttpException'

# For Symfony 4.4/5.x compatibility, you may need to adjust the env processor syntax:
# enabled: '%env(resolve:default:true:bool:ERROR_REPORTING_ENABLED)%'
```

### Step 4: Environment Variables

Add these variables to your `.env` file:

```bash
# Error Explorer Configuration
ERROR_WEBHOOK_URL=https://your-error-explorer-domain.com
ERROR_WEBHOOK_TOKEN=your-unique-project-token
PROJECT_NAME=your-project-name
ERROR_REPORTING_ENABLED=true
```

## Configuration Options

| Option | Type | Required | Description |
|--------|------|----------|-------------|
| `webhook_url` | string | Yes | The base URL of your Error Explorer instance |
| `token` | string | Yes | Unique project token from Error Explorer |
| `project_name` | string | Yes | Name identifier for your project |
| `enabled` | boolean | No | Enable/disable error reporting (default: true) |
| `ignore_exceptions` | array | No | List of exception classes to ignore |

### Default Ignored Exceptions

By default, these common Symfony exceptions are ignored:
- `Symfony\Component\Security\Core\Exception\AccessDeniedException`
- `Symfony\Component\HttpKernel\Exception\NotFoundHttpException`

You can override this list in your configuration.

## Usage

### Automatic Error Reporting

Once configured, the bundle automatically captures and reports:
- All unhandled exceptions
- HTTP errors (4xx, 5xx)
- Fatal errors and exceptions

### Manual Error Reporting

#### Option 1: Static Interface (Recommended)

The easiest way to report errors manually:

```php
use ErrorExplorer\ErrorReporter\ErrorReporter;

class YourController
{
    public function someAction()
    {
        try {
            // Your code here
        } catch (\Exception $e) {
            // Simple static call
            ErrorReporter::reportError($e, 'prod', 500);
            throw $e; // Re-throw if needed
        }
    }
}
```

#### Option 2: Service Injection

You can also inject the service directly:

```php
use ErrorExplorer\ErrorReporter\Service\WebhookErrorReporter;

class YourController
{
    /** @var WebhookErrorReporter */
    private $errorReporter;

    public function __construct(WebhookErrorReporter $errorReporter)
    {
        $this->errorReporter = $errorReporter;
    }

    public function someAction()
    {
        try {
            // Your code here
        } catch (\Exception $e) {
            // Using injected service
            $this->errorReporter->reportError($e, 'prod', 500);
            throw $e; // Re-throw if needed
        }
    }
}
```

#### Quick Helpers

```php
use ErrorExplorer\ErrorReporter\ErrorReporter;

// Simple report with defaults
ErrorReporter::report($exception);

// Report with context
ErrorReporter::reportWithContext($exception, 'staging', 404);

// Full control
ErrorReporter::reportError($exception, 'prod', 500, $request);
```

### Custom Messages & Events

#### Report Custom Messages

Report important events or custom messages without exceptions:

```php
use ErrorExplorer\ErrorReporter\ErrorReporter;

// Report a custom message
ErrorReporter::reportMessage('Payment processing failed for user 123', 'prod', 500);

// Report with custom context
ErrorReporter::reportMessage(
    'Suspicious login attempt detected', 
    'prod', 
    401, 
    null, 
    'warning', 
    ['user_id' => 123, 'ip' => '192.168.1.100']
);
```

#### Track Error Context with Breadcrumbs

Add breadcrumbs to track what led to an error. **Best Practice**: Only log critical steps that might fail, error conditions, or context needed for debugging - avoid logging successful operations:

> 💡 **When to use breadcrumbs:**
> - Before critical operations that might fail
> - When errors or warnings occur  
> - For navigation paths leading to errors
> - To track slow or problematic operations
> 
> **When NOT to use:**
> - Successful operations
> - Normal business flow that works fine
> - Too granular steps that add noise

```php
use ErrorExplorer\ErrorReporter\ErrorReporter;

// Add breadcrumbs for critical steps that might fail
ErrorReporter::addBreadcrumb('User started checkout process', 'user', 'info', ['cart_items' => 3]);

// Log navigation to track user path to error
ErrorReporter::logNavigation('/cart', '/checkout');

// Log critical user actions that might cause issues
ErrorReporter::logUserAction('Attempting payment', ['product_id' => 456]);

// Log failed HTTP requests
ErrorReporter::logHttpRequest('POST', '/api/payment', 500, ['error' => 'timeout']);

// Log slow/problematic database queries
ErrorReporter::logQuery('SELECT * FROM users WHERE id = ?', 2500, ['user_id' => 123, 'slow_query' => true]);

// When an error occurs, all breadcrumbs provide context
try {
    // Some operation that might fail
} catch (\Exception $e) {
    ErrorReporter::reportError($e); // Includes all breadcrumbs for context
}
```

#### Real-World Example

```php
use ErrorExplorer\ErrorReporter\ErrorReporter;

class CheckoutController
{
    public function processPayment($userId, $amount)
    {
        // Track critical step that might fail
        ErrorReporter::addBreadcrumb('Payment process initiated', 'payment', 'info', ['user_id' => $userId, 'amount' => $amount]);
        
        try {
            // Track critical steps (only potential failure points)
            ErrorReporter::addBreadcrumb('Validating user', 'validation');
            $user = $this->validateUser($userId);
            
            ErrorReporter::addBreadcrumb('Processing payment', 'payment', 'info', ['amount' => $amount]);
            $result = $this->paymentService->charge($user, $amount);
            
            // Success: no need to log, just return
            return $result;
            
        } catch (ValidationException $e) {
            // Report validation error with context
            ErrorReporter::reportMessage('User validation failed', 'prod', 400, null, 'error', [
                'user_id' => $userId,
                'validation_errors' => $e->getErrors()
            ]);
            throw $e;
            
        } catch (PaymentException $e) {
            // Report payment error - breadcrumbs are included automatically
            ErrorReporter::reportError($e, 'prod', 402);
            throw $e;
        }
    }
}
```

## Data Captured

The bundle captures comprehensive error context:

### Exception Data
- Exception message and class
- Stack trace
- File and line number
- HTTP status code (when applicable)

### Request Context
- URL and HTTP method
- Route name
- Client IP and User-Agent
- Request parameters (sanitized)
- Query parameters (sanitized)
- Headers (sensitive ones redacted)

### Server Context
- PHP version
- Memory usage (current and peak)
- Environment (dev/prod/test)
- Timestamp

### Breadcrumbs & User Journey
- User actions and navigation
- HTTP requests and responses
- Database queries with timing
- Custom events and markers
- Automatic chronological ordering
- Maximum 50 breadcrumbs (configurable)

### Security Features

Sensitive data is automatically sanitized:
- **Parameters**: password, token, secret, key, api_key, authorization
- **Headers**: authorization, cookie, x-api-key, x-auth-token

Sensitive values are replaced with `[REDACTED]`.

## Error Fingerprinting

Errors are automatically grouped using a fingerprint based on:
- Exception class
- File path
- Line number

This allows Error Explorer to group similar errors together.

## Compatibility

### PHP & Symfony Support

| Component | Minimum Version | Recommended |
|-----------|----------------|-------------|
| **PHP** | 7.2+ | 8.1+ |
| **Symfony** | 4.4+ | 6.0+ |

### Supported Symfony Versions
- ✅ Symfony 4.4 (LTS)
- ✅ Symfony 5.x
- ✅ Symfony 6.x  
- ✅ Symfony 7.x

### Compatibility Matrix

| PHP Version | Symfony 4.4 | Symfony 5.x | Symfony 6.x | Symfony 7.x |
|-------------|-------------|-------------|-------------|-------------|
| **7.2** | ✅ | ✅ | ❌ | ❌ |
| **7.3** | ✅ | ✅ | ❌ | ❌ |
| **7.4** | ✅ | ✅ | ❌ | ❌ |
| **8.0** | ✅ | ✅ | ✅ | ❌ |
| **8.1** | ✅ | ✅ | ✅ | ✅ |
| **8.2+** | ✅ | ✅ | ✅ | ✅ |

The bundle automatically adapts to your PHP and Symfony version for maximum compatibility.

## Development vs Production

### Development Environment

```bash
# .env.local
ERROR_REPORTING_ENABLED=false  # Disable in development
```

### Production Environment

```bash
# .env.prod
ERROR_REPORTING_ENABLED=true
ERROR_WEBHOOK_URL=https://errors.yourcompany.com
ERROR_WEBHOOK_TOKEN=prod-token-xyz
PROJECT_NAME=my-app-production
```

## Troubleshooting

### Bundle Not Capturing Errors

1. Check that `ERROR_REPORTING_ENABLED=true`
2. Verify your webhook URL and token are correct
3. Check Symfony logs for any HTTP client errors
4. Ensure the exception is not in the ignored list

### HTTP Client Errors

The bundle silently fails if the webhook is unreachable. Check your logs:

```bash
php bin/console log:search "Failed to report error"
```

### Testing the Integration

Create a test route to verify the integration:

```php
// src/Controller/TestController.php
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class TestController
{
    /**
     * @Route("/test-error-reporting")
     */
    public function testError()
    {
        throw new \Exception('Test error for Error Explorer');
    }
}
```

## License

MIT License

## Support

For issues and questions:
1. Check the Error Explorer documentation
2. Review this README
3. Check your Error Explorer dashboard for received errors
4. Verify your configuration and environment variables