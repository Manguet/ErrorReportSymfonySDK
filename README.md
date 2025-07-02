# Modern Error Reporter Bundle for Symfony

A modern, type-safe Symfony bundle that automatically captures and reports exceptions to your Error Explorer monitoring platform using the latest PHP 8.1+ and Symfony 6.x+ features.

## Features

- 🚀 **Automatic Exception Capture**: Listens to all unhandled exceptions with PHP 8 attributes
- 🔧 **Zero Configuration**: Works out of the box with intelligent defaults
- 🛡️ **Secure**: Sanitizes sensitive data automatically with modern array functions
- ⚡ **Non-blocking**: Asynchronous error reporting with retry logic and backoff
- 🎯 **Smart Filtering**: Enum-based filtering with log level priorities
- 📊 **Rich Context**: Captures comprehensive data with readonly value objects
- 🔄 **Modern Compatibility**: Requires PHP 8.1+ and Symfony 6.4+ for maximum performance
- 🏷️ **Type Safety**: Full type declarations with union types and readonly classes
- 📈 **Enhanced Breadcrumbs**: Smart categorization with icons and auto-leveling

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

#### Option 1: Modern Static Interface (Recommended)

The easiest way to report errors with modern PHP 8.1+ features:

```php
use ErrorExplorer\ErrorReporter\ErrorReporter;
use ErrorExplorer\ErrorReporter\Enum\LogLevel;

class YourController
{
    public function someAction(): Response
    {
        try {
            // Your code here
        } catch (Throwable $e) {
            // Modern static call with type safety
            ErrorReporter::reportError($e, 'prod', 500);
            throw $e; // Re-throw if needed
        }
    }
    
    public function reportCustomMessage(): void
    {
        // Report custom messages with enum levels
        ErrorReporter::reportMessage(
            message: 'Payment processing failed',
            level: LogLevel::CRITICAL,
            context: ['user_id' => 123, 'amount' => 99.99]
        );
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
use ErrorExplorer\ErrorReporter\Enum\{BreadcrumbCategory, LogLevel};

// Add breadcrumbs with modern enums and named parameters
ErrorReporter::addBreadcrumb(
    message: 'User started checkout process',
    category: BreadcrumbCategory::USER_ACTION,
    level: LogLevel::INFO,
    data: ['cart_items' => 3]
);

// Log navigation with type safety
ErrorReporter::logNavigation('/cart', '/checkout');

// Log critical user actions
ErrorReporter::logUserAction('Attempting payment', ['product_id' => 456]);

// Log HTTP requests with automatic severity detection
ErrorReporter::logHttpRequest('POST', '/api/payment', 500, ['error' => 'timeout']);

// Log database queries with performance monitoring
ErrorReporter::logQuery('SELECT * FROM users WHERE id = ?', 2500.0, ['user_id' => 123]);

// New: Log performance metrics
ErrorReporter::logPerformance('memory_usage', 128.5, 'mb');

// New: Log security events
ErrorReporter::logSecurity('Failed login attempt', LogLevel::WARNING, ['ip' => '192.168.1.1']);

// When an error occurs, all breadcrumbs provide context
try {
    // Some operation that might fail
} catch (Throwable $e) {
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

### Modern PHP & Symfony Support

| Component | Minimum Version | Recommended |
|-----------|----------------|-------------|
| **PHP** | 8.1+ | 8.3+ |
| **Symfony** | 6.4+ | 7.0+ |

### Supported Versions
- ✅ PHP 8.1+
- ✅ PHP 8.2+
- ✅ PHP 8.3+
- ✅ Symfony 6.4 (LTS)
- ✅ Symfony 7.x

### Modern Features Used

| Feature | PHP Version | Usage |
|---------|-------------|-------|
| **Enums** | 8.1+ | LogLevel, BreadcrumbCategory |
| **Readonly Classes** | 8.1+ | Value objects, Services |
| **Union Types** | 8.0+ | Method parameters |
| **Attributes** | 8.0+ | Event listeners |
| **Match Expression** | 8.0+ | Smart conditionals |
| **Named Arguments** | 8.0+ | Cleaner API calls |

The bundle leverages modern PHP features for better performance, type safety, and developer experience.

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