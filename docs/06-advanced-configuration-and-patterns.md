# Advanced Configuration And Patterns

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


---
