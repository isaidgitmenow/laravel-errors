<?php
// file: src/ErrorsServiceProvider.php  — stare finală 3.0

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Isaidgitmenow\LaravelErrors\Console\Commands\CacheErrorsCommand;
use Isaidgitmenow\LaravelErrors\Console\Commands\ClearErrorsCacheCommand;
use Isaidgitmenow\LaravelErrors\Console\Commands\DoctorCommand;
use Isaidgitmenow\LaravelErrors\Console\Commands\ListErrorsCommand;
use Isaidgitmenow\LaravelErrors\Console\Commands\MakeDddErrorCommand;
use Isaidgitmenow\LaravelErrors\Console\Commands\MakeExceptionCommand;
use Isaidgitmenow\LaravelErrors\Contracts\ErrorManagerInterface;
use Isaidgitmenow\LaravelErrors\Exceptions\InvalidConfigurationException;
use Isaidgitmenow\LaravelErrors\Mcp\Commands\ErrorsMcpCommand;
use Isaidgitmenow\LaravelErrors\Support\AttributeCache;
use Isaidgitmenow\LaravelErrors\Support\AttributedExceptionMapper;
use Isaidgitmenow\LaravelErrors\Support\CallableResolver;
use Isaidgitmenow\LaravelErrors\Support\ErrorIdentity;
use Isaidgitmenow\LaravelErrors\Support\HandlerSlots;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class ErrorsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-errors')
            ->hasConfigFile('errors')
            ->hasViews()
            ->hasTranslations()
            ->hasCommands(
                MakeExceptionCommand::class,
                MakeDddErrorCommand::class,
                ErrorsMcpCommand::class,
                CacheErrorsCommand::class,
                ClearErrorsCacheCommand::class,
                DoctorCommand::class,
                ListErrorsCommand::class,
            );
    }

    public function register(): void
    {
        parent::register();

        // WP-09: hook-ul Livewire trebuie să existe ÎNAINTE ca Livewire să-și booteze registry-ul.
        // Registry-ul e static: în teste, register() rulează per instanță de aplicație → guard static contra dublării.
        static $hookRegistered = false;
        if (! $hookRegistered && class_exists(\Livewire\ComponentHookRegistry::class)) {
            \Livewire\ComponentHookRegistry::register(Integrations\Livewire\ExceptionHook::class);
            $hookRegistered = true;
        }
    }

    public function packageRegistered(): void
    {
        $config = fn ($app): array => (array) $app['config']->get('errors', []);

        $this->app->singleton(ErrorManagerInterface::class, fn ($app) => new ErrorManager(
            config: $app[Repository::class],
            events: $app[Dispatcher::class],
        ));
        $this->app->alias(ErrorManagerInterface::class, ErrorManager::class);

        $this->app->singleton(AttributeCache::class, fn ($app) => new AttributeCache(
            app:     $app,
            reader:  $app->make(Support\AttributeReader::class),
            scanner: $app->make(Support\AttributeScanner::class),
            store:   $app['cache']->store(),
            config:  $config($app),
        ));
        $this->app->singleton(HandlerSlots::class, fn ($app) => new HandlerSlots($app[Repository::class], $app[Dispatcher::class]));
        $this->app->singleton(AttributedExceptionMapper::class, fn ($app) => new AttributedExceptionMapper($app[Repository::class]));

        // bind(), nu singleton(): se re-rezolvă la fiecare utilizare, deci config-ul e citit proaspăt (F-21) fără Repository.
        foreach ([
            Renderers\ApiRenderer::class, Renderers\LivewireRenderer::class, Renderers\FilamentRenderer::class,
            Renderers\InertiaRenderer::class, Renderers\WebRenderer::class, Renderers\ValidationProblemRenderer::class,
            Reporters\LogReporter::class, Reporters\DebugbarReporter::class, Reporters\XdebugReporter::class,
            Support\ProblemDetailsFormatter::class,
        ] as $class) {
            $this->app->bind($class, fn ($app) => new $class($config($app)));
        }
    }

    public function packageBooted(): void
    {
        $this->validateConfiguration();

        // Încălzire: o eroare de cache (lipsă/stale în producție) trebuie să iasă AICI, nu în afterResolving(Handler),
        // unde ar înlocui excepția reală pe care Laravel o tratează.
        if (in_array(true, (array) $this->app['config']->get('errors.integrate_with_laravel', []), true)) {
            $this->app->make(AttributeCache::class)->all();
        }

        // optimizes() is available since illuminate/support 11.27
        if (method_exists($this, 'optimizes')) {
            $this->optimizes(optimize: 'errors:cache', clear: 'errors:clear', key: 'errors');
        }

        $events = $this->app->make(Dispatcher::class);

        // Golire per unitate de lucru. WeakMap-urile se golesc singure, dar cache-ul runtime al claselor vendor
        // și wrapper-ele memoizate merită golite pe granițe de request/job.
        $flush = static function (): void {
            ExceptionInspector::flushCache();
            ErrorIdentity::flush();
            app(AttributedExceptionMapper::class)->flush();
        };
        if (class_exists(\Laravel\Octane\Events\RequestTerminated::class)) {
            $events->listen(\Laravel\Octane\Events\RequestTerminated::class, $flush);
        }
        $events->listen([JobProcessed::class, JobExceptionOccurred::class], $flush);

        if (class_exists(\Sentry\SentrySdk::class) && $this->app->bound(\Sentry\State\HubInterface::class)) {
            \Sentry\State\Scope::addGlobalEventProcessor($this->app->make(Integrations\Sentry\AttributesEventProcessor::class));
        }
    }

    /** §1.2 regula 3: erorile de configurare explodează la boot, nu tac în producție. */
    private function validateConfiguration(): void
    {
        $c = (array) $this->app['config']->get('errors', []);

        $enum = static function (string $key, mixed $value, array $allowed): void {
            if (! in_array($value, $allowed, true)) {
                throw new InvalidConfigurationException(
                    "errors.{$key} must be one of [" . implode(', ', $allowed) . "], got [" . var_export($value, true) . '].'
                );
            }
        };

        if (($c['inertia_mode'] ?? 'page') === 'props') {
            throw new InvalidConfigurationException(
                "errors.inertia_mode 'props' was removed in 2.0: Inertia::share() does not survive a redirect, so the error was silently lost. Use 'page', 'flash' or 'respond'."
            );
        }
        $enum('inertia_mode', $c['inertia_mode'] ?? 'page', ['page', 'flash', 'redirect', 'respond']);
        $enum('expose_messages', $c['expose_messages'] ?? 'attributed', ['attributed', 'always', 'never']);
        $enum('livewire_mode', $c['livewire_mode'] ?? 'hook', ['hook', 'json']);

        foreach (['json_formatter', 'livewire_handler', 'filament_handler', 'metrics'] as $key) {
            CallableResolver::resolve($c[$key] ?? null, $key);   // aruncă dacă e class-string neinvokabil
        }

        foreach ((array) ($c['contexts'] ?? []) as $detector => $renderer) {
            if (! class_exists($detector) || ! class_exists($renderer)) {
                throw new InvalidConfigurationException("errors.contexts: [{$detector} => {$renderer}] references a missing class.");
            }
        }
        foreach ((array) ($c['reporters'] ?? []) as $reporter) {
            if (! class_exists($reporter)) {
                throw new InvalidConfigurationException("errors.reporters: [{$reporter}] does not exist.");
            }
        }
    }
}
