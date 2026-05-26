<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Isaidgitmenow\LaravelErrors\Detectors\ApiDetector;
use Isaidgitmenow\LaravelErrors\Detectors\FilamentDetector;
use Isaidgitmenow\LaravelErrors\Detectors\InertiaDetector;
use Isaidgitmenow\LaravelErrors\Detectors\LivewireDetector;
use Isaidgitmenow\LaravelErrors\Detectors\WebDetector;
use Isaidgitmenow\LaravelErrors\Renderers\ApiRenderer;
use Isaidgitmenow\LaravelErrors\Renderers\FilamentRenderer;
use Isaidgitmenow\LaravelErrors\Renderers\InertiaRenderer;
use Isaidgitmenow\LaravelErrors\Renderers\LivewireRenderer;
use Isaidgitmenow\LaravelErrors\Renderers\WebRenderer;
use Isaidgitmenow\LaravelErrors\Reporters\DebugbarReporter;
use Isaidgitmenow\LaravelErrors\Reporters\LogReporter;

return [

    /*
    |--------------------------------------------------------------------------
    | Debug Mode Behavior
    |--------------------------------------------------------------------------
    | When APP_DEBUG=true, Spatie Ignition provides an excellent visual
    | debugger. Setting this to true will let Ignition take over for
    | Web and API contexts, while still running our custom renderers
    | for Livewire, Inertia, and Filament.
    */
    'respect_debug_mode' => true,

    /*
    |--------------------------------------------------------------------------
    | Context Pipeline (Priority Order)
    |--------------------------------------------------------------------------
    | Detectors are evaluated top-to-bottom. The first one that returns true
    | wins. Its paired Renderer is called to build the HTTP response.
    |
    | You can add, remove, or reorder entries to extend the package for new
    | frameworks or custom contexts. Values must implement the corresponding
    | interfaces.
    */
    'contexts' => [
        FilamentDetector::class => FilamentRenderer::class,
        LivewireDetector::class => LivewireRenderer::class,
        InertiaDetector::class  => InertiaRenderer::class,
        ApiDetector::class      => ApiRenderer::class,
        WebDetector::class      => WebRenderer::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Exception Pass-Through (Never Intercept)
    |--------------------------------------------------------------------------
    | These exception classes will always be handled by Laravel's default
    | handler. This ensures that ValidationException (form errors, 422),
    | AuthenticationException (login redirect, 401), etc. are never altered.
    */
    'pass_through' => [
        ValidationException::class,
        AuthenticationException::class,
        AuthorizationException::class,
        HttpException::class,
        ModelNotFoundException::class,
        TokenMismatchException::class,
        HttpResponseException::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Reporters Pipeline
    |--------------------------------------------------------------------------
    | Reporters are called in order for every exception that is NOT suppressed
    | by #[DontReport]. To disable a reporter, remove it from the list.
    | You can add custom reporters that implement ErrorReporterInterface.
    */
    'reporters' => [
        DebugbarReporter::class,
        LogReporter::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Sensitive Data Sanitization
    |--------------------------------------------------------------------------
    | These keys will be automatically redacted from request data, context,
    | and any data sent to external reporters.
    */
    'sanitize' => [
        'password',
        'password_confirmation',
        'current_password',
        'api_key',
        'api_token',
        'token',
        'secret',
        'authorization',
        'credit_card',
        'card_number',
        'cvv',
    ],

    /*
    |--------------------------------------------------------------------------
    | JSON Response Formatter
    |--------------------------------------------------------------------------
    | Define how API errors are formatted. Receives the Throwable and Request.
    | Defaults to a Laravel-style response structure.
    |
    | The Closure must return an array that will be JSON-encoded.
    |
    | Example:
    | 'json_formatter' => function (\Throwable $e, \Illuminate\Http\Request $request): array {
    |     return ['error' => ['message' => $e->getMessage(), 'code' => $e->getCode()]];
    | },
    */
    'json_formatter' => null, // null = default Laravel-style {message, errors}

    /*
    |--------------------------------------------------------------------------
    | Livewire Response Handler
    |--------------------------------------------------------------------------
    | Define how errors are surfaced to the user in Livewire components.
    | Receives the Throwable, Request, and the current Livewire component instance.
    |
    | Example (using Wire UI Toasts):
    | 'livewire_handler' => function (\Throwable $e, \Illuminate\Http\Request $request): void {
    |     session()->flash('error', $e->getMessage());
    | },
    */
    'livewire_handler' => null,

    /*
    |--------------------------------------------------------------------------
    | Inertia Response Mode
    |--------------------------------------------------------------------------
    | 'props'    - Return errors as shared props (default, integrates with
    |              Inertia's built-in error handling).
    | 'redirect' - Redirect to a dedicated error page using Inertia::render().
    |
    | When 'redirect' is used, set 'inertia_error_component' to the name
    | of your error page component (e.g., 'ErrorPage', 'Error/Index').
    */
    'inertia_mode' => 'props', // 'props' or 'redirect'

    'inertia_error_component' => 'ErrorPage',

    /*
    |--------------------------------------------------------------------------
    | Filament Response Handler
    |--------------------------------------------------------------------------
    | When null, the package uses Filament's native Notification system.
    | You may provide a custom Closure for full control.
    |
    | Example:
    | 'filament_handler' => function (\Throwable $e, \Illuminate\Http\Request $request): void {
    |     \Filament\Notifications\Notification::make()
    |         ->title($e->getMessage())
    |         ->danger()
    |         ->send();
    | },
    */
    'filament_handler' => null, // null = use native Filament Notification

];
