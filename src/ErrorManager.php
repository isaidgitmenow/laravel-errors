<?php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Isaidgitmenow\LaravelErrors\Contracts\ContextDetectorInterface;
use Isaidgitmenow\LaravelErrors\Contracts\ErrorReporterInterface;
use Isaidgitmenow\LaravelErrors\Contracts\ExceptionRendererInterface;
use Isaidgitmenow\LaravelErrors\Contracts\BypassesRateLimiting;
use Isaidgitmenow\LaravelErrors\Contracts\ReportsIgnoredExceptions;
use Isaidgitmenow\LaravelErrors\Reporters\RateLimitedReporter;
use Isaidgitmenow\LaravelErrors\Support\DataSanitizer;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The central orchestrator for the error handling pipeline.
 *
 * Responsibilities:
 * 1. Run reporters for exception logging / external tracking.
 * 2. Detect the current request context (Filament, Livewire, Inertia, API, Web).
 * 3. Delegate rendering to the appropriate renderer.
 * 4. Self-heal: fall back to Laravel's default if our own code fails.
 */
final class ErrorManager
{
    /**
     * Dynamically registered pass-through exceptions.
     * Third-party packages call ErrorManager::passThrough(MyException::class) to register here.
     *
     * @var array<class-string<\Throwable>>
     */
    private static array $dynamicPassThrough = [];

    /**
     * Dynamically registered contexts (e.g. from third-party packages).
     * These are prepended before the config-defined contexts.
     *
     * @var array<class-string<ContextDetectorInterface>, class-string<ExceptionRendererInterface>>
     */
    private array $customContexts = [];

    /**
     * Dynamically registered reporters.
     *
     * @var class-string<ErrorReporterInterface>[]
     */
    private array $customReporters = [];

    public function __construct(
        private readonly array $config,
    ) {}

    /**
     * Report the exception through the configured reporters pipeline.
     */
    public function report(Throwable $e): void
    {
        try {
            $shouldNotReport = ExceptionInspector::shouldNotReport($e);

            if (!$shouldNotReport) {
                // FIX #1: Inject #[WithContext] data into Laravel's global Context.
                // This enriches any downstream reporter (Sentry, Flare, etc.) automatically.
                $this->injectLaravelContext($e);
            }

            foreach ($this->getReporters() as $reporterClass) {
                $baseReporter = app($reporterClass);

                if (!$baseReporter instanceof ErrorReporterInterface) {
                    continue;
                }

                // If the exception shouldn't be reported, skip reporters unless they explicitly opt-in
                if ($shouldNotReport && !$baseReporter instanceof ReportsIgnoredExceptions) {
                    continue;
                }

                // FIX #3: Automatically wrap each reporter with RateLimitedReporter
                // if the exception carries the #[RateLimit] attribute.
                $reporter = $this->wrapWithRateLimit($baseReporter, $e);

                if (!$reporter->shouldReport($e)) {
                    continue;
                }

                $result = $reporter->report($e);

                // false = stop the pipeline
                if ($result === false) {
                    break;
                }
            }
        } catch (Throwable) {
            // Self-heal: never let our reporter crash the application
        }
    }

    /**
     * Render the exception into an HTTP response by running the context pipeline.
     *
     * Returns null to fall through to Laravel's default exception handler.
     */
    public function render(Throwable $e, Request $request): ?Response
    {
        try {
            // Check if this exception should pass through to Laravel's default handler
            if ($this->isPassThrough($e)) {
                return null;
            }

            // In debug mode, yield control back to Ignition for Web/API contexts
            if ($this->shouldYieldToIgnition($e, $request)) {
                return null;
            }

            foreach ($this->getContexts() as $detectorClass => $rendererClass) {
                $detector = app($detectorClass);

                if (!$detector instanceof ContextDetectorInterface) {
                    continue;
                }

                if (!$detector->detect($e, $request)) {
                    continue;
                }

                $renderer = app($rendererClass);

                if (!$renderer instanceof ExceptionRendererInterface) {
                    continue;
                }

                return $renderer->render($e, $request);
            }
        } catch (Throwable) {
            // Self-heal: never let our renderer cause a WSOD
        }

        return null;
    }

    /**
     * Prepend a custom context (detector => renderer) to the pipeline.
     * Used by third-party packages to extend without modifying config.
     *
     * @param class-string<ContextDetectorInterface>  $detector
     * @param class-string<ExceptionRendererInterface> $renderer
     */
    public function addContext(string $detector, string $renderer): static
    {
        $this->customContexts[$detector] = $renderer;

        return $this;
    }

    /**
     * Prepend a custom reporter to the pipeline.
     *
     * @param class-string<ErrorReporterInterface> $reporter
     */
    public function addReporter(string $reporter): static
    {
        $this->customReporters[] = $reporter;

        return $this;
    }

    /**
     * Dynamically register an exception class to bypass the pipeline entirely.
     * Designed for use by third-party packages so users don't need to edit config.
     *
     * @param class-string<\Throwable> $exceptionClass
     */
    public static function passThrough(string $exceptionClass): void
    {
        self::$dynamicPassThrough[] = $exceptionClass;
    }

    /**
     * Flush all dynamically registered pass-through exceptions.
     * Called by the service provider on Octane RequestTerminated to prevent
     * unbounded memory growth in long-running processes.
     */
    public static function flushPassThrough(): void
    {
        self::$dynamicPassThrough = [];
    }

    /**
     * Determine if this exception should bypass our pipeline entirely.
     */
    private function isPassThrough(Throwable $e): bool
    {
        $origin = ExceptionInspector::origin($e);

        $classList = array_merge($this->config['pass_through'] ?? [], self::$dynamicPassThrough);

        foreach ($classList as $class) {
            if ($origin instanceof $class || $e instanceof $class) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if Ignition should take over (debug mode + non-interactive context).
     */
    private function shouldYieldToIgnition(Throwable $e, Request $request): bool
    {
        if (!($this->config['respect_debug_mode'] ?? true)) {
            return false;
        }

        if (!app()->hasDebugModeEnabled()) {
            return false;
        }

        // In debug mode, yield only for standard Web and API contexts,
        // NOT for Filament/Livewire/Inertia where partial renders matter.
        return !$request->ajax()
            && !$request->wantsJson()
            && !$this->isInteractiveContext($request);
    }

    /**
     * Determine if the request is from an interactive SPA/component context.
     */
    private function isInteractiveContext(Request $request): bool
    {
        // Livewire
        if ($request->hasHeader('X-Livewire')) {
            return true;
        }

        // Inertia
        if ($request->hasHeader('X-Inertia')) {
            return true;
        }

        return false;
    }

    /**
     * FIX #1: Inject #[WithContext] data into Laravel's global Context.
     * Any reporter (Sentry, Flare, Log) that reads Context will include this data automatically.
     * Data is sanitized before injection to redact sensitive values.
     */
    private function injectLaravelContext(Throwable $e): void
    {
        $context = ExceptionInspector::context($e);

        if (empty($context)) {
            return;
        }

        $sanitized = DataSanitizer::sanitize(
            $context,
            $this->config['sanitize'] ?? []
        );

        // Laravel Context is available from Laravel 11+
        if (class_exists(Context::class)) {
            Context::addHidden('exception_context', $sanitized);
        }
    }

    /**
     * FIX #3: Wrap a reporter with RateLimitedReporter if the exception
     * carries a #[RateLimit] attribute. If no RateLimit attribute is set,
     * the reporter is returned unwrapped (zero overhead).
     */
    private function wrapWithRateLimit(mixed $reporter, Throwable $e): mixed
    {
        if (!$reporter instanceof ErrorReporterInterface) {
            return $reporter;
        }

        // Reporters that opt out of rate-limiting (e.g. XdebugReporter, DebugbarReporter)
        // must always fire on every exception so developers never miss a notification.
        if ($reporter instanceof BypassesRateLimiting) {
            return $reporter;
        }

        if (ExceptionInspector::rateLimit($e) === null) {
            return $reporter;
        }

        return new RateLimitedReporter($reporter);
    }



    /**
     * Get the merged ordered context pipeline (custom first, then config).
     *
     * @return array<class-string<ContextDetectorInterface>, class-string<ExceptionRendererInterface>>
     */
    private function getContexts(): array
    {
        return array_merge($this->customContexts, $this->config['contexts'] ?? []);
    }

    /**
     * Get the merged reporters list (custom first, then config).
     *
     * @return class-string<ErrorReporterInterface>[]
     */
    private function getReporters(): array
    {
        return array_merge($this->customReporters, $this->config['reporters'] ?? []);
    }
}
