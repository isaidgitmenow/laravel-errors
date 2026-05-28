<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Isaidgitmenow\LaravelErrors\Console\Commands\MakeDddErrorCommand;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/** Resolve the expected output path under Orchestra Testbench's base_path. */
function dddExceptionPath(string $domain, string $class, string $domainPath = 'src/Domain'): string
{
    return base_path("{$domainPath}/{$domain}/Exceptions/{$class}.php");
}

beforeEach(function () {
    $this->files        = new Filesystem();
    $this->cleanupPaths = [];
    // Reset fake state before every test so tests are isolated.
    MakeDddErrorCommand::unfake();
});

afterEach(function () {
    MakeDddErrorCommand::unfake();

    foreach ($this->cleanupPaths as $path) {
        if ($this->files->isDirectory($path)) {
            $this->files->deleteDirectory($path);
        } elseif ($this->files->exists($path)) {
            $this->files->delete($path);
        }
    }
});

// ---------------------------------------------------------------------------
// Graceful failure when laravel-ddd is absent
// ---------------------------------------------------------------------------

describe('ddd:error – missing laravel-ddd package', function () {

    it('fails gracefully with a friendly message when laravel-ddd is not installed', function () {
        // The real command returns false because tey/laravel-ddd is not
        // a dev dependency of this package — no fake needed here.
        $this->artisan('ddd:error', ['name' => 'Invoicing:PaymentFailed'])
            ->assertFailed()
            ->expectsOutputToContain('tey/laravel-ddd');
    });

});

// ---------------------------------------------------------------------------
// Successful generation – default DDD config (src/Domain / Domain)
// ---------------------------------------------------------------------------

describe('ddd:error – generation with default config (src/Domain)', function () {

    beforeEach(function () {
        MakeDddErrorCommand::fake();
        // null triggers the 'src/Domain' / 'Domain' fallback in the command.
        config()->set('ddd.domain_path', 'src/Domain');
        config()->set('ddd.domain_namespace', 'Domain');
    });

    it('creates the exception file at the correct DDD path using shorthand syntax', function () {
        $targetPath = dddExceptionPath('Invoicing', 'PaymentFailed');
        $this->cleanupPaths[] = dirname($targetPath, 2);

        $this->artisan('ddd:error', ['name' => 'Invoicing:PaymentFailed'])
            ->assertSuccessful()
            ->expectsOutputToContain('PaymentFailed');

        expect($this->files->exists($targetPath))->toBeTrue();
    });

    it('creates the exception file using the --domain flag', function () {
        $targetPath = dddExceptionPath('Billing', 'InvoiceNotFound');
        $this->cleanupPaths[] = dirname($targetPath, 2);

        $this->artisan('ddd:error', ['name' => 'InvoiceNotFound', '--domain' => 'Billing'])
            ->assertSuccessful();

        expect($this->files->exists($targetPath))->toBeTrue();
    });

    it('places the correct namespace in the generated file', function () {
        $targetPath = dddExceptionPath('Orders', 'OrderExpired');
        $this->cleanupPaths[] = dirname($targetPath, 2);

        $this->artisan('ddd:error', ['name' => 'Orders:OrderExpired'])->assertSuccessful();

        $contents = file_get_contents($targetPath);
        expect($contents)->toContain('namespace Domain\Orders\Exceptions;');
        expect($contents)->toContain('class OrderExpired extends Exception');
    });

    it('refuses to overwrite an already existing exception file', function () {
        $targetPath = dddExceptionPath('Payments', 'DuplicatePayment');
        $this->cleanupPaths[] = dirname($targetPath, 2);

        $this->files->ensureDirectoryExists(dirname($targetPath));
        $this->files->put($targetPath, '<?php // existing');

        $this->artisan('ddd:error', ['name' => 'Payments:DuplicatePayment'])
            ->assertFailed()
            ->expectsOutputToContain('already exists');
    });

    it('fails when no domain is given in either shorthand or --domain flag', function () {
        $this->artisan('ddd:error', ['name' => 'JustAClass'])
            ->assertFailed()
            ->expectsOutputToContain('domain name is required');
    });

});

// ---------------------------------------------------------------------------
// Honouring custom ddd.php config values
// ---------------------------------------------------------------------------

describe('ddd:error – respects config(ddd.domain_path) and config(ddd.domain_namespace)', function () {

    beforeEach(fn () => MakeDddErrorCommand::fake());

    it('places the file under a custom domain_path', function () {
        config()->set('ddd.domain_path', 'modules');
        config()->set('ddd.domain_namespace', 'Modules');

        $targetPath = dddExceptionPath('Shipping', 'ShipmentDelayed', 'modules');
        $this->cleanupPaths[] = dirname($targetPath, 2);

        $this->artisan('ddd:error', ['name' => 'Shipping:ShipmentDelayed'])
            ->assertSuccessful();

        expect($this->files->exists($targetPath))->toBeTrue();
    });

    it('uses the custom domain_namespace in the generated file', function () {
        config()->set('ddd.domain_path', 'modules');
        config()->set('ddd.domain_namespace', 'Modules');

        $targetPath = dddExceptionPath('Shipping', 'ShipmentLost', 'modules');
        $this->cleanupPaths[] = dirname($targetPath, 2);

        $this->artisan('ddd:error', ['name' => 'Shipping:ShipmentLost'])->assertSuccessful();

        $contents = file_get_contents($targetPath);
        expect($contents)->toContain('namespace Modules\Shipping\Exceptions;');
    });

});

// ---------------------------------------------------------------------------
// Attribute generation options
// ---------------------------------------------------------------------------

describe('ddd:error – attribute options', function () {

    beforeEach(function () {
        MakeDddErrorCommand::fake();
        config()->set('ddd.domain_path', 'src/Domain');
        config()->set('ddd.domain_namespace', 'Domain');
    });

    it('adds #[HttpCode] attribute when --http is not 500', function () {
        $targetPath = dddExceptionPath('Finance', 'UnauthorizedPayment');
        $this->cleanupPaths[] = dirname($targetPath, 2);

        $this->artisan('ddd:error', [
            'name'   => 'Finance:UnauthorizedPayment',
            '--http' => '401',
        ])->assertSuccessful();

        $contents = file_get_contents($targetPath);
        expect($contents)->toContain('#[HttpCode(401)]');
        expect($contents)->toContain('use Isaidgitmenow\LaravelErrors\Attributes\HttpCode;');
    });

    it('adds #[ReportTo] attribute when --report is provided', function () {
        $targetPath = dddExceptionPath('Finance', 'FraudDetected');
        $this->cleanupPaths[] = dirname($targetPath, 2);

        $this->artisan('ddd:error', [
            'name'     => 'Finance:FraudDetected',
            '--report' => 'slack',
        ])->assertSuccessful();

        $contents = file_get_contents($targetPath);
        expect($contents)->toContain("#[ReportTo('slack')]");
        expect($contents)->toContain('use Isaidgitmenow\LaravelErrors\Attributes\ReportTo;');
    });

});
