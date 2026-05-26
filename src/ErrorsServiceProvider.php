<?php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors;

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
    }
}
