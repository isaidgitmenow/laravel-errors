<?php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Isaidgitmenow\LaravelErrors\Console\Concerns\BuildsErrorStubs;
use Isaidgitmenow\LaravelErrors\Console\Concerns\ValidatesErrorInput;

/**
 * Artisan command to generate a decorated exception class.
 *
 * Usage:
 *   php artisan make:error PaymentFailed
 *   php artisan make:error PaymentFailed --http=402
 *   php artisan make:error PaymentFailed --http=402 --report=slack --env=production
 */
class MakeExceptionCommand extends Command
{
    use BuildsErrorStubs;
    use ValidatesErrorInput;

    protected $signature = 'make:error
                            {name : The name of the exception class (e.g. PaymentFailed)}
                            {--http=500 : The HTTP status code for #[HttpCode]}
                            {--report= : The reporting channel(s) for #[ReportTo], comma-separated}
                            {--env= : Restrict #[ReportTo] to these environments, comma-separated}';

    protected $description = 'Generate a new decorated exception class with Laravel-Errors attributes';

    public function __construct(private readonly Filesystem $files)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $name     = $this->validatedClassName((string) $this->argument('name'));
            $http     = $this->validatedHttp($this->option('http'));
            // N-07: Validate --report AND --env, not just --report
            $channels = $this->option('report') ? $this->validatedList((string) $this->option('report'), 'report channel') : [];
            $envs     = $this->option('env') ? $this->validatedList((string) $this->option('env'), 'environment') : [];

            [$namespace, $class] = $this->resolveNamespaceAndClass($name);
            $targetPath = $this->resolveTargetPath($namespace, $class);
            $this->assertWithinBase($targetPath, app_path());
        } catch (\InvalidArgumentException $ex) {
            $this->components->error($ex->getMessage());
            return self::FAILURE;
        }

        if ($this->files->exists($targetPath)) {
            $this->components->error("Exception [{$class}] already exists.");
            return self::FAILURE;
        }

        $this->files->ensureDirectoryExists(dirname($targetPath));    // ABIA ACUM atingem discul
        $this->files->put($targetPath, $this->buildStub($namespace, $class, $http, $channels, $envs));

        $this->components->info("Exception [{$class}] created successfully.");
        $this->components->twoColumnDetail('File', $targetPath);

        return self::SUCCESS;
    }

    /**
     * Resolve the namespace and class name from the given name argument.
     *
     * @return array{string, string}
     */
    private function resolveNamespaceAndClass(string $name): array
    {
        $name = str_replace('/', '\\', $name);
        $parts = explode('\\', $name);
        $class = array_pop($parts);
        $subNamespace = implode('\\', $parts);

        $baseNamespace = $this->laravel->getNamespace() . 'Exceptions';
        $namespace = $subNamespace
            ? $baseNamespace . '\\' . $subNamespace
            : $baseNamespace;

        return [$namespace, $class];
    }

    /**
     * Resolve the absolute file path for the generated class.
     * N-08: Use substr with prefix check instead of str_replace (which corrupts AppPayments → Payments).
     */
    private function resolveTargetPath(string $namespace, string $class): string
    {
        $base = rtrim($this->laravel->getNamespace(), '\\');
        // F-13: substr pe prefix, nu str_replace (App\Exceptions\AppPayments devenea Exceptions/Payments)
        $relative = ($namespace === $base || str_starts_with($namespace, $base . '\\'))
            ? substr($namespace, strlen($base))
            : $namespace;

        return app_path(ltrim(str_replace('\\', DIRECTORY_SEPARATOR, $relative), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $class . '.php');
    }
}
