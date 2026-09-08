<?php
// file: config/errors.php  — stare finală 3.0

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
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
use Isaidgitmenow\LaravelErrors\Reporters\XdebugReporter;

return [

    /*
    |--------------------------------------------------------------------------
    | ⚠️  Config Caching Compatibility
    |--------------------------------------------------------------------------
    | Closures CANNOT be serialized by `php artisan config:cache`.
    | Use invokable class-strings instead:
    |   'json_formatter' => \App\ErrorFormatters\ApiFormatter::class,
    */

    /*
    |--------------------------------------------------------------------------
    | Integrate with Laravel's Handler
    |--------------------------------------------------------------------------
    | Controls which Handler hooks ErrorHandler::handle() activates.
    | Granular flags allow you to enable only the features you need.
    |
    | These flags affect BOOT-TIME registration. After changing them you must:
    |   1. Clear cache:  php artisan errors:clear && php artisan config:clear
    |   2. Rebuild cache: php artisan optimize
    |
    | PRODUCTION: bootstrap/cache/errors.php is REQUIRED when any flag is true.
    | Run `php artisan errors:cache` during deploy (or `optimize`).
    */
    'integrate_with_laravel' => [
        'map_http_code'  => false,    // map() per class: translate attributes to HttpExceptionInterface
        'dont_report'    => false,    // dontReport() native for #[DontReport] classes
        'json_decision'  => false,    // shouldRenderJsonWhen() via HandlerSlots + api_prefixes
    ],

    /*
    |--------------------------------------------------------------------------
    | Debug Mode Behavior
    |--------------------------------------------------------------------------
    | When true, Ignition takes over for non-interactive contexts while our
    | renderers still handle Livewire, Inertia, and Filament.
    */
    'respect_debug_mode' => true,

    /*
    |--------------------------------------------------------------------------
    | Error ID
    |--------------------------------------------------------------------------
    | ULID injected into responses, logs, and context. Useful for support
    | tickets: "Please share the error_id shown on screen."
    */
    'error_id' => [
        'enabled'     => true,
        'header'      => 'X-Error-Id',
        'payload_key' => 'error_id',
        'in_message'  => true,     // append (ref: ULID) to the generic fallback message
    ],

    /*
    |--------------------------------------------------------------------------
    | Problem Details (RFC 9457)
    |--------------------------------------------------------------------------
    */
    'problem_details' => [
        'enabled'           => false,
        'type_base_url'     => null,     // null → config('app.url') . '/errors'
        'legacy_message'    => true,     // keep `message` key alongside RFC fields
        'expose_context'    => false,    // include sanitized #[WithContext] data
        'unify_validation'  => false,    // render 422 ValidationException as Problem Details
    ],

    /*
    |--------------------------------------------------------------------------
    | Message Exposure
    |--------------------------------------------------------------------------
    | 'attributed': raw message only for #[HttpCode] / #[TranslatedMessage] / HttpExceptionInterface
    | 'always':     raw exception getMessage() (NOT recommended in production)
    | 'never':      always generic fallback
    */
    'expose_messages' => 'attributed',

    /*
    |--------------------------------------------------------------------------
    | Fallback Message Prefix
    |--------------------------------------------------------------------------
    | Translation key prefix for generic error messages. The package looks up
    | `{prefix}.{status_code}` first, then falls back to Symfony status texts.
    */
    'fallback_message_prefix' => 'errors.http',

    /*
    |--------------------------------------------------------------------------
    | Default HTTP Status
    |--------------------------------------------------------------------------
    | Status code when no #[HttpCode] and no HttpExceptionInterface is found.
    */
    'default_status' => 500,

    /*
    |--------------------------------------------------------------------------
    | getCode() as HTTP Status
    |--------------------------------------------------------------------------
    | When true, $e->getCode() in the 400-599 range is used as the HTTP status.
    | This is opt-in because many SDKs store vendor-specific codes there.
    */
    'http_code_from_exception_code' => false,

    /*
    |--------------------------------------------------------------------------
    | API Route Prefixes
    |--------------------------------------------------------------------------
    | URL prefixes that are always treated as JSON APIs, even when the client
    | doesn't send Accept: application/json.
    */
    'api_prefixes' => ['api'],

    /*
    |--------------------------------------------------------------------------
    | Native Throttle
    |--------------------------------------------------------------------------
    | When true (production only), #[RateLimit] is also registered via
    | Handler::throttle() for framework-level suppression.
    */
    'native_throttle' => true,

    /*
    |--------------------------------------------------------------------------
    | Xdebug IDE Enrichment
    |--------------------------------------------------------------------------
    */
    'enrich_xdebug' => true,

    /*
    |--------------------------------------------------------------------------
    | Livewire Mode
    |--------------------------------------------------------------------------
    | 'hook': ComponentHook intercepts action exceptions (recommended)
    | 'json': Livewire context detected → JSON response (legacy)
    */
    'livewire_mode' => 'hook',

    /*
    |--------------------------------------------------------------------------
    | Context Pipeline (Priority Order)
    |--------------------------------------------------------------------------
    | ApiDetector FIRST: a JSON-expecting request is API regardless of context.
    */
    'contexts' => [
        ApiDetector::class      => ApiRenderer::class,      // primul: cine cere JSON e API oriunde
        LivewireDetector::class => LivewireRenderer::class,
        FilamentDetector::class => FilamentRenderer::class,  // inversează cu Livewire dacă vrei Notification pe fallback
        InertiaDetector::class  => InertiaRenderer::class,
        WebDetector::class      => WebRenderer::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Exception Pass-Through
    |--------------------------------------------------------------------------
    | Bypasses package reporters AND renderers. Decided on the thrown exception (F-06).
    | Our wrapper does NOT extend Symfony\HttpException, so it won't be caught.
    */
    'pass_through' => [
        ValidationException::class,
        AuthenticationException::class,
        AuthorizationException::class,
        \Symfony\Component\HttpKernel\Exception\HttpException::class,   // N-02: wrapper-ul nostru NU e HttpException
        ModelNotFoundException::class,
        TokenMismatchException::class,
        HttpResponseException::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Chain Wrapper Pass-Through
    |--------------------------------------------------------------------------
    | Framework exceptions that wrap user exceptions. When the OUTER exception
    | is NOT pass-through but the INNER (root cause) IS, the pass-through
    | decision is inherited only for these known wrapper classes.
    */
    'pass_through_chain_wrappers' => [
        \Illuminate\View\ViewException::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Reporters Pipeline
    |--------------------------------------------------------------------------
    | LogReporter is removed from defaults in 2.0: Laravel's native logger
    | (enriched via context()) handles the logging. Use LogReporter only
    | when you need #[ReportTo] channel-specific logging.
    */
    'reporters' => [
        XdebugReporter::class,
        DebugbarReporter::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Sensitive Data Sanitization
    |--------------------------------------------------------------------------
    | Matched on substring — avoid short tokens: 'pan' would redact company_id, participant, etc.
    */
    'sanitize' => [
        'password', 'password_confirmation', 'current_password',
        'api_key', 'api_token', 'token', 'secret',
        'authorization', 'credit_card', 'card_number', 'cvv', 'iban',
    ],

    /*
    |--------------------------------------------------------------------------
    | Attribute Scan Paths
    |--------------------------------------------------------------------------
    | Glob patterns for directories containing exception classes.
    | The scanner uses PhpToken to find classes efficiently.
    | AttributeScanner::resolvePaths() also adds DDD domain paths at runtime.
    */
    'scan_paths' => [
        app_path('Exceptions'),
        base_path('src/Domain') . '/*/Exceptions',
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Formatters (Closures or invokable class-strings)
    |--------------------------------------------------------------------------
    */
    'json_formatter'   => null,
    'livewire_handler' => null,
    'filament_handler' => null,
    'metrics'          => null,   // Closure | class-string invokable | null — receives ExceptionReported (MetricsListener)

    /*
    |--------------------------------------------------------------------------
    | Web Renderer
    |--------------------------------------------------------------------------
    */
    'web_renderer' => ['prefer_laravel_views' => true],   // errors::{status} of Laravel before package view

    /*
    |--------------------------------------------------------------------------
    | Inertia
    |--------------------------------------------------------------------------
    */
    'inertia_mode'            => 'page',    // page | flash | respond  ('redirect' alias for page; 'props' throws at boot)
    'inertia_error_component' => 'ErrorPage',

    /*
    |--------------------------------------------------------------------------
    | MCP Server
    |--------------------------------------------------------------------------
    */
    'mcp' => [
        'max_log_lines'   => 200,
        'log_file_mode'   => 0664,
        'log_dir_mode'    => 0775,
        'simulate_namespaces' => ['', 'Illuminate\\', 'Symfony\\Component\\HttpKernel\\Exception\\'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit (3.0)
    |--------------------------------------------------------------------------
    */
    'audit' => [
        'enabled' => false,
        'sink'    => 'database',
        'table'   => 'error_audit',
        'log_channel' => 'audit',
        'sinks'   => [],    // ['database', 'log_channel']
    ],

];
