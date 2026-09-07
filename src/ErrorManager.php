<?php
// file: src/ErrorManager.php  — stare finală 2.2

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Isaidgitmenow\LaravelErrors\Contracts\BypassesRateLimiting;
use Isaidgitmenow\LaravelErrors\Contracts\ContextDetectorInterface;
use Isaidgitmenow\LaravelErrors\Contracts\ErrorManagerInterface;
use Isaidgitmenow\LaravelErrors\Contracts\ErrorReporterInterface;
use Isaidgitmenow\LaravelErrors\Contracts\ExceptionRendererInterface;
use Isaidgitmenow\LaravelErrors\Contracts\InteractiveContextDetector;
use Isaidgitmenow\LaravelErrors\Contracts\ReportsIgnoredExceptions;
use Isaidgitmenow\LaravelErrors\Events\ExceptionRendered;
use Isaidgitmenow\LaravelErrors\Events\ExceptionReported;
use Isaidgitmenow\LaravelErrors\Reporters\RateLimitedReporter;
use Isaidgitmenow\LaravelErrors\Support\CriticalLog;
use Isaidgitmenow\LaravelErrors\Support\ErrorIdentity;
use Isaidgitmenow\LaravelErrors\Support\HandlerSlots;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class ErrorManager implements ErrorManagerInterface
{
    /** @var array<class-string, class-string> */
    private array $customContexts = [];

    /** @var list<class-string<ErrorReporterInterface>> */
    private array $customReporters = [];

    /** @var array<class-string, true>  dedupe — F-18 */
    private array $dynamicPassThrough = [];

    /** Stare pe instanță (§10.2): singleton-ul e re-creat per worker Octane; nu mai avem statice de golit. */
    private bool $bypassConsoleExceptions = false;

    /** @param array<string, mixed>|Repository $config  union pentru testele care fac `new ErrorManager(config: [...])` (F-21) */
    public function __construct(
        private readonly array|Repository $config = [],
        private readonly ?Dispatcher $events = null,
    ) {}

    // ------------------------------------------------------------------ report

    public function report(Throwable $e): bool
    {
        if ($this->bypassConsoleExceptions) {
            return true;    // procesul MCP: nu rulăm nimic, dar Laravel poate loga în fișier (nu atinge STDOUT)
        }
        if ($this->isPassThrough($e)) {
            return true;    // §4: pass_through ocolește reporterii ȘI renderele pachetului; Laravel decide singur
        }

        $suppress = ExceptionInspector::shouldNotReport($e);
        $ran      = [];

        try {
            if (! $suppress) {
                $this->pushContext($e);
            }

            foreach ($this->reporters() as $reporterClass) {
                $reporter = app($reporterClass);

                if (! $reporter instanceof ErrorReporterInterface) {
                    continue;
                }
                if ($suppress && ! $reporter instanceof ReportsIgnoredExceptions) {
                    continue;
                }

                $reporter = $this->wrapWithRateLimit($reporter, $e);

                if (! $reporter->shouldReport($e)) {
                    continue;
                }

                $ran[]  = $reporterClass;
                $result = $reporter->report($e);

                if ($result === false) {
                    break;
                }
            }

            $this->dispatch(new ExceptionReported(
                exception:        ExceptionInspector::origin($e),
                errorId:          ErrorIdentity::for($e),
                errorCode:        ExceptionInspector::errorCode($e),
                httpStatus:       ExceptionInspector::httpCode($e),
                logLevel:         ExceptionInspector::logLevel($e),
                sanitizedContext: ExceptionInspector::sanitizedContext($e),
                reportersRun:     $ran,
            ));
        } catch (Throwable $failure) {
            // §10.1: niciodată catch gol. Rate-limitat 1/min/mesaj ca să nu inunde log-ul.
            CriticalLog::once('laravel-errors report pipeline failed', $failure, ['for' => $e::class]);
        }

        // AI Structured Logging — local environment only, never throws.
        if (app()->environment('local')) {
            try {
                \Isaidgitmenow\LaravelErrors\Mcp\McpLogger::log($e);
            } catch (Throwable) {
                // Logging must never crash the application
            }
        }

        // #[DontReport] → false: oprim și logarea Laravel și callback-urile următoare (Sentry) — asta e intenția.
        // Orice altceva → true: Laravel loghează O DATĂ, cu level() + context() din ErrorHandler.
        return ! $suppress;
    }

    // ------------------------------------------------------------------ render

    public function render(Throwable $e, Request $request): ?Response
    {
        if ($this->bypassConsoleExceptions) {
            return null;
        }

        try {
            if ($this->isPassThrough($e)) {
                return null;
            }

            [$detector, $rendererClass] = $this->matchContext($e, $request);

            if ($this->shouldYieldToIgnition($detector, $request)) {
                return null;
            }

            if ($rendererClass === null) {
                return null;
            }

            $renderer = app($rendererClass);
            if (! $renderer instanceof ExceptionRendererInterface) {
                return null;
            }

            $response = $renderer->render($e, $request);

            if ($response !== null) {
                $response->headers->set(HandlerSlots::RENDERED_HEADER, '1');   // HandlerSlots îl consumă și îl elimină
                $this->dispatch(new ExceptionRendered(
                    exception: ExceptionInspector::origin($e),
                    errorId:   ErrorIdentity::for($e),
                    response:  $response,
                    renderer:  $rendererClass,
                    context:   $this->contextName($detector),
                ));
            }

            return $response;
        } catch (Throwable $failure) {
            CriticalLog::once('laravel-errors render pipeline failed', $failure, ['for' => $e::class]);

            return null;
        }
    }

    // ------------------------------------------------------------------ pass-through (F-06)

    public function isPassThrough(Throwable $e): bool
    {
        $classes = array_merge((array) $this->cfg('pass_through', []), array_keys($this->dynamicPassThrough));

        // 1. Excepția efectiv aruncată are prioritate. Wrapper-ul nostru NU e Symfony\HttpException, deci nu e prins aici.
        foreach ($classes as $class) {
            if ($e instanceof $class) {
                return true;
            }
        }

        // 2. Dacă vreun nod din lanț poartă atributele noastre (origin() îl alege), dezvoltatorul a exprimat
        //    o intenție — nu o suprascriem cu cauza adâncă.
        if (ExceptionInspector::hasOwnAttributes(ExceptionInspector::origin($e))) {
            return false;
        }

        // 3. Doar wrapper-ele CUNOSCUTE de framework (ViewException) își transmit decizia din cauza reală.
        $isWrapper = false;
        foreach ((array) $this->cfg('pass_through_chain_wrappers', [\Illuminate\View\ViewException::class]) as $w) {
            if ($e instanceof $w) {
                $isWrapper = true;
                break;
            }
        }
        if (! $isWrapper) {
            return false;
        }

        $origin = ExceptionInspector::origin($e);
        if ($origin === $e) {
            return false;
        }
        foreach ($classes as $class) {
            if ($origin instanceof $class) {
                return true;
            }
        }

        return false;
    }

    public function addPassThrough(string $exceptionClass): void
    {
        $this->dynamicPassThrough[$exceptionClass] = true;
    }

    /** Compat: API static vechi → instanță. */
    public static function passThrough(string $exceptionClass): void
    {
        app(ErrorManagerInterface::class)->addPassThrough($exceptionClass);
    }

    public function addContext(string $detector, string $renderer): static
    {
        $this->customContexts[$detector] = $renderer;

        return $this;
    }

    public function addReporter(string $reporter): static
    {
        $this->customReporters[] = $reporter;

        return $this;
    }

    public function bypassConsoleExceptions(bool $on = true): void
    {
        $this->bypassConsoleExceptions = $on;
    }

    // ------------------------------------------------------------------ internals

    /** @return array{0: ?ContextDetectorInterface, 1: ?class-string} */
    private function matchContext(Throwable $e, Request $request): array
    {
        foreach (array_merge($this->customContexts, (array) $this->cfg('contexts', [])) as $detectorClass => $rendererClass) {
            $detector = app($detectorClass);
            if ($detector instanceof ContextDetectorInterface && $detector->detect($e, $request)) {
                return [$detector, $rendererClass];
            }
        }

        return [null, null];
    }

    private function shouldYieldToIgnition(?ContextDetectorInterface $detector, Request $request): bool
    {
        if (! $this->cfg('respect_debug_mode', true) || ! app()->hasDebugModeEnabled()) {
            return false;
        }

        return ! $request->ajax() && ! $request->wantsJson() && ! $detector instanceof InteractiveContextDetector;
    }

    private function pushContext(Throwable $e): void
    {
        // I-01/I-07: push, nu add (un request poate produce mai multe erori); vizibil, nu hidden (altfel nu ajunge nicăieri).
        Context::push('errors', [
            'error_id' => ErrorIdentity::for($e),
            'class'    => ExceptionInspector::origin($e)::class,
            'code'     => ExceptionInspector::errorCode($e),
            'context'  => ExceptionInspector::sanitizedContext($e),
        ]);
    }

    private function wrapWithRateLimit(ErrorReporterInterface $reporter, Throwable $e): ErrorReporterInterface
    {
        if ($reporter instanceof BypassesRateLimiting || ExceptionInspector::rateLimit($e) === null) {
            return $reporter;
        }

        return new RateLimitedReporter($reporter);
    }

    /** @return list<class-string> */
    private function reporters(): array
    {
        // F-22a: NU se cache-uiește pe instanță — singleton-ul trăiește cât procesul MCP, iar lock-ul se schimbă în același proces.
        if (app()->environment('local') && file_exists(storage_path('framework/mcp_mock_reporters.lock'))) {
            return [];
        }

        return array_merge($this->customReporters, (array) $this->cfg('reporters', []));
    }

    private function contextName(?ContextDetectorInterface $detector): string
    {
        return $detector === null ? 'web' : strtolower((string) preg_replace('/Detector$/', '', class_basename($detector)));
    }

    private function dispatch(object $event): void
    {
        try {
            ($this->events ?? app(Dispatcher::class))->dispatch($event);
        } catch (Throwable $failure) {
            CriticalLog::once('laravel-errors event listener failed', $failure, ['event' => $event::class]);
        }
    }

    private function cfg(string $key, mixed $default = null): mixed
    {
        if ($this->config instanceof Repository) {
            return $this->config->get("errors.{$key}", $default);
        }

        return $this->config[$key] ?? $default;
    }
}
