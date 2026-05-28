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


## 📚 Documentation

For detailed usage and advanced configuration, please refer to the specific documentation chapters:

### [Core Architecture And Api](docs/01-core-architecture-and-api.md)
- 🏗️ Core Architecture & API Reference
- 🕵️‍♂️ How Does It Know the Request Context?
- 🚀 The Complete Lifecycle: Generating, Throwing & Logging

### [Usage And Attributes](docs/02-usage-and-attributes.md)
- 📖 Usage: The Attributes API
- 💡 The Ultimate Exception Example
- 🤔 Do I Still Need `try/catch`?

### [Renderers](docs/03-renderers.md)
- 🎨 Context Detectors & Renderers
- 🌐 Web Renderer Example
- 📡 API Renderer Example
- ⚡ Livewire Renderer Example
- 🛡️ Filament Renderer Example
- ⚛️ Inertia.js Renderer Example

### [Reporters And Integrations](docs/04-reporters-and-integrations.md)
- 📢 Reporters
- 💬 Slack Integration Example
- 🦉 NightWatch Integration Example
- 💥 Flare (or Sentry) Integration
- 🐛 Laravel Debugbar Integration Example
- 🔀 Routing to Different Log Channels
- 🌍 Environment-Specific Reporting

### [Exception Handling Mechanics](docs/05-exception-handling-mechanics.md)
- 🚧 Bypassing the Pipeline: Native Laravel Exceptions
- 🚦 The "Pass Through" Exceptions (Complete List)
- 🤷‍♂️ Handling Generic (Un-decorated) Exceptions
- 📝 A Note on API Form Requests (`ValidationException`)
- 🛡️ Working with Gates & Permissions (`AuthorizationException`)
- 🌍 Translated Error Messages Example

### [Advanced Configuration And Patterns](docs/06-advanced-configuration-and-patterns.md)
- ⚙️ Advanced Configuration & Mechanics
- 🧩 Custom Detectors, Renderers, and Reporters
- 🏗️ Building a Custom Detector (Step-by-Step)
- 🛑 Anti-Spam: Error Rate Limiting (In-Depth)
- ⚡ Performance & Error Cache (Octane / Testing)
- 🤯 Exotic Use Cases & Advanced Patterns
- 🌐 Advanced API: Complying with JSON:API Specification

### [Ecosystem And Commands](docs/07-ecosystem-and-commands.md)
- ⚙️ Queue Jobs Integration
- 💻 Artisan Commands Integration
- 🛠️ Generating Exceptions: `make:error`
- 🏗️ Domain Driven Design (DDD) Support

### [Testing And Ci](docs/08-testing-and-ci.md)
- 🧪 Testing Your Application
- 🔬 Static Analysis with Larastan
- 🤖 Continuous Integration (GitHub Actions)


---
## 🧪 Testing

The package includes a comprehensive test suite (built with Pest and Orchestra Testbench).

```bash
composer test
```

Run static analysis with Larastan:

```bash
composer phpstan
```


---
## 📜 License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

---
