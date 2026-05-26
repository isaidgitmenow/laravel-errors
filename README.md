# Laravel Errors 🚨

[![Latest Version on Packagist](https://img.shields.io/packagist/v/isaidgitmenow/laravel-errors.svg?style=flat-square)](https://packagist.org/packages/isaidgitmenow/laravel-errors)
[![Total Downloads](https://img.shields.io/packagist/dt/isaidgitmenow/laravel-errors.svg?style=flat-square)](https://packagist.org/packages/isaidgitmenow/laravel-errors)
[![Tests](https://img.shields.io/github/actions/workflow/status/isaidgitmenow/errors/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/isaidgitmenow/errors/actions/workflows/run-tests.yml)

A powerful, elegant, and declarative error handling package for modern Laravel applications (Laravel 11+).

Built with **PHP 8.4+ Attributes** and strictly adhering to **SOLID principles**, this package replaces traditional, boilerplate-heavy exception rendering and reporting methods with clean, declarative attributes directly on your Exception classes.

## ✨ Features

- **Declarative PHP 8 Attributes**: Configure HTTP codes, reporting rules, rate limiting, and context directly on your exception classes.
- **Plug-and-Play Frontend Integrations**: Automatically detects the context of the request (**Filament, Livewire, Inertia, API, Web**) and formats the response appropriately so your SPA or Admin panel doesn't break on a 500 error.
- **Bulletproof Resilience**: Includes a self-healing `try/catch` wrapper so an error in your error handler never causes a White Screen of Death (WSOD).
- **Deep Attribute Inspection**: Safely traverses Laravel's wrapped exceptions (e.g., `QueryException`, `ViewException`) to find and apply your custom attributes on the original exception.
- **Spatie Ignition & Laravel Debugbar Ready**: Seamlessly integrates with local developer tools without breaking production flows.
- **Auto-Injection into Laravel Context**: Automatically forwards `#[WithContext]` data to downstream trackers like Sentry or Flare via Laravel 11's global `Context`.
- **Data Sanitization**: Built-in redaction for sensitive keys (like passwords and API tokens) before they hit logs or external trackers.
- **Anti-Spam Rate Limiting**: Prevent cascading failures from exhausting your error tracker quotas using the `#[RateLimit]` attribute.

## 📦 Requirements

- PHP 8.4+
- Laravel 11.0+

## 🚀 Installation & Setup

You can install the package via composer:

```bash
composer require isaidgitmenow/laravel-errors
```

Optionally, publish the configuration file to customize the pipeline:

```bash
php artisan vendor:publish --tag="laravel-errors-config"
```

Integration is incredibly simple. Open your `bootstrap/app.php` and register the handler:

```php
// bootstrap/app.php
use Illuminate\Foundation\Configuration\Exceptions;
use Isaidgitmenow\LaravelErrors\ErrorHandler;

return Application::configure(basePath: dirname(__DIR__))
    // ...
    ->withExceptions(function (Exceptions $exceptions) {
        // Let the package orchestrate your entire error pipeline:
        ErrorHandler::handle($exceptions);
    })->create();
```

---

## 🏗️ Core Architecture & API Reference

The package comprises a set of decoupled, SOLID-compliant classes to manage the lifecycle of an exception. Here is a 100% documentation coverage of the package's classes, functions, and interactions.

### 1. The Bootstrapper: `ErrorHandler`

This is the public facade used in `bootstrap/app.php`.

- **`ErrorHandler::handle(Exceptions $exceptions): void`**
  Hooks into Laravel's default exception handler. It tells Laravel to `stop()` default logging (since our `LogReporter` handles it) and overrides the `render` method, passing execution entirely to the `ErrorManager`.

### 2. The Orchestrator: `ErrorManager`

The `ErrorManager` is a singleton bound to the service container. It runs the entire reporting and rendering pipeline.

- **`report(Throwable $e): void`**
  1. Checks if the exception should be ignored via `#[DontReport]`.
  2. Runs data sanitization and injects `#[WithContext]` data into Laravel's global `Context`.
  3. Iterates over all registered reporters (e.g., `LogReporter`, `DebugbarReporter`).
  4. Automatically wraps reporters with `RateLimitedReporter` if the `#[RateLimit]` attribute is present.
- **`render(Throwable $e, Request $request): ?Response`**
  1. Allows `pass_through` exceptions (like `ValidationException`) to fall back to Laravel.
  2. Intelligently yields to **Ignition** in local debug mode for Web/API requests.
  3. Iterates through the ordered list of Context Detectors. When a detector matches (e.g., Livewire), it executes the corresponding Renderer.
- **`addContext(string $detector, string $renderer): static`**
  Allows third-party packages to dynamically prepend custom Detector/Renderer pairs to the pipeline.
- **`addReporter(string $reporter): static`**
  Allows third-party packages to dynamically prepend custom Reporters to the pipeline.

### 3. The Inspector: `ExceptionInspector`

The `ExceptionInspector` utilizes PHP Reflection to recursively traverse the exception chain (bypassing Laravel wrappers like `ViewException`) and extract our custom attributes. Results are statically cached per-request for high performance.

- **`origin(Throwable $e): Throwable`**
  Finds the deepest non-framework exception in the chain that carries our custom attributes.
- **`httpCode(Throwable $e): int`**
  Returns the HTTP status defined by `#[HttpCode]`, or falls back to `getCode()` / `500`.
- **`shouldNotReport(Throwable $e): bool`**
  Returns `true` if the exception is decorated with `#[DontReport]`.
- **`reportToChannels(Throwable $e): ?array`**
  Returns the specific log channels defined by `#[ReportTo]`.
- **`translatedMessage(Throwable $e): ?string`**
  Retrieves and translates the key defined by `#[TranslatedMessage]`. Returns `null` if the translation doesn't exist.
- **`context(Throwable $e): array`**
  Extracts public properties defined by `#[WithContext]` as an associative array.
- **`rateLimit(Throwable $e): ?RateLimit`**
  Returns the `RateLimit` attribute instance if present.
- **`flushCache(): void`**
  Clears the static reflection cache. Useful during tests.

### 4. Support Tools: `DataSanitizer`

- **`DataSanitizer::sanitize(array $data, array $hiddenKeys): array`**
  Recursively traverses context data and redacts the values of any keys matching the `sanitize` array from `config/errors.php` (e.g., `password`, `api_token`), replacing them with `[REDACTED]`.

---

## 🎨 Context Detectors & Renderers

The package uses a Strategy Pipeline to automatically identify the request type and return the correct format so your frontend SPAs don't break on a 500 error.

### 🛡️ Filament Panels
- **`FilamentDetector`**: Matches requests where the path is within the Filament routing scope (e.g., `admin/*`).
- **`FilamentRenderer`**: Returns a structured JSON response simulating a Livewire `dispatch` event. If `#[TranslatedMessage]` is used, it triggers a red error Toast Notification inside the Filament UI without breaking the panel.

### ⚡ Livewire
- **`LivewireDetector`**: Matches requests carrying the `X-Livewire` header.
- **`LivewireRenderer`**: Returns an HTML response containing a script payload that Livewire can parse natively. This prevents full-page HTML crash dumps from destroying Livewire component state.

### ⚛️ Inertia.js (Vue/React/Svelte)
- **`InertiaDetector`**: Matches requests carrying the `X-Inertia` header.
- **`InertiaRenderer`**: Returns a JSON response containing an Inertia modal configuration. It uses the `#[TranslatedMessage]` and `#[HttpCode]` so your frontend SPA can catch the exception gracefully instead of logging out the user or showing raw HTML.

### 🔌 API Requests
- **`ApiDetector`**: Matches requests calling `wantsJson()` or paths matching `api/*`.
- **`ApiRenderer`**: Returns a standard JSON payload containing a `message` and `errors` array. The HTTP status code is applied from the `#[HttpCode]` attribute. This structure can be fully customized via a Closure in `config/errors.php`.

### 🌐 Standard Web Requests
- **`WebDetector`**: The fallback detector that always returns `true` if no other context matched.
- **`WebRenderer`**: Defers rendering back to Laravel (returning `null`), which natively renders the standard Blade error pages (e.g., `resources/views/errors/500.blade.php`).

---

## 📢 Reporters

Reporters determine how errors are logged or sent to external trackers (like Sentry or Flare). The pipeline loops through these sequentially.

- **`LogReporter`**:
  Uses Laravel's core `Log` facade. It respects the `#[ReportTo]` attribute to route the error to specific channels (e.g., slack, single, daily). If not specified, it falls back to the default logger.
- **`DebugbarReporter`**:
  Integrates with `barryvdh/laravel-debugbar`. It forces exceptions marked with `#[DontReport]` to still appear in the Debugbar's 'Exceptions' tab locally. It also dumps the sanitized `#[WithContext]` data directly into the 'Messages' tab.
- **`RateLimitedReporter`**:
  A dynamic proxy wrapper. The `ErrorManager` automatically wraps any reporter with this class if the exception carries the `#[RateLimit]` attribute. It uses Laravel's `RateLimiter` facade to suppress duplicate logs within the specified interval, saving API quota for Sentry/Flare.

---

## 📖 Usage: The Attributes API

Instead of writing logic inside your exception classes or the global handler, you decorate your exceptions with attributes.

### `#[HttpCode(int $code)]`
Defines the HTTP status code returned to the client.

```php
use Isaidgitmenow\LaravelErrors\Attributes\HttpCode;

#[HttpCode(402)]
class PaymentFailedException extends \RuntimeException {}
```

### `#[DontReport]`
Prevents the exception from being sent to external trackers (Log files, Sentry, Flare).

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
Provides a user-friendly, translated error message key. Filament, Inertia, and API renderers will prioritize this message to present a safe error to the frontend.

```php
use Isaidgitmenow\LaravelErrors\Attributes\TranslatedMessage;

#[TranslatedMessage('errors.billing.card_declined')]
class CardDeclinedException extends \Exception {}
```

### `#[WithContext(array $properties)]`
Automatically extracts public properties from your exception class.
1. The data is injected into Laravel 11's global `Context::addHidden('exception_context', ...)`.
2. Sentry, Flare, and your Log files will automatically pick this up.

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
Protects your error trackers from being flooded (e.g., when a database goes down). If this exception occurs more than `$max` times within `$intervalInMinutes`, subsequent occurrences will be suppressed from reporters.

```php
use Isaidgitmenow\LaravelErrors\Attributes\RateLimit;

#[RateLimit(max: 5, intervalInMinutes: 1)] // Max 5 logs per minute
class ThirdPartyApiTimeoutException extends \Exception {}
```

---

## ⚙️ Advanced Configuration & Mechanics

The package behavior can be customized via the `config/errors.php` file.

### 🔒 Data Sanitization (`DataSanitizer`)
You can define an array of sensitive keys (case-insensitive) in the config. The `DataSanitizer` will recursively replace the values of these keys with `[REDACTED]` before they are written to logs or injected into the Laravel Context.

```php
// config/errors.php
'sanitize' => [
    'password',
    'api_token',
    'credit_card',
],
```

### ⏭️ Pass-Through Exceptions
Some core Laravel exceptions have specific native rendering logic (e.g., `ValidationException` redirecting back with input). These are defined in the `pass_through` array in the config. The `ErrorManager` ignores these entirely, allowing Laravel to handle them natively.

### 🧩 Extending the Pipeline
The package uses a strategy pattern. You can inject your own Detectors, Renderers, or Reporters without modifying the package source.

```php
use Isaidgitmenow\LaravelErrors\Facades\LaravelErrors;

// In a ServiceProvider's boot method
LaravelErrors::addContext(
    MyCustomAppDetector::class,
    MyCustomAppRenderer::class
);

LaravelErrors::addReporter(MyProprietaryReporter::class);
```

## 💡 The Ultimate Exception Example

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

## 🧪 Testing

The package includes a comprehensive test suite (built with Pest and Orchestra Testbench).

```bash
composer test
```

## 📜 License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
