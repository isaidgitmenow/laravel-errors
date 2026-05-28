# Xdebug Enrichment Plan (Analyzed for Utility)

## Feature Analysis
This feature is designed to be **highly practical, silent, and strictly local**. 
- **Exact:** It leverages a native Xdebug 3 feature (`xdebug_notify`) to push data directly to the IDE's debugger. It requires absolutely no browser interaction or UI modifications.
- **Useful:** When a developer catches a complex error, they instantly get an IDE popup with the exact `#[WithContext]` payload, pre-sanitized, so they don't have to scroll through log files or use `dd()`.
- **Zero-Friction:** By bypassing rate limiting (`BypassesRateLimiting`), developers won't get confused when they hit refresh 5 times and the IDE stops showing the popup (which would happen if the logger throttled it).
- **Clean:** We will **not** add an `env()` variable for this to avoid cluttering `.env` files. It will just be a config key `enrich_xdebug => true` that developers can toggle if they publish the config.

## Proposed Changes

### 1. Configuration (No .env clutter)
#### [MODIFY] `config/errors.php`
- Add ` 'enrich_xdebug' => true,` 
- Register `Isaidgitmenow\LaravelErrors\Reporters\XdebugReporter::class` in the `reporters` array.

### 2. Create the Reporter (Strictly Scoped)
#### [NEW] `src/Reporters/XdebugReporter.php`
- Implements `ErrorReporterInterface` and `BypassesRateLimiting`.
- **`shouldReport(Throwable $e)`**:
  - `config('app.debug') === true`
  - `$this->config['enrich_xdebug'] ?? true === true`
  - `function_exists('xdebug_notify')`
  - Context is not empty.
- **`report(Throwable $e)`**: 
  - Retrieves context, sanitizes it (hiding passwords), and calls `xdebug_notify(['LaravelErrors Context for ' . class_basename($e) => $sanitizedContext])`.

### 3. Service Provider Registration (Dependency Injection)
#### [MODIFY] `src/ErrorsServiceProvider.php`
- Bind `XdebugReporter::class` in `packageRegistered()` passing `$app['config']->get('errors', [])` so it can access the `sanitize` list.

### 4. Bypass Rate Limiting cleanly (SOLID)
#### [NEW] `src/Contracts/BypassesRateLimiting.php`
- Marker interface.
#### [MODIFY] `src/ErrorManager.php`
- `wrapWithRateLimit()` skips wrapping if `$reporter instanceof BypassesRateLimiting`.

### 5. Tests
#### [NEW] `tests/Feature/XdebugReporterTest.php`
- Full coverage using a namespace-mocked `xdebug_notify()` function.
