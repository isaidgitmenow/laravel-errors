# Usage And Attributes

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

See [Environment-Specific Reporting](04-reporters-and-integrations.md#environment-specific-reporting) for a full walkthrough.

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


---

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


---
