<?php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors;

use Isaidgitmenow\LaravelErrors\Reporters\DebugbarReporter;
use Isaidgitmenow\LaravelErrors\Reporters\LogReporter;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class ErrorsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-errors')
            ->hasConfigFile('errors');
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
    }
}
