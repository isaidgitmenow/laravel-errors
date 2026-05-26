# Laravel Errors 🚨

[![Latest Version on Packagist](https://img.shields.io/packagist/v/isaidgitmenow/laravel-errors.svg?style=flat-square)](https://packagist.org/packages/isaidgitmenow/laravel-errors)
[![Total Downloads](https://img.shields.io/packagist/dt/isaidgitmenow/laravel-errors.svg?style=flat-square)](https://packagist.org/packages/isaidgitmenow/laravel-errors)
[![Tests](https://img.shields.io/github/actions/workflow/status/isaidgitmenow/errors/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/isaidgitmenow/errors/actions/workflows/run-tests.yml)

A powerful, elegant, and declarative error handling package for modern Laravel applications (Laravel 11+). 

Built with **PHP 8.4+ Attributes** and strictly adhering to **SOLID principles**, this package replaces traditional, boilerplate-heavy exception rendering and reporting methods with clean, declarative attributes directly on your Exception classes.

## ✨ Features

- **Declarative PHP 8 Attributes**: Configure HTTP codes, reporting rules, rate limiting, and context directly on your exception classes. No more bloated `report()` or `render()` methods inside exceptions.
- **Plug-and-Play Context Detection**: Automatically detects the context of the request (Filament, Livewire, Inertia, API, Web) and formats the response appropriately.
- **Bulletproof Resilience**: Includes a self-healing `try/catch` wrapper so an error in your error handler never causes a White Screen of Death (WSOD).
- **Deep Attribute Inspection**: Safely traverses Laravel's wrapped exceptions (e.g., `QueryException`, `ViewException`) to find and apply your custom attributes on the original exception.
- **Spatie Ignition & Laravel Debugbar Ready**: Yields to Ignition when in debug mode for standard web/API requests, while ensuring `#[DontReport]` exceptions are still visible in Laravel Debugbar.
- **Auto-Injection into Laravel Context**: Seamlessly integrates with Laravel 11+ global `Context`, automatically forwarding `#[WithContext]` data to downstream trackers like Sentry or Flare.
- **Data Sanitization**: Built-in redaction for sensitive keys (like passwords and API tokens) before they hit logs or external trackers.
- **Anti-Spam Rate Limiting**: Prevent cascading failures from exhausting your error tracker quotas using the `#[RateLimit]` attribute.

## 📦 Requirements

- PHP 8.4+ (Utilizes the latest language features)
- Laravel 11.0+ (Designed for the modern `bootstrap/app.php` routing)

## 🚀 Installation

You can install the package via composer:

```bash
composer require isaidgitmenow/laravel-errors
```

Optionally, you can publish the configuration file to customize the pipeline:

```bash
php artisan vendor:publish --tag="laravel-errors-config"
```

## 🛠 Setup

Integration is incredibly simple. Open your `bootstrap/app.php` and register the handler:

```php
// bootstrap/app.php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Isaidgitmenow\LaravelErrors\ErrorHandler;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // ...
    })
    ->withExceptions(function (Exceptions $exceptions) {
        
        // Add this single line:
        ErrorHandler::handle($exceptions);

    })->create();
```

That's it! The package now orchestrates your entire error pipeline.

## 📖 Usage: The Attributes API

Instead of writing logic inside your exception classes or the global handler, you decorate your exceptions with attributes.

### `#[HttpCode(int $code)]`
Defines the HTTP status code returned to the client when this exception is rendered.

```php
use Isaidgitmenow\LaravelErrors\Attributes\HttpCode;

#[HttpCode(402)]
class PaymentFailedException extends \RuntimeException {}
```

### `#[DontReport]`
Prevents the exception from being sent to external trackers (like Sentry or Log files). 
*Note: If Laravel Debugbar is installed, the exception will still be shown locally in the Debugbar's Exceptions tab.*

```php
use Isaidgitmenow\LaravelErrors\Attributes\DontReport;

#[DontReport]
class UserCancelledActionException extends \Exception {}
```

### `#[ReportTo(string|array $channels)]`
Routes the exception log to specific logging channels defined in your `config/logging.php`.

```php
use Isaidgitmenow\LaravelErrors\Attributes\ReportTo;

#[ReportTo(['slack', 'emergency_file'])]
class CriticalDatabaseFailureException extends \Exception {}
```

### `#[TranslatedMessage(string $key)]`
Provides a user-friendly, translated error message key. Renderers (like Inertia or API) can use this to present a safe error message to the frontend without exposing technical details.

```php
use Isaidgitmenow\LaravelErrors\Attributes\TranslatedMessage;

#[TranslatedMessage('errors.billing.card_declined')]
class CardDeclinedException extends \Exception {}
```

### `#[WithContext(array $properties)]`
Automatically extracts public properties from your exception class and adds them to the error context. This data is automatically injected into Laravel's global `Context`, making it available to Sentry, Flare, and your log files.

```php
use Isaidgitmenow\LaravelErrors\Attributes\WithContext;

#[WithContext(['userId', 'transactionId'])]
class PaymentFailedException extends \RuntimeException
{
    public function __construct(
        public int $userId,
        public string $transactionId,
        string $message = ""
    ) {
        parent::__construct($message);
    }
}
```

### `#[RateLimit(int $max, int $intervalInMinutes)]`
Protects your error trackers from being flooded. If this exception occurs more than `$max` times within `$intervalInMinutes`, subsequent occurrences will be suppressed from reporters.

```php
use Isaidgitmenow\LaravelErrors\Attributes\RateLimit;

#[RateLimit(max: 5, intervalInMinutes: 1)] // Max 5 logs per minute
class ThirdPartyApiTimeoutException extends \Exception {}
```

---

## ⚙️ Advanced Configuration & Mechanics

The package behavior can be customized via the `config/errors.php` file.

### Data Sanitization
You can define an array of sensitive keys (case-insensitive) in the config. The `DataSanitizer` will recursively replace the values of these keys with `[REDACTED]` before they are written to logs or injected into the Laravel Context.

```php
// config/errors.php
'sanitize' => [
    'password',
    'password_confirmation',
    'api_token',
    'credit_card',
    'secret',
],
```

### Pass-Through Exceptions
Some core Laravel exceptions (like `ValidationException` or `AuthenticationException`) have specific native rendering logic that shouldn't be altered by this package. These are defined in the `pass_through` array in the config. The `ErrorManager` ignores these, allowing Laravel to handle them natively.

### Extending the Pipeline
The package uses a strategy pattern. You can inject your own Detectors, Renderers, or Reporters.

#### Custom Renderers & Detectors
If you have a custom frontend structure, you can add your own detector and renderer.

```php
use Isaidgitmenow\LaravelErrors\Facades\LaravelErrors;

// In a ServiceProvider's boot method
LaravelErrors::addContext(
    MyCustomAppDetector::class,
    MyCustomAppRenderer::class
);
```
Detectors are evaluated top-to-bottom. Custom contexts are prepended, giving them highest priority.

#### Custom Reporters
If you need to report errors to a proprietary system:

```php
LaravelErrors::addReporter(MyProprietaryReporter::class);
```

## 💡 Tips & Tricks

### The Ultimate Exception
Combine attributes to create highly specific, self-documenting exception behaviors:

```php
#[HttpCode(429)]
#[TranslatedMessage('errors.rate_limited')]
#[WithContext(['userId', 'attemptCount'])]
#[RateLimit(max: 1, intervalInMinutes: 10)] // Only log the first occurrence every 10 mins
#[ReportTo('slack_alerts')]
class UserRateLimitedException extends \Exception
{
    public function __construct(
        public int $userId,
        public int $attemptCount
    ) {
        parent::__construct("User rate limited");
    }
}
```

### Using the Facade for Custom Reporting
You can manually trigger the error pipeline using the Facade, which is useful for caught exceptions that you still want processed by your configured reporters.

```php
use Isaidgitmenow\LaravelErrors\Facades\LaravelErrors;

try {
    // some risky logic
} catch (\Exception $e) {
    LaravelErrors::report($e);
    
    // Optionally return a rendered response based on context
    // return LaravelErrors::render($e, request()) ?? response('Fallback Error', 500);
}
```

### How Debug Mode (`APP_DEBUG=true`) Works
When `APP_DEBUG=true`:
1. **API/Web requests**: The package intentionally steps back and lets **Spatie Ignition** render the beautiful developer error page.
2. **Interactive Contexts (Livewire/Inertia/Filament)**: The package maintains control to ensure the frontend doesn't break entirely, rendering the error appropriately for that specific stack.
3. **Debugbar**: If `barryvdh/laravel-debugbar` is installed, exceptions marked with `#[DontReport]` are *still* sent to the Debugbar, so you never miss an error while developing locally!

## 🧪 Testing

The package includes a comprehensive test suite (built with Pest and Orchestra Testbench). 

```bash
composer test
```

## 📜 License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
