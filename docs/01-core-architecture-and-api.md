# Core Architecture And Api

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
  2. Iterates through the ordered list of Context Detectors to find the first match.
  3. Intelligently yields to **Ignition** in local debug mode — but only for non-interactive contexts. Detectors that implement the `InteractiveContextDetector` marker interface (e.g., `LivewireDetector`, `InertiaDetector`) always keep control so partial renders are not broken.
  4. Executes the matched Renderer to produce the HTTP response.
- **`addContext(string $detector, string $renderer): static`**
  Allows third-party packages to dynamically prepend custom Detector/Renderer pairs to the pipeline.
- **`addReporter(string $reporter): static`**
  Allows third-party packages to dynamically prepend custom Reporters to the pipeline.
- **`hasReporters(): bool`**
  Returns `true` if any reporters are configured (via config or dynamically via `addReporter()`). Used internally by `ErrorHandler` to decide if the fallback logger is needed — this ensures dynamically added reporters are correctly accounted for.
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


---
