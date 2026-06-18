<?php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Mcp\Handlers;

use Composer\InstalledVersions;

/**
 * Handles MCP resources/list and resources/read.
 *
 * Static resources:
 *   errors://environment      — Ecosystem + runtime detection
 *   errors://rules/standards  — SOLID/DRY/KISS rules markdown
 *
 * Dynamic resources (auto-discovered):
 *   errors://docs/{slug}      — Every markdown file in the docs/ directory
 */
final class ResourceHandler
{
    /**
     * Return the full list of available resources (static + dynamic docs).
     *
     * @return array<int, array<string, mixed>>
     */
    public function list(): array
    {
        $resources = [
            [
                'uri'         => 'errors://environment',
                'name'        => 'Environment',
                'description' => 'Current ecosystem detection: installed packages, versions, and runtime state.',
                'mimeType'    => 'application/json',
            ],
            [
                'uri'         => 'errors://rules/standards',
                'name'        => 'Coding Standards',
                'description' => 'Strict SOLID, DRY, and KISS rules for this package.',
                'mimeType'    => 'text/markdown',
            ],
        ];

        foreach ($this->discoverDocs() as $slug => $path) {
            $resources[] = [
                'uri'         => "errors://docs/{$slug}",
                'name'        => "Docs: {$slug}",
                'description' => "Documentation file: {$slug}.md",
                'mimeType'    => 'text/markdown',
            ];
        }

        return $resources;
    }

    /**
     * Read and return the content of the requested resource.
     *
     * @return array<string, mixed>
     */
    public function read(string $uri): array
    {
        return match (true) {
            $uri === 'errors://environment'     => $this->readEnvironment(),
            $uri === 'errors://rules/standards' => $this->readStandards(),
            str_starts_with($uri, 'errors://docs/') => $this->readDoc(substr($uri, strlen('errors://docs/'))),
            default => throw new \InvalidArgumentException("Unknown resource URI: {$uri}"),
        };
    }

    // -------------------------------------------------------------------------
    // Resource Readers
    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function readEnvironment(): array
    {
        $packages = [
            'inertiajs/inertia-laravel' => null,
            'livewire/livewire'          => null,
            'filament/filament'          => null,
            'barryvdh/laravel-debugbar'  => null,
        ];

        foreach (array_keys($packages) as $package) {
            try {
                if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled($package)) {
                    $packages[$package] = InstalledVersions::getPrettyVersion($package);
                }
            } catch (\Throwable) {
                // Package not installed or Composer runtime not available
            }
        }

        $opcache = function_exists('opcache_get_status') ? opcache_get_status(false) : null;

        $content = json_encode([
            'php_version'     => PHP_VERSION,
            'laravel_version' => app()->version(),
            'environment'     => app()->environment(),
            'debug'           => app()->hasDebugModeEnabled(),
            'packages'        => $packages,
            'opcache'         => [
                'enabled'        => is_array($opcache) && ($opcache['opcache_enabled'] ?? false),
                'cached_scripts' => is_array($opcache) ? ($opcache['opcache_statistics']['num_cached_scripts'] ?? 0) : 0,
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return [
            'uri'      => 'errors://environment',
            'mimeType' => 'application/json',
            'text'     => $content,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function readStandards(): array
    {
        $content = <<<'MARKDOWN'
        # Laravel-Errors Coding Standards

        ## SOLID Principles

        ### S — Single Responsibility Principle
        - Every class has exactly one reason to change.
        - Controllers and Models must be thin; move business logic to Services or Actions.
        - Each Handler class in this package handles exactly one protocol concern (tools, resources, prompts).

        ### O — Open/Closed Principle
        - Extend behaviour via Interfaces and abstract classes — do NOT modify existing core logic.
        - Add new detectors/renderers/reporters by implementing the corresponding Contract interface.
        - Never edit `ErrorManager` to add renderer-specific logic; create a new Renderer instead.

        ### L — Liskov Substitution Principle
        - Every `ErrorReporterInterface` implementation must honour `shouldReport()` semantics.
        - Every `ContextDetectorInterface` implementation must return a proper boolean from `detect()`.
        - Overriding methods must never change the expected return type or throw unexpected exceptions.

        ### I — Interface Segregation Principle
        - `BypassesRateLimiting` is a small, focused marker interface — do not bloat it.
        - `ReportsIgnoredExceptions` is similarly narrow. Prefer many small interfaces over one large one.

        ### D — Dependency Inversion Principle
        - Depend on `ErrorReporterInterface`, not concrete reporter classes.
        - Use Laravel's Service Container for injection — register bindings in `ErrorsServiceProvider`.
        - Never call `new ConcreteClass()` inside a class that could receive it via DI.

        ## DRY — Don't Repeat Yourself
        - Stack trace filtering logic lives exclusively in `McpLogger`.
        - JSONL reading logic lives exclusively in `McpLogReader`.
        - Stub building logic lives exclusively in the `BuildsErrorStubs` trait.

        ## KISS — Keep It Simple, Stupid
        - Prefer explicit `match()` over complex if-else chains.
        - Prefer `final` classes unless extension is explicitly required.
        - Zero third-party MCP dependencies — everything is plain PHP + Laravel primitives.

        ## Error Handling Rules
        - The error handling pipeline must NEVER throw. Use `try/catch(Throwable)` with silent self-healing.
        - MCP handlers may throw `\InvalidArgumentException` — `McpServer` catches and converts them to JSON-RPC errors.
        - Production safety: local-env checks are mandatory for any tool that writes to disk.
        MARKDOWN;

        return [
            'uri'      => 'errors://rules/standards',
            'mimeType' => 'text/markdown',
            'text'     => $content,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function readDoc(string $slug): array
    {
        $docs = $this->discoverDocs();

        if (! isset($docs[$slug])) {
            throw new \InvalidArgumentException("Documentation file not found: {$slug}");
        }

        $content = @file_get_contents($docs[$slug]);

        if ($content === false) {
            throw new \RuntimeException("Unable to read documentation file: {$slug}");
        }

        return [
            'uri'      => "errors://docs/{$slug}",
            'mimeType' => 'text/markdown',
            'text'     => $content,
        ];
    }

    // -------------------------------------------------------------------------
    // Dynamic Docs Discovery
    // -------------------------------------------------------------------------

    /**
     * Scan the package docs/ directory and return a map of slug => absolute path.
     *
     * @return array<string, string>
     */
    private function discoverDocs(): array
    {
        // The docs directory lives next to src/ inside this package.
        // We resolve it relative to this file's location.
        $docsDir = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'docs';

        if (! is_dir($docsDir)) {
            return [];
        }

        $docs = [];

        $iterator = new \DirectoryIterator($docsDir);
        foreach ($iterator as $file) {
            if ($file->isDot() || $file->getExtension() !== 'md') {
                continue;
            }

            // Use the filename without extension as the slug
            $docs[$file->getBasename('.md')] = $file->getRealPath();
        }

        ksort($docs);

        return $docs;
    }
}
