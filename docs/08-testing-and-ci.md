# Testing And Ci

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


---
