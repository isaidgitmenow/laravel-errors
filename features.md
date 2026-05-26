# V2 Architecture & DX Upgrades

This plan outlines the implementation of several high-value features designed to make the `laravel-errors` package enterprise-ready, heavily optimized for modern stacks (like Octane), and exceptionally developer-friendly.

## User Review Required

> [!IMPORTANT]
> We will use Pest.

## Proposed Changes

---

### 1. Octane Compatibility (Memory Leak Prevention)
Swoole/RoadRunner keep the application loaded in memory. Static caches will grow indefinitely.

#### [MODIFY] [ErrorsServiceProvider.php](file:///d:/xampp/htdocs/packages/errors/src/ErrorsServiceProvider.php)
- Inject the `Illuminate\Contracts\Events\Dispatcher` into the `boot()` method.
- Add an event listener for `\Laravel\Octane\Events\RequestTerminated::class`.
- When triggered, call `\Isaidgitmenow\LaravelErrors\ExceptionInspector::flushCache()` to clear the reflection cache safely after every request.

---

### 2. Extensibility: Dynamic Pass-Through
Allow third-party packages to register their own exceptions that should bypass our pipeline, without forcing the user to modify `config/errors.php`.

#### [MODIFY] [ErrorManager.php](file:///d:/xampp/htdocs/packages/errors/src/ErrorManager.php)
- Add a static property: `private static array $dynamicPassThrough = [];`
- Add a static method: `public static function passThrough(string $exceptionClass): void`
- Update `isPassThrough(Throwable $e)` to merge `$this->config['pass_through']` with `self::$dynamicPassThrough`.

---

### 3. Environment-Specific Reporting
Allow developers to restrict `#[ReportTo]` to specific environments (e.g., only send to Slack in production).

#### [MODIFY] [ReportTo.php](file:///d:/xampp/htdocs/packages/errors/src/Attributes/ReportTo.php)
- Add a new readonly property: `public readonly array $environments = []` to the constructor.

#### [MODIFY] [ExceptionInspector.php](file:///d:/xampp/htdocs/packages/errors/src/ExceptionInspector.php)
- Update `reportToChannels()` to return an array of channels OR `null`.
- If the attribute has `$environments` defined, check if `app()->environment()` is in the array. If not, return `null` (suppress reporting).

---

### 4. Developer Experience: `make:error` Command
Create an artisan command to rapidly generate decorated exceptions.

#### [NEW] `src/Console/Commands/MakeExceptionCommand.php`
- Add command signature: `make:error {name} {--http=500} {--report=}`
- Add logic to generate a class stub using Laravel's file system, automatically injecting `#[HttpCode]` and `#[ReportTo]` based on options.

#### [NEW] `stubs/error.stub`
- Create the raw PHP stub template for the generated exception.

#### [MODIFY] [ErrorsServiceProvider.php](file:///d:/xampp/htdocs/packages/errors/src/ErrorsServiceProvider.php)
- Register the `MakeExceptionCommand` in the `configurePackage` method using `$package->hasCommand()`.

---

### 5. Automated Testing (Pest PHP)
Utilize the existing Pest PHP setup to write comprehensive tests.

#### [NEW] `tests/Feature/ErrorManagerTest.php`
- Test that Context Detectors are evaluated in order.
- Test that `pass_through` exceptions yield correctly.
- Test that `RateLimitedReporter` caches correctly.
---

### 6. Facade Support (`LaravelErrors::passThrough()`)
Improve DX by exposing the new dynamic pass-through method on the existing Facade.

#### [MODIFY] `src/Facades/LaravelErrors.php`
- Add `@method static void passThrough(string $exceptionClass)` to the class docblock.

---

### 7. Static Analysis (Larastan)
Ensure bulletproof code quality by scanning the package for edge cases and type mismatch issues.

#### [NEW] `phpstan.neon.dist`
- Create the configuration file for PHPStan targeting max level (level 9 if possible, or at least level 5).

#### [MODIFY] `composer.json`
- Add `"nunomaduro/larastan": "^2.0"` to `require-dev`.
- Add `"phpstan": "vendor/bin/phpstan analyse"` to the `scripts` section.

---

### 8. Continuous Integration (GitHub Actions)
Guarantee that the package works across all modern PHP versions and Laravel 11.

#### [NEW] `.github/workflows/run-tests.yml`
- Set up a matrix for PHP 8.2 and 8.3.
- Run `composer install`, then run `vendor/bin/pest`.
- Run `vendor/bin/phpstan analyse`.

## Verification Plan

### Automated Tests
I will run `vendor/bin/pest` to ensure all components (Detectors, Renderers, Reporters, and the Pipeline) function correctly.
I will run `composer run phpstan` to verify static analysis passes.

### Manual Verification
1. I will run `php artisan make:error PaymentFailed --http=402 --report=slack` to ensure the generated file looks perfect.
2. I will manually review the `Octane` integration code.
