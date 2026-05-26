<?php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Tests;

use Isaidgitmenow\LaravelErrors\ErrorsServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            ErrorsServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.debug', false);
        $app['config']->set('app.key', 'base64:2fl+Ktvkfl+Fuzz3NwoU9bYQFMOSN31KZk2NsYyE0Ek=');
        $app['config']->set('cache.default', 'array');
    }
}
