<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;

beforeEach(function () {
    $this->files = new Filesystem();
    $this->cleanupPaths = [];
});

afterEach(function () {
    foreach ($this->cleanupPaths as $path) {
        if ($this->files->isDirectory($path)) {
            $this->files->deleteDirectory($path);
        } elseif ($this->files->exists($path)) {
            $this->files->delete($path);
        }
    }
});

describe('make:error', function () {
    it('creates an exception file in the default Exceptions directory', function () {
        $targetPath = app_path('Exceptions/PaymentFailed.php');
        $this->cleanupPaths[] = app_path('Exceptions');

        $this->artisan('make:error', ['name' => 'PaymentFailed'])
            ->assertSuccessful()
            ->expectsOutputToContain('PaymentFailed');

        expect($this->files->exists($targetPath))->toBeTrue();
        $contents = file_get_contents($targetPath);
        expect($contents)->toContain('namespace App\Exceptions;');
        expect($contents)->toContain('class PaymentFailed extends Exception');
    });

    it('creates an exception file in a nested directory', function () {
        $targetPath = app_path('Exceptions/Billing/InvoiceNotFound.php');
        $this->cleanupPaths[] = app_path('Exceptions/Billing');

        $this->artisan('make:error', ['name' => 'Billing/InvoiceNotFound'])
            ->assertSuccessful();

        expect($this->files->exists($targetPath))->toBeTrue();
        $contents = file_get_contents($targetPath);
        expect($contents)->toContain('namespace App\Exceptions\Billing;');
    });

    it('refuses to overwrite an already existing exception file', function () {
        $targetPath = app_path('Exceptions/DuplicatePayment.php');
        $this->cleanupPaths[] = app_path('Exceptions');

        $this->files->ensureDirectoryExists(dirname($targetPath));
        $this->files->put($targetPath, '<?php // existing');

        $this->artisan('make:error', ['name' => 'DuplicatePayment'])
            ->assertFailed()
            ->expectsOutputToContain('already exists');
    });

    it('adds HttpCode attribute when --http is not 500', function () {
        $targetPath = app_path('Exceptions/UnauthorizedAction.php');
        $this->cleanupPaths[] = app_path('Exceptions');

        $this->artisan('make:error', [
            'name'   => 'UnauthorizedAction',
            '--http' => '401',
        ])->assertSuccessful();

        $contents = file_get_contents($targetPath);
        expect($contents)->toContain('#[HttpCode(401)]');
        expect($contents)->toContain('use Isaidgitmenow\LaravelErrors\Attributes\HttpCode;');
    });

    it('adds ReportTo attribute when --report is provided', function () {
        $targetPath = app_path('Exceptions/FraudDetected.php');
        $this->cleanupPaths[] = app_path('Exceptions');

        $this->artisan('make:error', [
            'name'     => 'FraudDetected',
            '--report' => 'slack',
        ])->assertSuccessful();

        $contents = file_get_contents($targetPath);
        expect($contents)->toContain("#[ReportTo('slack')]");
        expect($contents)->toContain('use Isaidgitmenow\LaravelErrors\Attributes\ReportTo;');
    });

    it('adds ReportTo attribute with multiple channels and envs', function () {
        $targetPath = app_path('Exceptions/CriticalError.php');
        $this->cleanupPaths[] = app_path('Exceptions');

        $this->artisan('make:error', [
            'name'     => 'CriticalError',
            '--report' => 'slack,sentry',
            '--env'    => 'prod,staging'
        ])->assertSuccessful();

        $contents = file_get_contents($targetPath);
        expect($contents)->toContain("#[ReportTo(['slack', 'sentry'], environments: ['prod', 'staging'])]");
    });
});
