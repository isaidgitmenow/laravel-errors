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
- **Octane Compatible**: Automatically flushes the reflection cache after every request under Swoole / RoadRunner to prevent memory leaks.
- **Dynamic Pass-Through**: Third-party packages can register exceptions to bypass the pipeline at runtime — no config edits required.
- **Environment-Specific Reporting**: Restrict `#[ReportTo]` to specific environments (e.g., only send Slack alerts in `production`).
- **`make:error` Artisan Command**: Scaffold fully decorated exception classes in seconds with `php artisan make:error`.
- **Static Analysis Ready**: Ships with a `phpstan.neon.dist` pre-configured for [Larastan](https://github.com/larastan/larastan) level 5.
- **CI/CD Ready**: Includes a GitHub Actions workflow matrix covering PHP 8.4/8.5 × Laravel 11/12.

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
- **`passThrough(string $exceptionClass): void`** *(static)*
  Dynamically registers an exception class to bypass the pipeline entirely. Designed for use by third-party package service providers so their internal exceptions are never captured by your error handler. Unlike the `pass_through` config array, this requires no changes to published config files. See [Dynamic Pass-Through](#-dynamic-pass-through-at-runtime) for details.

### 3. The Inspector: `ExceptionInspector`

The `ExceptionInspector` utilizes PHP Reflection to recursively traverse the exception chain (bypassing Laravel wrappers like `ViewException`) and extract our custom attributes. Results are statically cached per-request for high performance.

- **`origin(Throwable $e): Throwable`**
  Finds the deepest non-framework exception in the chain that carries our custom attributes.
- **`httpCode(Throwable $e): int`**
  Returns the HTTP status defined by `#[HttpCode]`, or falls back to `getCode()` / `500`.
- **`shouldNotReport(Throwable $e): bool`**
  Returns `true` if the exception is decorated with `#[DontReport]`.
- **`reportToChannels(Throwable $e): ?array`**
  Returns the specific log channels defined by `#[ReportTo]`. Returns `null` if the attribute has an `environments` restriction and the current environment does not match, effectively suppressing reporting for this request.
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
- **`FilamentDetector`**: Matches requests by checking if there is an active Filament panel running (`\Filament\Facades\Filament::getCurrentPanel() !== null`). This works flawlessly regardless of your custom routing structure!
- **`FilamentRenderer`**: Executes a native Filament `Notification::make()->send()` to trigger a native error Toast Notification inside the Filament UI without breaking the panel. It then returns a standard JSON response to gracefully conclude the Livewire lifecycle.

### ⚡ Livewire
- **`LivewireDetector`**: Matches requests carrying the `X-Livewire` header.
- **`LivewireRenderer`**: Returns a structured JSON response containing the error message. Livewire parses this natively, preventing full-page HTML crash dumps from destroying Livewire component state.

### ⚛️ Inertia.js (Vue/React/Svelte)
- **`InertiaDetector`**: Matches requests carrying the `X-Inertia` header.
- **`InertiaRenderer`**: Depending on your `inertia_mode` config, it either shares the error globally as an Inertia `prop` and redirects back, or natively renders a dedicated Error Component via `Inertia::render()`. This allows your SPA to handle the error natively without crashing.

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

### `#[ReportTo(string|array $channels, array $environments = [])]`
Routes the exception log to specific logging channels defined in your `config/logging.php`.

```php
use Isaidgitmenow\LaravelErrors\Attributes\ReportTo;

// Always report to a specific channel:
#[ReportTo(['slack', 'emergency_file'])]
class CriticalDatabaseFailureException extends \Exception {}

// Only report in production — silent in staging/local:
#[ReportTo('slack', environments: ['production'])]
class BillingGatewayException extends \Exception {}

// Multi-channel, multi-environment:
#[ReportTo(['slack', 'pagerduty'], environments: ['production', 'staging'])]
class DatabaseReplicationLagException extends \Exception {}
```

When `environments` is non-empty, the `ExceptionInspector` checks `app()->environment($environments)` before returning the channels. If the current environment is not in the list, `reportToChannels()` returns `null` and the `LogReporter` skips sending the report entirely — no noise in local or staging, full alerts in production.

See [Environment-Specific Reporting](#-environment-specific-reporting) for a full walkthrough.

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

Run static analysis with Larastan:

```bash
composer phpstan
```

## 📜 License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

---

## 🌐 Web Renderer Example

By default, the `WebRenderer` returns `null`, intentionally yielding control back to Laravel's core rendering engine. This means you can use Laravel's standard Blade error views seamlessly with the `#[HttpCode]` attribute.

For example, if you define a custom exception with a 404 HTTP code:

```php
use Isaidgitmenow\LaravelErrors\Attributes\HttpCode;

#[HttpCode(404)]
class ProductNotFoundException extends \Exception {}
```

When this exception is thrown in a standard web request, the package detects the `Web` context, reads the `#[HttpCode(404)]` attribute, and automatically tells Laravel to render the view located at `resources/views/errors/404.blade.php`.

You just need to create the Blade file:

```blade
{{-- resources/views/errors/404.blade.php --}}
@extends('layouts.app')

@section('content')
    <div class="container text-center">
        <h1>404 - Not Found</h1>
        <p>Sorry, we couldn't find the product you're looking for!</p>
    </div>
@endsection
```

---

## 📡 API Renderer Example

When an exception is thrown during an API request (detected via `wantsJson()` or an `api/*` route), the `ApiRenderer` automatically takes over and formats the response as standard JSON.

For example, using a custom exception:

```php
use Isaidgitmenow\LaravelErrors\Attributes\HttpCode;
use Isaidgitmenow\LaravelErrors\Attributes\TranslatedMessage;

#[HttpCode(422)]
#[TranslatedMessage('errors.subscription.expired')]
class SubscriptionExpiredException extends \Exception {}
```

If a client makes a JSON request and this exception is thrown, they will receive a clean `422 Unprocessable Entity` response:

```json
{
    "message": "Your subscription has expired. Please renew to continue.",
    "errors": []
}
```

### Customizing the API Response Format

If your frontend expects a different JSON structure (e.g., JSON:API specification), you can completely customize the payload globally by defining a `json_formatter` Closure in `config/errors.php`:

```php
// config/errors.php
use Illuminate\Http\Request;

return [
    // ...
    'json_formatter' => function (\Throwable $e, Request $request) {
        return [
            'success' => false,
            'error_type' => class_basename($e),
            'developer_message' => $e->getMessage(),
            // You can even extract specific attributes
            'meta' => \Isaidgitmenow\LaravelErrors\ExceptionInspector::context($e),
        ];
    },
];
```

---

## ⚡ Livewire Renderer Example

A common pain point in Livewire development is that a backend `500 Server Error` often results in a full HTML stack trace being injected directly into your component's DOM, breaking the page entirely.

The `LivewireDetector` intercepts requests containing the `X-Livewire` header. When an exception occurs, the `LivewireRenderer` takes over and formats the error safely so Livewire doesn't crash.

### Basic Usage

You don't need to change anything in your components. If you throw a decorated exception inside a Livewire method:

```php
use Isaidgitmenow\LaravelErrors\Attributes\TranslatedMessage;
use Livewire\Component;

class CheckoutForm extends Component
{
    public function processPayment()
    {
        // ... payment fails
        
        // This exception is caught by the LivewireRenderer
        throw new #[TranslatedMessage('checkout.insufficient_funds')] \Exception();
    }
}
```

The package catches the error and returns a clean JSON response containing the message, preserving the interactive state of the rest of the page.

### Customizing the Livewire Handler

You might want to trigger a frontend notification (like a Toast or a SweetAlert) when an error occurs during a Livewire request, rather than just returning a response. You can configure a global closure in `config/errors.php` using the `livewire_handler` key:

```php
// config/errors.php
use Illuminate\Http\Request;
use Isaidgitmenow\LaravelErrors\ExceptionInspector;

return [
    // ...
    'livewire_handler' => function (\Throwable $e, Request $request) {
        $message = ExceptionInspector::translatedMessage($e) ?? $e->getMessage();
        
        // Flash the error to the session so a global Toast component can display it
        session()->flash('error', $message);
        
        // Or interact with Livewire's internal response (if needed)
    },
];
```

---

## 🛡️ Filament Renderer Example

In a Filament Admin panel, an unhandled exception usually results in an ugly modal containing a full Ignition stack trace, or worse, a broken UI state. 

The `FilamentDetector` automatically intercepts requests made inside your Filament paths. The `FilamentRenderer` then dynamically uses Filament's native Notification system to display the error, keeping your admin panel beautiful and interactive.

### Basic Usage

You can throw a decorated exception directly inside a Filament Action, Resource, or Page:

```php
use Isaidgitmenow\LaravelErrors\Attributes\TranslatedMessage;
use Filament\Actions\Action;

class CreateInvoiceAction extends Action
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->action(function () {
            // ... invoice creation fails
            
            throw new #[TranslatedMessage('invoices.creation_failed')] \RuntimeException();
        });
    }
}
```

The `FilamentRenderer` catches this exception and natively executes:
```php
\Filament\Notifications\Notification::make()
    ->title(__('Error'))
    ->body('The translated invoice creation failed message')
    ->danger()
    ->send();
```
The user simply sees a red Toast Notification in the corner of their screen!

### Customizing the Filament Handler

If you want to customize how the notification looks, or if you want to perform other actions when an error occurs in Filament, you can define a `filament_handler` in `config/errors.php`:

```php
// config/errors.php
use Illuminate\Http\Request;
use Isaidgitmenow\LaravelErrors\ExceptionInspector;
use Filament\Notifications\Notification;

return [
    // ...
    'filament_handler' => function (\Throwable $e, Request $request) {
        $message = ExceptionInspector::translatedMessage($e) ?? $e->getMessage();
        
        Notification::make()
            ->title('Oops! Something went wrong.')
            ->body($message)
            ->warning() // Make it a warning instead of danger
            ->duration(10000) // Stay on screen longer
            ->send();
    },
];
```

---

## ⚛️ Inertia.js Renderer Example

Inertia.js applications (Vue, React, Svelte) expect a specific JSON payload to update their client-side router. A raw Laravel 500 HTML error page will break an Inertia request and force a hard reload or show a blank modal.

The `InertiaDetector` catches requests containing the `X-Inertia` header. The `InertiaRenderer` then handles the exception using one of two configurable modes in `config/errors.php`: `'props'` (default) or `'redirect'`.

### Mode 1: Props (Default)

In this mode, the renderer catches the exception, shares the error globally using `Inertia::share()`, and redirects the user back to the same page (`back()->withInput()`). This allows you to show an inline error without losing the user's form data.

```php
// config/errors.php
'inertia_mode' => 'props',
```

If you throw an exception:
```php
use Isaidgitmenow\LaravelErrors\Attributes\TranslatedMessage;

throw new #[TranslatedMessage('cart.item_out_of_stock')] \Exception();
```

You can then catch this globally in your Vue/React layout by reading the shared `error` prop:

```vue
<!-- Layout.vue -->
<template>
  <div v-if="$page.props.error" class="bg-red-500 text-white p-4">
    {{ $page.props.error.status }} - {{ $page.props.error.message }}
  </div>
  <slot />
</template>
```

### Mode 2: Redirect to Error Page

If you prefer to completely redirect the user to a dedicated Error component (like an isolated 500 or 404 page in your frontend), change the mode to `redirect`.

```php
// config/errors.php
'inertia_mode' => 'redirect',
'inertia_error_component' => 'ErrorPage', // The name of your Vue/React component
```

Now, when an exception is thrown, the package executes `Inertia::render('ErrorPage')` and passes the `status` and `message` as props to that component.

```vue
<!-- Pages/ErrorPage.vue -->
<script setup>
defineProps({
  status: Number,
  message: String,
})
</script>

<template>
  <div class="error-container">
    <h1>Error {{ status }}</h1>
    <p>{{ message }}</p>
  </div>
</template>
```

---

## 🐛 Laravel Debugbar Integration Example

If you have `barryvdh/laravel-debugbar` installed, the package automatically utilizes the `DebugbarReporter` in local development (`APP_DEBUG=true`) to make debugging incredibly fast without littering your code with `dd()`.

### 1. Context Dumping

When you use the `#[WithContext]` attribute, the `DebugbarReporter` automatically JSON-encodes those properties and dumps them directly into the **Messages** tab of the Debugbar.

```php
use Isaidgitmenow\LaravelErrors\Attributes\WithContext;

#[WithContext(['userId', 'payload'])]
class InvalidWebhookException extends \Exception
{
    public function __construct(
        public int $userId,
        public array $payload
    ) {
        parent::__construct("Invalid webhook payload received.");
    }
}
```

If this exception is thrown, you don't need to check your log files. Just open the Debugbar **Messages** tab, and you will see:
```text
[InvalidWebhookException] Context: {
    "userId": 42,
    "payload": {
        "event": "charge.failed",
        "amount": 500
    }
}
```

### 2. Seeing Suppressed Errors Locally

Normally, if you decorate an exception with `#[DontReport]`, it is completely ignored by all reporters (it won't go to your log files, Sentry, Flare, etc.). 

However, during local development, this can be confusing because the error happens silently. The `ErrorManager` is smart enough to bypass the `DontReport` rule **specifically for the Debugbar**. 

```php
use Isaidgitmenow\LaravelErrors\Attributes\DontReport;

#[DontReport]
class UserCancelledActionException extends \Exception {}
```

If this exception occurs, your `laravel.log` stays clean, but the exception will still appear with its full stack trace in the **Exceptions** tab of the Debugbar, ensuring you never miss a silent failure while coding!

---

## 🌍 Translated Error Messages Example

When building user-facing applications, showing a raw backend exception message (e.g., `SQLSTATE[23000]: Integrity constraint violation`) is a terrible user experience. 

The `#[TranslatedMessage]` attribute allows you to bind a Laravel translation key directly to an exception. **All frontend renderers** (API, Livewire, Inertia, Filament) automatically prioritize this translated message over the raw exception message.

### Usage

First, define your translation in Laravel's `lang` directory (e.g., `lang/en/errors.php`):

```php
// lang/en/errors.php
return [
    'checkout' => [
        'out_of_stock' => 'We are sorry, but this item just went out of stock!',
    ],
];
```

Then, attach the attribute to your exception, pointing to that translation key:

```php
use Isaidgitmenow\LaravelErrors\Attributes\TranslatedMessage;

#[TranslatedMessage('errors.checkout.out_of_stock')]
class ItemOutOfStockException extends \Exception
{
    public function __construct(string $internalMessage = "Inventory count mismatch in DB")
    {
        // The internal message is what gets logged to Sentry or your log files
        parent::__construct($internalMessage);
    }
}
```

### What Happens Behind the Scenes?

If this exception is thrown during an **API Request**, the `ApiRenderer` will return:
```json
{
    "message": "We are sorry, but this item just went out of stock!",
    "errors": []
}
```

If it's thrown during a **Filament Request**, the `FilamentRenderer` will pop up a red Toast Notification saying:
> "We are sorry, but this item just went out of stock!"

Meanwhile, your `LogReporter` or Sentry will still receive the raw, developer-friendly message: `"Inventory count mismatch in DB"`. 

This completely separates what the **developer** sees from what the **user** sees, keeping your UI clean and your logs informative.

---

## 🧩 Custom Detectors, Renderers, and Reporters

The package is built on a strict Strategy pattern, making it infinitely extensible without modifying the core files. You can create your own Detectors, Renderers, and Reporters.

### 1. Creating a Custom Detector and Renderer

Let's say you have a custom mobile app that sends a specific `X-Mobile-App` header, and you want to return a highly specialized XML response for it.

First, implement the `ContextDetectorInterface`:

```php
namespace App\Exceptions\Handlers;

use Illuminate\Http\Request;
use Isaidgitmenow\LaravelErrors\Contracts\ContextDetectorInterface;
use Throwable;

class MobileAppDetector implements ContextDetectorInterface
{
    public function detect(Throwable $e, Request $request): bool
    {
        return $request->hasHeader('X-Mobile-App');
    }
}
```

Next, implement the `ExceptionRendererInterface`:

```php
namespace App\Exceptions\Handlers;

use Illuminate\Http\Request;
use Isaidgitmenow\LaravelErrors\Contracts\ExceptionRendererInterface;
use Isaidgitmenow\LaravelErrors\ExceptionInspector;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class MobileAppRenderer implements ExceptionRendererInterface
{
    public function render(Throwable $e, Request $request): ?Response
    {
        $message = ExceptionInspector::translatedMessage($e) ?? $e->getMessage();
        $code = ExceptionInspector::httpCode($e);

        $xml = "<error><code>{$code}</code><message>{$message}</message></error>";

        return response($xml, $code)->header('Content-Type', 'application/xml');
    }
}
```

### 2. Creating a Custom Reporter

If you want to send errors to a proprietary internal tracking system, implement the `ErrorReporterInterface`:

```php
namespace App\Exceptions\Handlers;

use Isaidgitmenow\LaravelErrors\Contracts\ErrorReporterInterface;
use Throwable;

class InternalTrackerReporter implements ErrorReporterInterface
{
    public function shouldReport(Throwable $e): bool
    {
        // Don't track 404s in the internal system
        return \Isaidgitmenow\LaravelErrors\ExceptionInspector::httpCode($e) !== 404;
    }

    public function report(Throwable $e): bool
    {
        // Send to your internal API
        Http::post('https://internal.tracker/api/errors', [
            'error' => $e->getMessage(),
            'context' => \Isaidgitmenow\LaravelErrors\ExceptionInspector::context($e),
        ]);

        return true; // Continue the reporting pipeline
    }
}
```

### 3. Registering the Extensions

You can inject your custom logic into the pipeline dynamically. The best place to do this is in the `boot` method of your `AppServiceProvider` (or a dedicated service provider).

Resolve the `ErrorManager` from the container and prepend your classes:

```php
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Isaidgitmenow\LaravelErrors\ErrorManager;
use App\Exceptions\Handlers\MobileAppDetector;
use App\Exceptions\Handlers\MobileAppRenderer;
use App\Exceptions\Handlers\InternalTrackerReporter;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->resolving(ErrorManager::class, function (ErrorManager $manager) {
            
            // 1. Add Custom Context (Detector + Renderer)
            // It will be evaluated BEFORE the default ones (like API or Web)
            $manager->addContext(
                detector: MobileAppDetector::class, 
                renderer: MobileAppRenderer::class
            );

            // 2. Add Custom Reporter
            // It will run alongside LogReporter and DebugbarReporter
            $manager->addReporter(InternalTrackerReporter::class);

        });
    }
}
```

That's it! Your mobile app now receives perfectly formatted XML errors, and your internal tracker receives sanitized error payloads automatically.

---

## 💬 Slack Integration Example

This package integrates perfectly with Laravel's native logging system, meaning sending errors to Slack is incredibly easy.

### 1. Configure the Webhook
Make sure you have set the webhook URL in your `.env` file. The `slack` channel is already pre-configured in Laravel's `config/logging.php`.

```env
LOG_SLACK_WEBHOOK_URL=https://hooks.slack.com/services/T00000000/B00000000/XXXXXXXXXXXXXXXXXXXXXXXX
```

### 2. Attach the Attribute
Simply add the `#[ReportTo('slack')]` attribute to any exception you want to send to Slack. 

```php
namespace App\Exceptions;

use Exception;
use Isaidgitmenow\LaravelErrors\Attributes\ReportTo;

#[ReportTo('slack')]
class CriticalPaymentException extends Exception
{
    // ...
}
```

The `LogReporter` will automatically detect the attribute and route the exception through `Log::channel('slack')->error(...)`. You can even route to multiple channels simultaneously using `#[ReportTo(['slack', 'daily'])]`.

---

## 🦉 NightWatch Integration Example

If you are using NightWatch (or another external/internal tracking system), you can easily integrate it by creating a custom Reporter.

### 1. Create the Reporter
Create a new class that implements `Isaidgitmenow\LaravelErrors\Contracts\ErrorReporterInterface`.

```php
namespace App\Exceptions\Reporters;

use Isaidgitmenow\LaravelErrors\Contracts\ErrorReporterInterface;
use Isaidgitmenow\LaravelErrors\ExceptionInspector;
use Illuminate\Support\Facades\Http;
use Throwable;

class NightWatchReporter implements ErrorReporterInterface
{
    public function shouldReport(Throwable $e): bool
    {
        // Define when NightWatch should ignore the exception.
        // E.g., Don't send 404 Not Found errors to NightWatch.
        return ExceptionInspector::httpCode($e) !== 404;
    }

    public function report(Throwable $e): bool
    {
        // Extract context added via #[WithContext]
        $context = ExceptionInspector::context($e);

        // Send the error to NightWatch via their API
        Http::withToken(config('services.nightwatch.token'))
            ->post('https://api.nightwatch.io/v1/errors', [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'context' => $context, // This context is already sanitized!
            ]);

        // Return true to allow other reporters (like LogReporter) to also run
        return true; 
    }
}
```

### 2. Register the Reporter
To enable your `NightWatchReporter`, register it in the `boot` method of your `AppServiceProvider`.

```php
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Isaidgitmenow\LaravelErrors\ErrorManager;
use App\Exceptions\Reporters\NightWatchReporter;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->resolving(ErrorManager::class, function (ErrorManager $manager) {
            // Register NightWatch to run alongside the default LogReporter
            $manager->addReporter(NightWatchReporter::class);
        });
    }
}
```

Now, any exception thrown in your application will be automatically intercepted, sanitized, and sent to NightWatch!

---

## 💥 Flare (or Sentry) Integration

Integrating with Flare (or Sentry) is virtually zero-config because this package leverages **Laravel 11's Global Context**.

### How it works out of the box
When you decorate an exception with `#[WithContext]`, the `ErrorManager` automatically extracts that data, sanitizes it (hiding passwords/tokens), and injects it into Laravel's `Context` facade.

Because the official Flare package (`spatie/laravel-ignition` and `spatie/flare-client-php`) automatically reads from Laravel's Context, **all your custom exception data will magically appear in your Flare dashboard!**

### Example

```php
use Isaidgitmenow\LaravelErrors\Attributes\WithContext;

#[WithContext(['userId', 'subscriptionId'])]
class BillingFailedException extends \Exception
{
    public function __construct(
        public int $userId,
        public string $subscriptionId
    ) {
        parent::__construct("Billing attempt failed.");
    }
}
```

When this exception is sent to Flare, the `userId` and `subscriptionId` will automatically be present in the **Context** tab of the error on Flare, without writing any custom Flare reporters!

*(Note: If you use `#[DontReport]`, the exception will **not** be sent to Flare, keeping your error quota safe.)*

---

## ⚙️ Queue Jobs Integration

Because this package orchestrates errors directly through Laravel's central Exception Handler (in `bootstrap/app.php`), **errors thrown inside Queue Jobs, Console Commands, and Scheduled Tasks are automatically supported.**

There is no extra setup required!

### Example

If you throw a decorated exception inside a Job:

```php
namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Isaidgitmenow\LaravelErrors\Attributes\ReportTo;
use Isaidgitmenow\LaravelErrors\Attributes\WithContext;
use Isaidgitmenow\LaravelErrors\Attributes\RateLimit;

class ProcessVideoUpload implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $videoId) {}

    public function handle(): void
    {
        // ... external API fails
        
        throw new VideoProcessingFailedException($this->videoId);
    }
}

#[ReportTo('slack')]
#[WithContext(['videoId'])]
#[RateLimit(max: 10, intervalInMinutes: 5)] // Stop spamming Slack if the queue is failing continuously
class VideoProcessingFailedException extends \Exception
{
    public function __construct(public int $videoId)
    {
        parent::__construct("Video encoding failed on the external provider.");
    }
}
```

When the Laravel Queue Worker encounters this exception, the package will automatically:
1. Extract the `videoId` into the log context.
2. Apply the anti-spam rate limiter.
3. Route the error directly to the `slack` channel.

---

## 💻 Artisan Commands Integration

Similar to Queue Jobs, exceptions thrown inside Artisan Commands (`php artisan ...`) are captured natively by the same `ErrorHandler`.

If a command fails, the exception is parsed for attributes (like `#[ReportTo]` or `#[WithContext]`) before the console terminates.

### Example

```php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Isaidgitmenow\LaravelErrors\Attributes\ReportTo;
use Isaidgitmenow\LaravelErrors\Attributes\WithContext;

class SyncRemoteDataCommand extends Command
{
    protected $signature = 'app:sync-data {provider}';

    public function handle(): int
    {
        $provider = $this->argument('provider');

        // ... sync process fails
        throw new DataSyncFailedException($provider);
    }
}

#[ReportTo(['slack', 'emergency_file'])]
#[WithContext(['providerName'])]
class DataSyncFailedException extends \Exception
{
    public function __construct(public string $providerName)
    {
        parent::__construct("Failed to synchronize data from the external provider.");
    }
}
```

If you run `php artisan app:sync-data salesforce` and it fails, the error is immediately logged to the `slack` and `emergency_file` channels, complete with the `providerName: "salesforce"` context, and then the command exits with a failure status code.

---

## 🛑 Anti-Spam: Error Rate Limiting (In-Depth)

When an external service goes down or a database connection fails, you can quickly rack up thousands of identical errors in a few seconds. This can exhaust your Sentry/Flare quota, trigger aggressive Slack notifications, and fill up your server's disk space.

This package solves this natively using the `#[RateLimit]` attribute, which is powered by Laravel's core `RateLimiter` facade.

### How it works
When an exception carrying `#[RateLimit]` is thrown, the `ErrorManager` dynamically wraps all of your reporters (like `LogReporter`, `NightWatchReporter`) in a `RateLimitedReporter`. 

If the threshold is exceeded, the reporter simply **skips** sending the error to external trackers, but **still executes the Renderers** (so the user still sees the correct 500 API/Inertia/Livewire response).

### Example Scenario

Imagine you have a billing process that calls Stripe. If Stripe's API is temporarily unreachable, you don't want 500 Slack messages.

```php
namespace App\Exceptions;

use Exception;
use Isaidgitmenow\LaravelErrors\Attributes\RateLimit;
use Isaidgitmenow\LaravelErrors\Attributes\ReportTo;
use Isaidgitmenow\LaravelErrors\Attributes\TranslatedMessage;

// Limit to 2 logs every 5 minutes
#[RateLimit(max: 2, intervalInMinutes: 5)]
#[ReportTo('slack')]
#[TranslatedMessage('checkout.payment_gateway_down')]
class PaymentGatewayOfflineException extends Exception
{
    public function __construct(string $message = "Payment provider timeout.")
    {
        parent::__construct($message);
    }
}
```

If 100 users try to checkout within 5 minutes and this exception is thrown 100 times:
1. **Occurrence 1:** Sent to Slack. User sees "Payment provider timeout" toast.
2. **Occurrence 2:** Sent to Slack. User sees "Payment provider timeout" toast.
3. **Occurrence 3 to 100:** **NOT** sent to Slack (Quota saved!). User still sees the correct "Payment provider timeout" toast.

Once the 5-minute interval passes, the rate limiter resets automatically and the next occurrence will be reported to Slack again.

---

## ⚡ Performance & Error Cache (Octane / Testing)

Because this package relies heavily on PHP 8 Attributes, it uses PHP's Reflection API to inspect exceptions when they are thrown.

To guarantee zero performance impact in production, the `ExceptionInspector` utilizes an **in-memory static cache** per-request. Once an exception class is reflected, its attributes are cached. If the same exception is thrown again during the lifecycle of the request, the reflection is skipped and the cached attributes are used instantly.

### Automatic Octane Compatibility

Under **Laravel Octane** (Swoole or RoadRunner), the application stays bootstrapped in memory across many requests. Without intervention, the static reflection cache would grow indefinitely — a classic memory leak.

This package automatically handles this for you. The `ErrorsServiceProvider` registers a listener on `\Laravel\Octane\Events\RequestTerminated` that flushes the cache at the end of every request:

```php
// This happens automatically — no configuration required.
// The event listener is only registered when laravel/octane is installed.
ExceptionInspector::flushCache();
```

There is nothing you need to do. If you install `laravel/octane`, the cache flush is wired up for you. If Octane is not installed, zero overhead is added.

### Manual Cache Flushing (Tests)
In typical Laravel requests, the static cache is naturally destroyed at the end of the request. However, during continuous PHPUnit / Pest tests, state can bleed between tests if the same exception class is used in multiple tests.

You can manually clear the reflection cache at any time by calling:

```php
use Isaidgitmenow\LaravelErrors\ExceptionInspector;

ExceptionInspector::flushCache();
```

**Testing Tip:** If you are testing exceptions in Pest or PHPUnit and asserting different attributes dynamically on the same exception class, you should call `flushCache()` in your `tearDown()` or `afterEach()` hooks:

```php
// Pest Example
afterEach(function () {
    \Isaidgitmenow\LaravelErrors\ExceptionInspector::flushCache();
});
```

---

## 🧪 Testing Your Application

When writing Feature tests for an application that uses this package, you are usually testing that your API/Livewire/Inertia endpoints gracefully handle errors and return the correct status codes and translated messages.

### 1. Do NOT disable exception handling
In Laravel testing, developers often use `$this->withoutExceptionHandling()`. If you do this, Laravel will throw the raw PHP exception during the test and the `ErrorManager` pipeline will **never run**. 

To test that your application returns the correct API/Inertia/Livewire error responses, **keep exception handling enabled** (which is the default behavior in Laravel tests).

### 2. Asserting API Responses
Because the `ApiRenderer` formats the response predictably based on your attributes, testing becomes incredibly easy.

Suppose you have an endpoint that throws a `#[HttpCode(422)]` and `#[TranslatedMessage('errors.invalid_action')]` exception.

```php
// Pest Feature Test
test('it returns a formatted 422 error when the action is invalid', function () {
    // Make an API request
    $response = $this->postJson('/api/process-action', [
        'data' => 'bad_data'
    ]);

    // Assert the package caught it and applied the #[HttpCode]
    $response->assertStatus(422);

    // Assert the package applied the #[TranslatedMessage]
    $response->assertJson([
        'message' => __('errors.invalid_action'),
        'errors' => []
    ]);
});
```

### 3. Asserting Context & Reporters
If you want to assert that an error was correctly reported to a specific channel (e.g., via `#[ReportTo]`), you can mock the `Log` facade just like you would in any standard Laravel application:

```php
use Illuminate\Support\Facades\Log;

test('it sends critical errors to the slack channel', function () {
    // Spy on the slack channel
    Log::shouldReceive('channel')
        ->with('slack')
        ->once()
        ->andReturnSelf();
        
    Log::shouldReceive('error')->once();

    // Trigger the endpoint that throws the exception
    $this->postJson('/api/critical-action');
});
```

---

## 🔀 Routing to Different Log Channels

Sometimes you don't want all errors dumped into a single `laravel.log` file. You might want critical payment errors sent to an `emergency` file, API timeouts sent to a `daily` file, and generic errors handled normally.

Because this package perfectly bridges PHP Attributes with Laravel's core Logging system, routing errors to different channels is extremely simple.

### 1. Define your Channels in Laravel
First, ensure your channels are configured in Laravel's native `config/logging.php`:

```php
// config/logging.php
'channels' => [
    'payments' => [
        'driver' => 'single',
        'path' => storage_path('logs/payments.log'),
        'level' => 'debug',
    ],
    
    'webhooks' => [
        'driver' => 'daily',
        'path' => storage_path('logs/webhooks.log'),
        'days' => 14,
    ],
],
```

### 2. Route via Attribute
Use the `#[ReportTo]` attribute on your exception. You can pass a single string or an array of multiple channels.

```php
namespace App\Exceptions;

use Exception;
use Isaidgitmenow\LaravelErrors\Attributes\ReportTo;

// Send to a single custom channel
#[ReportTo('payments')]
class PaymentDeclinedException extends Exception {}

// Send to multiple channels simultaneously!
#[ReportTo(['webhooks', 'slack'])]
class WebhookSignatureInvalidException extends Exception {}
```

When these exceptions are thrown, the `LogReporter` bypasses the default channel and explicitly routes the message (and context) only to the channels you specified:
- `PaymentDeclinedException` will ONLY be written to `storage/logs/payments.log`.
- `WebhookSignatureInvalidException` will be written to `storage/logs/webhooks-2023-10-25.log` AND sent as a message to your Slack workspace.

---

## 🚀 The Complete Lifecycle: Generating, Throwing & Logging

If you are new to the package, here is a step-by-step walkthrough of exactly how to generate an exception, throw it in your code, and see how it gets logged.

### 1. Generating the Exception
First, create your custom exception class. You can use Laravel's artisan command to create the base file:

```bash
php artisan make:exception OrderFailedException
```

### 2. Decorating with Attributes
Open the generated file in `app/Exceptions/OrderFailedException.php` and add your desired attributes.

We will add a specific HTTP Code, a translated user message, inject context, and route it directly to the `slack` channel.

```php
namespace App\Exceptions;

use Exception;
use Isaidgitmenow\LaravelErrors\Attributes\HttpCode;
use Isaidgitmenow\LaravelErrors\Attributes\ReportTo;
use Isaidgitmenow\LaravelErrors\Attributes\TranslatedMessage;
use Isaidgitmenow\LaravelErrors\Attributes\WithContext;

#[HttpCode(422)]
#[TranslatedMessage('orders.insufficient_inventory')]
#[ReportTo('slack')]
#[WithContext(['orderId', 'failedItemSku'])] // Extracts these public properties
class OrderFailedException extends Exception
{
    // The public properties defined here will be extracted by #[WithContext]
    public function __construct(
        public int $orderId,
        public string $failedItemSku,
        string $internalDeveloperMessage = "Order processing failed due to inventory mismatch."
    ) {
        // The internal message is what gets logged (not shown to the user)
        parent::__construct($internalDeveloperMessage);
    }
}
```

### 3. Throwing the Exception
Now, you simply `throw` this exception anywhere in your application logic (Controllers, Services, Jobs, etc.).

```php
namespace App\Services;

use App\Exceptions\OrderFailedException;
use App\Models\Order;

class OrderProcessingService
{
    public function process(Order $order)
    {
        $inventoryAvailable = false; // Simulated logic
        
        if (! $inventoryAvailable) {
            // Throw the custom exception and pass the necessary context data
            throw new OrderFailedException(
                orderId: $order->id,
                failedItemSku: 'SKU-999'
            );
        }
        
        // ... continue processing
    }
}
```

### 4. What Happens Behind the Scenes?
The moment you run `throw new OrderFailedException(...)`:

1. **The Application Stops**: The standard execution stops and Laravel hands the exception over to the `ErrorHandler` in `bootstrap/app.php` (which is powered by this package).
2. **Context Extraction**: The package reads `#[WithContext]`, takes `$orderId` and `$failedItemSku`, and dynamically injects them into Laravel's Global Context (and sanitizes them if needed).
3. **The Reporters Run**: 
   - The package reads `#[ReportTo('slack')]`.
   - It fires `Log::channel('slack')->error("Order processing failed due to inventory mismatch.", ['orderId' => ..., 'failedItemSku' => ...])`.
   - The developer gets a Slack notification immediately!
4. **The Renderers Run**:
   - If the request came from an **API**, it returns a `422` JSON response with the translated message.
   - If it came from **Livewire/Filament**, it triggers a native red Toast notification with the translated message.
   - If it came from a **Queue Job**, the job is marked as failed (and no HTTP response is rendered).

All of this happens instantly, with zero boilerplate in your controllers!

---

## 🕵️‍♂️ How Does It Know the Request Context?

You might be wondering: *"When an exception is thrown, how does the package know whether to return an Inertia modal, a Filament toast, or a standard JSON response?"*

The package uses a pipeline of **Context Detectors** configured in `config/errors.php`. When an exception hits the `ErrorManager`, it passes the current HTTP `Request` through these detectors from top to bottom. The first one that returns `true` wins!

Here is how the default detectors work:

1. **`FilamentDetector`**: Safely checks if there is an active Filament panel running (`\Filament\Facades\Filament::getCurrentPanel() !== null`).
2. **`LivewireDetector`**: Checks if the request contains the `X-Livewire` header.
3. **`InertiaDetector`**: Checks if the request contains the `X-Inertia` header.
4. **`ApiDetector`**: Checks if the request expects JSON (`$request->wantsJson()`) or if the URL path starts with `/api/*`.
5. **`WebDetector`**: The ultimate fallback. Always returns `true` and defers rendering back to standard Laravel Blade views.

Because this detection is dynamic and based entirely on the HTTP Request, headers, and running facades, **you can throw the exact same exception class from a background Job, an API Controller, or a Filament Action**, and the package will perfectly adapt the visual response to the user's environment!

---

## 🏗️ Building a Custom Detector (Step-by-Step)

If you are building a specific client (like a mobile iOS app) and you want to format errors in a highly specific way just for that client, you can inject a new Detector into the pipeline.

### 1. Create the Detector
Implement `ContextDetectorInterface` to detect your custom environment.

```php
namespace App\Exceptions\Handlers;

use Illuminate\Http\Request;
use Isaidgitmenow\LaravelErrors\Contracts\ContextDetectorInterface;
use Throwable;

class IosAppDetector implements ContextDetectorInterface
{
    public function detect(Throwable $e, Request $request): bool
    {
        // For example, looking for a specific header sent by the iOS app
        return $request->hasHeader('X-iOS-App');
    }
}
```

### 2. Create the Renderer
Implement `ExceptionRendererInterface` to format the response when the detector matches.

```php
namespace App\Exceptions\Handlers;

use Illuminate\Http\Request;
use Isaidgitmenow\LaravelErrors\Contracts\ExceptionRendererInterface;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class IosAppRenderer implements ExceptionRendererInterface
{
    public function render(Throwable $e, Request $request): ?Response
    {
        return response()->json([
            'ios_alert_title' => 'Error',
            'ios_alert_body' => $e->getMessage()
        ], 500);
    }
}
```

### 3. Register the Pipeline
Bind them together in your `AppServiceProvider`.

```php
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Isaidgitmenow\LaravelErrors\ErrorManager;
use App\Exceptions\Handlers\IosAppDetector;
use App\Exceptions\Handlers\IosAppRenderer;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->resolving(ErrorManager::class, function (ErrorManager $manager) {
            
            // Add your custom context. 
            // It will be evaluated BEFORE the default ones (like API or Web).
            $manager->addContext(
                detector: IosAppDetector::class, 
                renderer: IosAppRenderer::class
            );

            // You can add as many custom detectors as you want!
            // The order in which you call addContext() is the exact order they will be evaluated.
            // All custom detectors run BEFORE the default package detectors.
            $manager->addContext(
                detector: AndroidAppDetector::class,
                renderer: AndroidAppRenderer::class
            );
            
        });
    }
}
```

### Order of Evaluation
If you have multiple custom detectors, the `ErrorManager` evaluates them in the **exact order** you call `addContext()`. 

Furthermore, **all dynamically added contexts are prepended** to the default pipeline. This means your custom `IosAppDetector` and `AndroidAppDetector` will always be evaluated *before* the package's default `ApiDetector` or `WebDetector`.

---

## 🤔 Do I Still Need `try/catch`?

A common question when adopting this package is whether you still need to use `try/catch` blocks in your application code.

The short answer is: **No for fatal errors, Yes for recovery/fallback.**

This package changes how you view errors: they are no longer just "code crashes", but declarative communication tools.

### 1. When you NO LONGER need `try/catch` (95% of cases)
Traditionally, you would catch an exception just to log it and return a formatted HTTP response. **You no longer need to do this.** If the goal is to stop execution and show an error, just `throw`.

❌ **The Old Way (Messy Controllers):**
```php
public function processPayment()
{
    try {
        $stripe->charge($amount);
    } catch (Exception $e) {
        Log::channel('slack')->error("Payment failed: " . $e->getMessage());
        return response()->json(['message' => 'Payment failed. Try again.'], 422);
    }
}
```

✅ **The New Way (Clean Controllers):**
```php
public function processPayment()
{
    if ($stripeFails) {
        // Just throw it! The package handles the Slack log and the 422 JSON response.
        throw new PaymentFailedException($amount);
    }
}
```

### 2. When you STILL need `try/catch` (Fallback Logic)
You only need `try/catch` when you **do not** want the application to stop, but instead want to attempt a "Plan B".

**Example A: Graceful Degradation (Fallback)**
If an external API goes down, you might want to load stale data from Cache instead of showing an error page.
```php
try {
    $rate = Api::getExchangeRate();
} catch (ConnectionException $e) {
    // We catch the error so the app DOES NOT crash. We use a fallback instead.
    $rate = Cache::get('last_known_rate'); 
}
```

**Example B: Database Rollbacks**
When performing complex DB operations, you need to catch exceptions to rollback the transaction. However, you should still `throw` the exception at the end of the catch block so the package can process it!
```php
DB::beginTransaction();

try {
    $user->update([...]);
    $invoice->generate([...]);
    DB::commit();
} catch (Exception $e) {
    DB::rollBack(); // 1. Revert database changes
    
    // 2. Throw a decorated exception so the package can log it and notify the user!
    throw new DatabaseTransactionFailedException($e->getMessage());
}
```

**Example C: Loop Continuation (Partial Failures)**
When processing a batch of items (like a CSV import or sending newsletters), you don't want one bad row to abort the entire job.
```php
foreach ($users as $user) {
    try {
        $this->newsletterService->sendTo($user);
    } catch (EmailBouncedException $e) {
        // Log this specific failure, but DO NOT stop the loop!
        // We can manually trigger the package's pipeline if we still want it reported:
        app(\Isaidgitmenow\LaravelErrors\ErrorManager::class)->report($e);
        
        continue; // Process the next user
    }
}
```

**Example D: Exception Translation (Wrapping 3rd Party Errors)**
If a vendor package throws a generic exception (which you cannot decorate because you don't own the code), you can catch it and throw your own decorated exception instead.
```php
try {
    $mailchimp->subscribe($email);
} catch (\Mailchimp\Exceptions\TimeoutException $e) {
    // Catch the generic 3rd party exception and throw our own decorated one
    throw new NewsletterSubscriptionFailedException($email, $e);
}
```

By embracing the **"Fail Fast"** principle, your controllers and services will become incredibly thin. Just `throw` the decorated exceptions and let the package do the heavy lifting!

---

## 🚧 Bypassing the Pipeline: Native Laravel Exceptions

You might be wondering: *"What happens when Laravel throws a `ValidationException` (422) during form validation, an `AuthorizationException` (403) from a Gate, or a `NotFoundHttpException` (404) for a missing page? Will the package intercept them and log them as 500 errors?"*

The answer is **No**. The package has a built-in `pass_through` mechanism defined in `config/errors.php`. This array comes pre-configured with all of Laravel's core HTTP and routing exceptions:

```php
'pass_through' => [
    \Illuminate\Validation\ValidationException::class,
    \Illuminate\Auth\AuthenticationException::class,
    \Illuminate\Auth\Access\AuthorizationException::class,
    \Symfony\Component\HttpKernel\Exception\HttpException::class, // Catches 404s, 405s, 429s, etc.
    \Illuminate\Database\Eloquent\ModelNotFoundException::class,  // Catches User::findOrFail()
    \Illuminate\Session\TokenMismatchException::class,            // Catches CSRF failures
    \Illuminate\Http\Exceptions\HttpResponseException::class,
],
```

When the `ErrorManager` encounters an exception that is an `instanceof` any class listed in this array, it immediately halts its own pipeline and **yields full control back to Laravel's native exception handler**. 

This guarantees that form validation redirects, `$errors` bags, unauthenticated redirects, 403 Forbidden pages, and standard 404 Not Found pages work exactly as they normally do in standard Laravel, without triggering false alarms in your Slack channel or error logs!

### 🔌 Dynamic Pass-Through at Runtime

The `pass_through` config is great for your own application, but it requires editing the published config file. If you are a **package author** and want your internal exceptions to always bypass the pipeline without forcing the user to touch their config, use the static `passThrough()` method:

```php
// In your package's ServiceProvider boot() method:
use Isaidgitmenow\LaravelErrors\ErrorManager;
use Isaidgitmenow\LaravelErrors\Facades\LaravelErrors;

// Via the ErrorManager directly:
ErrorManager::passThrough(MyVendorInternalException::class);

// Or via the Facade:
LaravelErrors::passThrough(MyVendorInternalException::class);
```

This merges your exceptions into the pass-through list at runtime, without requiring the end user to publish and edit `config/errors.php`. The static registry is separate from the config array, so both are always respected.

**Application developers** can also use this for one-off registrations inside `AppServiceProvider::boot()`:

```php
public function boot(): void
{
    // Bypass the pipeline for a third-party exception you cannot decorate
    LaravelErrors::passThrough(\Vendor\Package\SomeInternalException::class);
}
```

---

## 🤷‍♂️ Handling Generic (Un-decorated) Exceptions

What happens if you (or a third-party package) throw a raw exception without any of our custom attributes?

```php
throw new \Exception("Something broke!");
```

The package is smart enough to handle this perfectly! 
1. It defaults to an **HTTP 500** status code.
2. It sends the log to your default logging channel (via Laravel's standard Log facade).
3. Most importantly: **It still uses the Context Renderers!** 

This means if a raw `PDOException` is thrown during a **Livewire** request, the `LivewireRenderer` will still intercept it and return a safe JSON payload instead of a crashing HTML stack trace. Your app's frontend stays resilient even for unexpected, un-decorated errors!

---

## 📝 A Note on API Form Requests (`ValidationException`)

If you are building an API and using Laravel's `FormRequest` classes, you might wonder what happens when a user submits invalid data. Does the package log the error? Does it change the 422 JSON response?

The answer is **No, by design.**

As mentioned in the *Bypassing the Pipeline* section, `\Illuminate\Validation\ValidationException::class` is in the `pass_through` array by default.

When validation fails in a Form Request:
1. Laravel throws a `ValidationException`.
2. The `ErrorManager` sees it in the `pass_through` array and ignores it.
3. It is **not** logged to Slack, Flare, or your log files (which is good, you don't want alerts for every typo a user makes).
4. Laravel natively takes over and returns the standard `422 Unprocessable Entity` JSON response with the `$errors` bag.

If you ever *want* to intercept validation errors (for example, to force them into a proprietary JSON format via your `ApiRenderer`), simply remove `ValidationException::class` from the `pass_through` array in `config/errors.php`. However, sticking to Laravel's native 422 structure is highly recommended!

---

## 🌐 Advanced API: Complying with JSON:API Specification

Many modern APIs adhere to the strict [JSON:API Specification](https://jsonapi.org/format/#errors). By default, Laravel returns a simple `{ "message": "...", "errors": {} }` structure. 

With this package, you can instantly upgrade your entire application to output strictly compliant JSON:API errors by leveraging the `json_formatter` closure in `config/errors.php`, combined with the exception attributes!

### 1. Update the Config
Open your `config/errors.php` and configure the globally applied `json_formatter`:

```php
// config/errors.php
use Illuminate\Http\Request;
use Isaidgitmenow\LaravelErrors\ExceptionInspector;

return [
    // ...
    
    'json_formatter' => function (\Throwable $e, Request $request): array {
        $httpCode = ExceptionInspector::httpCode($e);
        $message = ExceptionInspector::translatedMessage($e) ?? $e->getMessage();
        $context = ExceptionInspector::context($e); // Extracted from #[WithContext]
        
        return [
            'errors' => [
                [
                    'status' => (string) $httpCode,
                    'title' => class_basename($e),
                    'detail' => $message,
                    'meta' => empty($context) ? null : $context,
                ]
            ]
        ];
    },
];
```

### 2. Throw your exceptions normally

```php
use Isaidgitmenow\LaravelErrors\Attributes\HttpCode;
use Isaidgitmenow\LaravelErrors\Attributes\TranslatedMessage;
use Isaidgitmenow\LaravelErrors\Attributes\WithContext;

#[HttpCode(403)]
#[TranslatedMessage('api.insufficient_permissions')]
#[WithContext(['requiredRole'])]
class UnauthorizedActionException extends \Exception
{
    public function __construct(public string $requiredRole)
    {
        parent::__construct("User lacks the required role to perform this action.");
    }
}
```

### 3. The Result
When this exception is thrown in an API request, the client receives the beautifully formatted, JSON:API compliant response:

**HTTP Status: 403 Forbidden**
```json
{
    "errors": [
        {
            "status": "403",
            "title": "UnauthorizedActionException",
            "detail": "You do not have permission to perform this action.",
            "meta": {
                "requiredRole": "super-admin"
            }
        }
    ]
}
```
This guarantees that **every single exception** thrown in your application will conform to your company's API contract natively, without repeating formatting logic in your controllers!

---

## 🛡️ Working with Gates & Permissions (`AuthorizationException`)

When you use Laravel's native authorization features (like **Gates** or **Policies**), Laravel automatically throws an `\Illuminate\Auth\Access\AuthorizationException` if the user is not allowed to perform an action.

Because this package is designed to intercept unhandled exceptions, you might wonder: *"Will my 403 Forbidden errors get logged as 500 Server Errors in Slack?"*

**By default, NO!** The package handles this gracefully because `AuthorizationException` is included in the `pass_through` array in `config/errors.php`:

```php
'pass_through' => [
    \Illuminate\Validation\ValidationException::class,
    \Illuminate\Auth\AuthenticationException::class,
    \Illuminate\Auth\Access\AuthorizationException::class, // <-- Ignores Policy/Gate failures!
],
```

### How to use Gates/Policies with this package
You don't need to change anything about how you write your code. Just use Laravel's native authorization methods:

```php
// In a Controller
public function destroy(Post $post)
{
    // If this fails, Laravel throws AuthorizationException.
    // Our package ignores it, and Laravel natively returns a 403 response!
    Gate::authorize('delete', $post);
    
    $post->delete();
}
```

**What if I WANT to log authorization failures?**
If you are building a high-security application (like banking) and you actively *want* to be notified on Slack whenever someone attempts an unauthorized action, you can remove `AuthorizationException::class` from the `pass_through` array.

Then, you can map the native exception to your custom attributes using the package's pipeline, or simply create a custom exception for security breaches:

```php
// Throw a decorated exception for security breaches instead of a standard Gate!
if (Gate::denies('delete', $post)) {
    throw new SecurityBreachException('User attempted to delete a post they do not own!');
}
```

---

## 🚦 The "Pass Through" Exceptions (Complete List)

By default, the package ships with a pre-configured `pass_through` array in `config/errors.php`. When an exception listed here is thrown, the package **immediately stops its own pipeline** and lets Laravel handle it natively.

Here is the complete list and *why* they are bypassed:

1. **`\Illuminate\Validation\ValidationException::class`**
   - **Why:** Thrown by FormRequests. Bypassing it ensures your `$errors` variable is populated in Blade and that API users receive the standard 422 JSON response.
2. **`\Illuminate\Auth\AuthenticationException::class`**
   - **Why:** Thrown when a guest visits a protected route. Bypassing it ensures the user gets redirected to `/login` (Web) or receives a 401 response (API) natively.
3. **`\Illuminate\Auth\Access\AuthorizationException::class`**
   - **Why:** Thrown when a Gate or Policy fails. Bypassing it ensures Laravel renders a standard 403 Forbidden page instead of logging a 500 Server Error.
4. **`\Symfony\Component\HttpKernel\Exception\HttpException::class`**
   - **Why:** The base class for all HTTP errors (`NotFoundHttpException`, `TooManyRequestsHttpException`, etc.). Bypassing this guarantees that 404s and Rate Limits are handled by Laravel natively.
5. **`\Illuminate\Database\Eloquent\ModelNotFoundException::class`**
   - **Why:** Thrown by `User::findOrFail($id)`. Laravel natively converts this to a 404 Not Found. Bypassing it prevents false-positive 500 error logs.
6. **`\Illuminate\Session\TokenMismatchException::class`**
   - **Why:** Thrown when a CSRF token expires. Bypassing it lets Laravel show the standard 419 Page Expired template.
7. **`\Illuminate\Http\Exceptions\HttpResponseException::class`**
   - **Why:** An internal Laravel exception used to abruptly halt execution and return a raw Response object. Must be bypassed.

### Interacting with Pass-Through Exceptions (Injecting Custom Logic)

If you have a strict requirement to log or customize the response of a native Laravel exception, you have full control!

Because you cannot add PHP 8 Attributes directly to native classes (like `ModelNotFoundException`), the most elegant way to handle them is to **catch them and translate them into your own Decorated Exceptions**.

Here is the step-by-step example for all native errors:

#### Step 1: Remove the Exception from `pass_through`
First, open `config/errors.php` and delete the exception you want to intercept (e.g., `ModelNotFoundException`) from the `pass_through` array.

#### Step 2: Create a Decorated Exception
Create a custom exception that extends `Exception` (or the native exception) and add your package attributes:

```php
use Exception;
use Isaidgitmenow\LaravelErrors\Attributes\HttpCode;
use Isaidgitmenow\LaravelErrors\Attributes\ReportTo;
use Isaidgitmenow\LaravelErrors\Attributes\TranslatedMessage;

#[HttpCode(404)]
#[ReportTo('slack')] // We want Slack to know when a model is not found!
#[TranslatedMessage('The requested resource could not be found.')]
class CustomResourceNotFoundException extends Exception {}
```

#### Step 3: Translate it in `bootstrap/app.php`
Use Laravel's `renderable` hook to catch the native exception and throw your decorated one *before* it hits the package pipeline:

```php
// bootstrap/app.php
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Auth\Access\AuthorizationException;

->withExceptions(function (Exceptions $exceptions) {
    
    // Example 1: Translating a Model Not Found Error
    $exceptions->renderable(function (ModelNotFoundException $e) {
        throw new CustomResourceNotFoundException('Model missing: ' . $e->getModel());
    });

    // Example 2: Translating a 403 Gate/Policy Error
    $exceptions->renderable(function (AuthorizationException $e) {
        throw new CustomSecurityBreachException('Unauthorized access attempt!');
    });

    // Finally, load the package
    \Isaidgitmenow\LaravelErrors\ErrorHandler::handle($exceptions);
})
```

**Why is this brilliant?** 
Because `ErrorHandler::handle($exceptions)` runs *after* your `renderable` callbacks, the package will immediately catch your newly thrown `CustomResourceNotFoundException`. It will see the `#[ReportTo('slack')]` attribute, send the notification to your Slack channel, evaluate the Context Detectors, and return a perfectly formatted 404 Inertia Modal or API JSON response! 

This pattern works universally for **any** of the 7 pass-through exceptions listed above.

---

## 🤯 Exotic Use Cases & Advanced Patterns

Because the package separates **Context Detection**, **Rendering**, and **Reporting**, you can use it to build incredibly advanced, enterprise-grade exception workflows. 

Here are a few "exotic" ways you can use the package to supercharge your application:

### 1. The "Live-State" External API Context
The `#[WithContext]` attribute doesn't just read static variables. Because it maps to a method on your Exception class, that method can actually **perform API calls** to gather the exact state of the world at the moment of failure!

Imagine a Stripe subscription charge fails. Instead of just logging "Payment Failed", your exception can query Stripe in real-time and attach the customer's balance to the Slack log!

```php
use Exception;
use Stripe\StripeClient;
use Isaidgitmenow\LaravelErrors\Attributes\WithContext;
use Isaidgitmenow\LaravelErrors\Attributes\ReportTo;

#[ReportTo('billing-alerts')]
class StripeChargeFailedException extends Exception
{
    public function __construct(
        public string $stripeCustomerId,
        public int $attemptedAmount,
        string $message = "Charge failed"
    ) {
        parent::__construct($message);
    }

    #[WithContext]
    public function gatherStripeIntel(): array
    {
        // 🚀 Fetch real-time data from Stripe when the exception is reported!
        $stripe = new StripeClient(env('STRIPE_SECRET'));
        $customer = $stripe->customers->retrieve($this->stripeCustomerId);

        return [
            'attempted_amount' => $this->attemptedAmount,
            'customer_email'   => $customer->email,
            'account_balance'  => $customer->balance,
            'is_delinquent'    => $customer->delinquent,
            'action_required'  => 'Please contact customer to update card.',
        ];
    }
}
```
**Result:** When the exception hits the `LogReporter`, it pauses, calls `gatherStripeIntel()`, talks to Stripe, and your Slack `billing-alerts` channel receives an incredibly detailed forensic report!

### 2. The "Self-Healing" Reporter (Auto-Restarting Workers)
Reporters don't just have to send logs. A Reporter is just a class that implements `ErrorReporterInterface`. You can create a **Self-Healing Reporter** that detects specific critical exceptions and executes terminal commands to fix the server automatically!

```php
use Illuminate\Support\Facades\Artisan;
use Isaidgitmenow\LaravelErrors\Contracts\ErrorReporterInterface;
use Throwable;

class SelfHealingReporter implements ErrorReporterInterface
{
    public function shouldReport(Throwable $e): bool
    {
        // Only trigger self-healing for Database Connection timeouts
        return $e instanceof \Illuminate\Database\QueryException && 
               str_contains($e->getMessage(), 'server has gone away');
    }

    public function report(Throwable $e): bool
    {
        // 🚀 Auto-restart the queue workers to re-establish DB connections!
        Artisan::call('queue:restart');
        
        // Return true to allow the next Reporter (e.g. Slack) to also run,
        // so you get a message saying "DB died, but I restarted the queues!"
        return true; 
    }
}
```
Register this in `config/errors.php` inside the `'reporters'` array, and your app will literally fix itself before PagerDuty even rings!

### 3. Dynamic Channel Routing based on Severity
What if you want to route an exception to a different Slack channel based on how much money is involved?
You can't do this with a static `#[ReportTo('channel')]` attribute. Instead, you build a **Dynamic Routing Reporter**:

```php
use Illuminate\Support\Facades\Log;
use Isaidgitmenow\LaravelErrors\Contracts\ErrorReporterInterface;
use Throwable;

class FinancialRoutingReporter implements ErrorReporterInterface
{
    public function shouldReport(Throwable $e): bool
    {
        return $e instanceof TransactionFailedException;
    }

    public function report(Throwable $e): bool
    {
        $context = ExceptionInspector::context($e);
        
        // 🚀 Dynamic routing logic!
        if ($e->amount > 10000) {
            Log::channel('ceo-alerts')->critical("WHALE ALERT: {$e->getMessage()}", $context);
        } else {
            Log::channel('dev-team')->warning("Standard failure: {$e->getMessage()}", $context);
        }
        
        return true;
    }
}
```

### 4. Interactive Inertia "Action Modal"
Because the package uses `InertiaRenderer` configured to return props, you can use the `#[WithContext]` data not just for backend logging, but to **drive frontend UI**.

If an exception is thrown because a user's subscription expired, you can attach a checkout URL to the context. The Inertia frontend catches it and automatically shows a "Pay Now" modal!

```php
#[HttpCode(402)] // Payment Required
#[TranslatedMessage('Your subscription has expired.')]
class SubscriptionExpiredException extends Exception
{
    #[WithContext]
    public function frontendPayload(): array
    {
        return [
            // This is sanitized from backend logs but exposed to Inertia!
            'show_payment_modal' => true,
            'checkout_url' => route('billing.checkout'),
        ];
    }
}
```
In your Vue/React layout:
```vue
<script setup>
import { usePage } from '@inertiajs/vue3'
import { computed } from 'vue'

const error = computed(() => usePage().props.error)
</script>

<template>
  <PaymentModal 
    v-if="error?.status === 402 && error?.context?.show_payment_modal" 
    :url="error.context.checkout_url" 
  />
</template>
```

By viewing exceptions not as "crashes", but as **rich data objects**, this package allows you to build completely seamless, interactive, and self-healing applications!

---

## 🌍 Environment-Specific Reporting

In a typical workflow, you want full Slack/PagerDuty alerts in **production**, but you don't want to be bombarded with notifications while developing locally or running CI against a staging branch.

The `environments` parameter on `#[ReportTo]` handles this elegantly:

```php
use Isaidgitmenow\LaravelErrors\Attributes\ReportTo;

// Only fire Slack alerts in production
#[ReportTo('slack', environments: ['production'])]
class PaymentGatewayException extends \Exception {}

// Send to Slack in production AND staging, but not locally
#[ReportTo(['slack', 'pagerduty'], environments: ['production', 'staging'])]
class DatabaseReplicationLagException extends \Exception {}

// No restriction: always report (same as before)
#[ReportTo('slack')]
class AlwaysReportedException extends \Exception {}
```

### How it works

When `ExceptionInspector::reportToChannels()` is called by the `LogReporter`, it reads the `environments` list from the attribute. If the list is non-empty, it calls `app()->environment($environments)` against the current `APP_ENV`. If the environment doesn't match, it returns `null` instead of the channel list, and the `LogReporter` skips reporting entirely.

```
APP_ENV=local  → #[ReportTo('slack', environments: ['production'])] → null (suppressed)
APP_ENV=production → same attribute → ['slack'] (reported)
```

### Combining with other attributes

You can freely combine environment filtering with the rest of the attribute API:

```php
use Isaidgitmenow\LaravelErrors\Attributes\HttpCode;
use Isaidgitmenow\LaravelErrors\Attributes\RateLimit;
use Isaidgitmenow\LaravelErrors\Attributes\ReportTo;
use Isaidgitmenow\LaravelErrors\Attributes\TranslatedMessage;
use Isaidgitmenow\LaravelErrors\Attributes\WithContext;

#[HttpCode(503)]
#[TranslatedMessage('errors.third_party_down')]
#[WithContext(['provider', 'attemptCount'])]
#[RateLimit(max: 3, intervalInMinutes: 5)]
#[ReportTo(['slack', 'emergency_file'], environments: ['production'])]
class ThirdPartyApiDownException extends \Exception
{
    public function __construct(
        public string $provider,
        public int $attemptCount,
        string $message = "Third-party API is unavailable."
    ) {
        parent::__construct($message);
    }
}
```

In this example:
- All environments see the 503 response and the translated message
- Rate limiting applies everywhere
- The Slack and emergency file alerts only fire in production

---

## 🛠️ Generating Exceptions: `make:error`

Manually creating exception files and remembering which `use` imports to add is tedious. The `make:error` artisan command scaffolds a fully decorated exception class for you in seconds.

### Basic Usage

```bash
# Create a plain exception (no attributes)
php artisan make:error PaymentFailed

# Create with a custom HTTP status code
php artisan make:error PaymentFailed --http=402

# Create with HTTP code + reporting channel
php artisan make:error PaymentFailed --http=402 --report=slack

# Full: HTTP code + multi-channel + environment restriction
php artisan make:error PaymentFailed --http=402 --report=slack,emergency_file --env=production
```

The command places the generated file in `app/Exceptions/` (respecting sub-namespacing):

```bash
# Creates app/Exceptions/Payments/PaymentFailed.php
php artisan make:error Payments/PaymentFailed --http=402 --report=slack --env=production
```

### Generated Output

Running `php artisan make:error PaymentFailed --http=402 --report=slack --env=production` generates:

```php
<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Isaidgitmenow\LaravelErrors\Attributes\HttpCode;
use Isaidgitmenow\LaravelErrors\Attributes\ReportTo;

#[HttpCode(402)]
#[ReportTo('slack', environments: ['production'])]
class PaymentFailed extends Exception
{
    //
}
```

The command automatically:
- Adds only the `use` statements for the attributes you requested
- Correctly formats single vs. multi-channel `#[ReportTo]`
- Applies the `environments:` named argument when `--env` is provided

### Options Reference

| Option | Default | Description |
|---|---|---|
| `{name}` | *(required)* | Class name. Supports `/` for sub-namespacing (e.g. `Billing/CardDeclined`) |
| `--http` | `500` | HTTP status code for `#[HttpCode]`. Omitted entirely if 500 (the default). |
| `--report` | *(none)* | Comma-separated channel name(s). Adds `#[ReportTo]`. Omitted if not specified. |
| `--env` | *(none)* | Comma-separated environment name(s). Adds the `environments:` argument to `#[ReportTo]`. Requires `--report`. |

### Custom Stubs

If you want to customize the generated file template, publish the stub to your application:

```bash
# Copy the stub to your app's stubs/ directory
cp vendor/isaidgitmenow/laravel-errors/stubs/error.stub stubs/error.stub
```

The command automatically detects `stubs/error.stub` in your project root and uses it instead of the package default.

---

## 🔬 Static Analysis with Larastan

The package ships with a pre-configured `phpstan.neon.dist` file so you can run type-safe analysis on your error-handling code with zero setup.

### Running Analysis

```bash
# Via the Composer script:
composer phpstan

# Or directly:
vendor/bin/phpstan analyse --memory-limit=512M
```

The configuration targets `src/` at **PHPStan level 5** with the Larastan extension loaded for Laravel-aware type inference. It pre-suppresses errors from optional dependencies (`Debugbar`, `Filament`) that may not be installed in your dev environment.

### Installing Larastan in Your Application

If you want to run Larastan across your own application code (not just this package), install it separately:

```bash
composer require larastan/larastan --dev
```

Then create a `phpstan.neon` in your application root:

```neon
includes:
    - vendor/larastan/larastan/extension.neon

parameters:
    level: 5
    paths:
        - app
        - src
```

---

## 🤖 Continuous Integration (GitHub Actions)

The package ships with a ready-to-use GitHub Actions workflow at `.github/workflows/run-tests.yml`. It covers:

| PHP | Laravel | What runs |
|---|---|---|
| 8.4 | ^11.0 | `pest --ci`, `phpstan analyse` |
| 8.4 | ^12.0 | `pest --ci`, `phpstan analyse` |
| 8.5 | ^11.0 | `pest --ci`, `phpstan analyse` |
| 8.5 | ^12.0 | `pest --ci`, `phpstan analyse` |

The workflow runs automatically on every push and pull request to `main`/`master`. The `Tests` badge at the top of this README reflects the latest CI run.

### Using the Workflow in Your Fork / Package

The workflow is already committed at `.github/workflows/run-tests.yml`. If you fork the package or base a new package on it, push to GitHub and CI will start automatically — no configuration needed.

If you want to add CI to your own **application** that uses this package, the simplest setup is:

```yaml
# .github/workflows/tests.yml
name: Tests
on: [push, pull_request]
jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
      - run: composer install --no-interaction
      - run: vendor/bin/pest --ci
```

---

## 🏗️ Domain Driven Design (DDD) Support

If your application uses a modular architecture via the [tey/laravel-ddd](https://github.com/jaspertey/laravel-ddd) package, you can generate decorated exceptions directly inside your Domain modules instead of the standard `app/Exceptions` folder.

The package provides a dedicated `ddd:error` Artisan command that understands your DDD architecture and respects your `config/ddd.php` paths.

### Basic Usage

You can use the shorthand DDD syntax (`Domain:Class`) to specify where the exception belongs:

```bash
php artisan ddd:error Invoicing:PaymentFailed
```
This will automatically create `src/Domain/Invoicing/Exceptions/PaymentFailed.php` (or wherever your `domain_path` is configured).

### Using the `--domain` Option

If you prefer, you can pass the domain as an explicit option instead of using the shorthand syntax:

```bash
php artisan ddd:error PaymentFailed --domain=Invoicing
```

### Full Attribute Support

Just like the standard `make:error` command, `ddd:error` supports all dynamic attributes to scaffold your exception in one line:

```bash
php artisan ddd:error Invoicing:PaymentFailed --http=402 --report=slack,pagerduty --env=production
```

This will generate a fully decorated domain exception:

```php
<?php

declare(strict_types=1);

namespace Domain\Invoicing\Exceptions;

use Exception;
use Isaidgitmenow\LaravelErrors\Attributes\HttpCode;
use Isaidgitmenow\LaravelErrors\Attributes\ReportTo;

#[HttpCode(402)]
#[ReportTo(['slack', 'pagerduty'], environments: ['production'])]
class PaymentFailed extends Exception
{
    //
}
```

> **Note:** The `ddd:error` command requires the `tey/laravel-ddd` package to be installed in your project. If it is missing, the command will gracefully halt and prompt you to install it.
