<?php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Isaidgitmenow\LaravelErrors\Console\Concerns\BuildsErrorStubs;

/**
 * Artisan command to generate a decorated exception class.
 *
 * Usage:
 *   php artisan make:error PaymentFailed
 *   php artisan make:error PaymentFailed --http=402
 *   php artisan make:error PaymentFailed --http=402 --report=slack
 */
class MakeExceptionCommand extends Command
{
    use BuildsErrorStubs;

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
        $name = $this->argument('name');

        [$namespace, $class] = $this->resolveNamespaceAndClass($name);

        $targetPath = $this->resolveTargetPath($namespace, $class);

        if ($this->files->exists($targetPath)) {
            $this->components->error("Exception [{$class}] already exists.");
            return self::FAILURE;
        }

        $this->files->ensureDirectoryExists(dirname($targetPath));
        $this->files->put($targetPath, $this->buildStub($namespace, $class));

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
     */
    private function resolveTargetPath(string $namespace, string $class): string
    {
        $baseNamespace = rtrim($this->laravel->getNamespace(), '\\');
        $relativePath = str_replace($baseNamespace, '', $namespace);
        $relativePath = str_replace('\\', DIRECTORY_SEPARATOR, $relativePath);

        return app_path(ltrim($relativePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $class . '.php');
    }
}
