# Reporters And Integrations

## 📢 Reporters

Reporters determine how errors are logged or sent to external trackers (like Sentry or Flare). The pipeline loops through these sequentially.

- **`LogReporter`**:
  Uses Laravel's core `Log` facade. It respects the `#[ReportTo]` attribute to route the error to specific channels (e.g., slack, single, daily). If not specified, it falls back to the default logger.
- **`DebugbarReporter`**:
  Integrates with `barryvdh/laravel-debugbar`. It forces exceptions marked with `#[DontReport]` to still appear in the Debugbar's 'Exceptions' tab locally. It also dumps the sanitized `#[WithContext]` data directly into the 'Messages' tab.
- **`XdebugReporter`**:
  A silent, purely local reporter that pushes `#[WithContext]` data directly to your IDE via `xdebug_notify()`. It bypasses rate limiting, ensuring you see the payload every time you refresh during debugging.
- **`RateLimitedReporter`**:
  A dynamic proxy wrapper. The `ErrorManager` automatically wraps any reporter with this class if the exception carries the `#[RateLimit]` attribute. It uses Laravel's `RateLimiter` facade to suppress duplicate logs within the specified interval, saving API quota for Sentry/Flare.

---


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


---

## 🐞 Xdebug IDE Enrichment

If you use Xdebug 3 for local development, you can leverage the `XdebugReporter` to push `#[WithContext]` payloads directly into your IDE's debugging UI.

### How it works

When an exception with `#[WithContext]` is thrown during a local request (`APP_DEBUG=true`), the package calls the native `xdebug_notify()` function. This sends a custom notification straight to your IDE (like PhpStorm or VS Code) containing the fully sanitized context.

Unlike logs or the Debugbar, this requires **zero browser interaction**. The moment the exception occurs, a popup or debug variable will appear in your IDE with the exact state of the failure!

### Configuration

It is enabled by default. To disable it, simply set the `enrich_xdebug` key to `false` in your published `config/errors.php` file:

```php
// config/errors.php
'enrich_xdebug' => false,
```

*(Note: The Xdebug reporter automatically bypasses rate limiting, so you won't lose notifications if you repeatedly refresh a failing page while debugging.)*

---


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


---
