<?php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors;

use Illuminate\Contracts\Events\Dispatcher;
use Isaidgitmenow\LaravelErrors\Console\Commands\MakeDddErrorCommand;
use Isaidgitmenow\LaravelErrors\Console\Commands\MakeExceptionCommand;
use Isaidgitmenow\LaravelErrors\Renderers\ApiRenderer;
use Isaidgitmenow\LaravelErrors\Renderers\FilamentRenderer;
use Isaidgitmenow\LaravelErrors\Renderers\InertiaRenderer;
use Isaidgitmenow\LaravelErrors\Renderers\LivewireRenderer;
use Isaidgitmenow\LaravelErrors\Reporters\DebugbarReporter;
use Isaidgitmenow\LaravelErrors\Reporters\LogReporter;
use Isaidgitmenow\LaravelErrors\Reporters\XdebugReporter;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class ErrorsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-errors')
            ->hasConfigFile('errors')
            ->hasCommand(MakeExceptionCommand::class)
            ->hasCommand(MakeDddErrorCommand::class);
    }

    public function packageRegistered(): void
    {
        // Bind ErrorManager as a singleton, resolved with the merged config.
        $this->app->singleton(ErrorManager::class, function ($app) {
            return new ErrorManager(
                config: $app['config']->get('errors', []),
            );
        });

        // Bind reporters with config injected so they receive the
        // 'sanitize' key list and any other config values at runtime.
        $this->app->bind(LogReporter::class, function ($app) {
            return new LogReporter(
                config: $app['config']->get('errors', []),
            );
        });

        $this->app->bind(DebugbarReporter::class, function ($app) {
            return new DebugbarReporter(
                config: $app['config']->get('errors', []),
            );
        });

        $this->app->bind(XdebugReporter::class, function ($app) {
            return new XdebugReporter(
                config: $app['config']->get('errors', []),
            );
        });

        // Bind renderers so they receive the full config (json_formatter,
        // livewire_handler, filament_handler, inertia_mode, etc.).
        $this->app->bind(ApiRenderer::class, function ($app) {
            return new ApiRenderer(
                config: $app['config']->get('errors', []),
            );
        });

        $this->app->bind(FilamentRenderer::class, function ($app) {
            return new FilamentRenderer(
                config: $app['config']->get('errors', []),
            );
        });

        $this->app->bind(InertiaRenderer::class, function ($app) {
            return new InertiaRenderer(
                config: $app['config']->get('errors', []),
            );
        });

        $this->app->bind(LivewireRenderer::class, function ($app) {
            return new LivewireRenderer(
                config: $app['config']->get('errors', []),
            );
        });
    }

    public function packageBooted(): void
    {
        // Octane Compatibility: flush the static reflection cache after every request
        // to prevent memory leaks under Swoole / RoadRunner long-running processes.
        if (class_exists(\Laravel\Octane\Events\RequestTerminated::class)) {
            /** @var Dispatcher $events */
            $events = $this->app->make(Dispatcher::class);
            $events->listen(
                \Laravel\Octane\Events\RequestTerminated::class,
                static function (): void {
                    ExceptionInspector::flushCache();
                    ErrorManager::flushPassThrough();
                }
            );
        }
    }
}
