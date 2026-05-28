# Ecosystem And Commands

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


---
