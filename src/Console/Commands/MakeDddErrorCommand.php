<?php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Isaidgitmenow\LaravelErrors\Console\Concerns\BuildsErrorStubs;

/**
 * Artisan command to generate a decorated exception class inside a DDD domain module.
 *
 * Requires the `tey/laravel-ddd` package at runtime; fails gracefully if absent.
 *
 * Usage:
 *   php artisan ddd:error PaymentFailed --domain=Invoicing
 *   php artisan ddd:error Invoicing:PaymentFailed
 *   php artisan ddd:error Invoicing:PaymentFailed --http=402 --report=slack
 */
class MakeDddErrorCommand extends Command
{
    use BuildsErrorStubs;

    /**
     * When non-null, overrides the runtime availability check.
     * Use `MakeDddErrorCommand::fake()` / `::unfake()` in tests.
     *
     * @internal
     */
    private static ?bool $fakeAvailable = null;

    /** Force the command to behave as if laravel-ddd IS available (test helper). */
    public static function fake(): void
    {
        self::$fakeAvailable = true;
    }

    /** Force the command to behave as if laravel-ddd is NOT available (test helper). */
    public static function fakeUnavailable(): void
    {
        self::$fakeAvailable = false;
    }

    /** Restore real runtime availability detection. */
    public static function unfake(): void
    {
        self::$fakeAvailable = null;
    }

    protected $signature = 'ddd:error
                            {name : Exception name or shorthand "Domain:ClassName" (e.g. Invoicing:PaymentFailed)}
                            {--domain= : The domain name (overrides shorthand prefix)}
                            {--http=500 : The HTTP status code for #[HttpCode]}
                            {--report= : The reporting channel(s) for #[ReportTo], comma-separated}
                            {--env= : Restrict #[ReportTo] to these environments, comma-separated}';

    protected $description = 'Generate a new decorated exception class inside a DDD domain module';

    public function __construct(private readonly Filesystem $files)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->isLaravelDddAvailable()) {
            $this->components->error(
                'The [tey/laravel-ddd] package is required to use [ddd:error]. ' .
                'Install it with: composer require tey/laravel-ddd'
            );

            return self::FAILURE;
        }

        [$domain, $class] = $this->parseDomainAndClass();

        if (! $domain) {
            $this->components->error(
                'A domain name is required. Use [--domain=MyDomain] or the shorthand [Domain:ClassName].'
            );

            return self::FAILURE;
        }

        $namespace  = $this->resolveDomainNamespace($domain);
        $targetPath = $this->resolveDomainPath($domain, $class);

        if ($this->files->exists($targetPath)) {
            $this->components->error("Exception [{$class}] already exists in domain [{$domain}].");

            return self::FAILURE;
        }

        $this->files->ensureDirectoryExists(dirname($targetPath));
        $this->files->put($targetPath, $this->buildStub($namespace, $class));

        $this->components->info("Exception [{$class}] created in domain [{$domain}].");
        $this->components->twoColumnDetail('File', $targetPath);

        return self::SUCCESS;
    }

    // -------------------------------------------------------------------------
    // Parsing
    // -------------------------------------------------------------------------

    /**
     * Parse the domain name and bare class name from the `name` argument and
     * the optional `--domain` flag.
     *
     * Shorthand format:  `Invoicing:PaymentFailed`  →  domain=Invoicing, class=PaymentFailed
     * Flag format:       `PaymentFailed --domain=Invoicing`  →  same result
     *
     * @return array{string|null, string}
     */
    private function parseDomainAndClass(): array
    {
        $raw = $this->argument('name');

        if (Str::contains($raw, ':')) {
            [$domain, $class] = explode(':', $raw, 2);
        } else {
            $domain = null;
            $class  = $raw;
        }

        // --domain flag takes precedence over shorthand prefix
        $domainOption = $this->option('domain');
        if ($domainOption) {
            $domain = $domainOption;
        }

        return [$domain ?: null, $class];
    }

    // -------------------------------------------------------------------------
    // Path / Namespace Resolution (reads ddd.php config with fallback)
    // -------------------------------------------------------------------------

    /**
     * Resolve the fully-qualified namespace for the Exceptions sub-directory of
     * the given domain, honouring `config('ddd.domain_namespace')`.
     */
    private function resolveDomainNamespace(string $domain): string
    {
        $baseNamespace = rtrim((string) config('ddd.domain_namespace', 'Domain'), '\\');

        return "{$baseNamespace}\\{$domain}\\Exceptions";
    }

    /**
     * Resolve the absolute filesystem path for the generated file, honouring
     * `config('ddd.domain_path')`.
     */
    private function resolveDomainPath(string $domain, string $class): string
    {
        $domainPath = rtrim((string) config('ddd.domain_path', 'src/Domain'), '/\\');

        // base_path() keeps us relative to the Laravel application root.
        return base_path("{$domainPath}/{$domain}/Exceptions/{$class}.php");
    }

    // -------------------------------------------------------------------------
    // Runtime dependency check
    // -------------------------------------------------------------------------

    /**
     * Check whether the tey/laravel-ddd package is available at runtime.
     * This avoids a hard composer dependency while still failing gracefully.
     */
    protected function isLaravelDddAvailable(): bool
    {
        if (self::$fakeAvailable !== null) {
            return self::$fakeAvailable;
        }

        // The canonical class shipped by tey/laravel-ddd
        return class_exists(\Lunarstorm\LaravelDDD\LaravelDDD::class)
            || class_exists(\Tey\LaravelDDD\LaravelDDDServiceProvider::class);
    }
}
