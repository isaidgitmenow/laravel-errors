<?php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Mcp\Handlers;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Isaidgitmenow\LaravelErrors\ErrorManager;
use Isaidgitmenow\LaravelErrors\Mcp\McpLogReader;
use Isaidgitmenow\LaravelErrors\Mcp\McpLogger;
use ReflectionClass;
use Throwable;

/**
 * Handles MCP tools/call dispatching.
 *
 * Each tool method is named after its JSON-RPC tool name (with underscores → camelCase).
 * Input arguments are strictly validated and cast before use.
 */
final class ToolHandler
{
    /**
     * List of all available tools with their schemas.
     *
     * @return array<int, array<string, mixed>>
     */
    public function list(): array
    {
        return [
            [
                'name'        => 'generate_error',
                'description' => 'Generate a new decorated exception class using make:error (or ddd:error when a domain is provided).',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'name'   => ['type' => 'string', 'description' => 'Exception class name, e.g. PaymentFailed'],
                        'http'   => ['type' => 'integer', 'description' => 'HTTP status code (default 500)', 'default' => 500],
                        'report' => ['type' => 'string', 'description' => 'Comma-separated reporting channels'],
                        'env'    => ['type' => 'string', 'description' => 'Comma-separated environments for #[ReportTo]'],
                        'domain' => ['type' => 'string', 'description' => 'DDD domain name (triggers ddd:error instead of make:error)'],
                    ],
                    'required' => ['name'],
                ],
            ],
            [
                'name'        => 'list_exceptions',
                'description' => 'List all custom exception classes in the application with their attributes.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'page'     => ['type' => 'integer', 'description' => 'Page number (1-indexed)', 'default' => 1],
                        'per_page' => ['type' => 'integer', 'description' => 'Results per page (default 20)', 'default' => 20],
                    ],
                ],
            ],
            [
                'name'        => 'inspect_pipeline',
                'description' => 'Dump the active Detectors, Renderers, and Reporters configured in the error pipeline.',
                'inputSchema' => ['type' => 'object', 'properties' => []],
            ],
            [
                'name'        => 'get_recent_errors',
                'description' => 'Return the last 10 structured errors from the MCP JSONL log.',
                'inputSchema' => ['type' => 'object', 'properties' => []],
            ],
            [
                'name'        => 'simulate_error',
                'description' => 'Instantiate and run an exception class through the error pipeline inside a database transaction that is always rolled back.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'class'            => ['type' => 'string', 'description' => 'Fully-qualified Throwable class name'],
                        'context'          => ['type' => 'object', 'description' => 'Simulated request context (url, method, headers)'],
                        'constructor_args' => ['type' => 'array', 'description' => 'Arguments passed to the exception constructor', 'items' => ['type' => 'string']],
                    ],
                    'required' => ['class'],
                ],
            ],
            [
                'name'        => 'search_error_logs',
                'description' => 'Search historical MCP JSONL error logs for a keyword (capped at 20 results).',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'Search keyword or phrase'],
                    ],
                    'required' => ['query'],
                ],
            ],
            [
                'name'        => 'get_global_context',
                'description' => 'Return all values currently stored in Laravel\'s global Context facade.',
                'inputSchema' => ['type' => 'object', 'properties' => []],
            ],
            [
                'name'        => 'mock_reporters',
                'description' => 'Enable or disable reporter mocking via a lock file. When enabled, all external reporters are silenced (local env only).',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'enable' => ['type' => 'boolean', 'description' => 'true to enable mocking, false to remove the lock file'],
                    ],
                    'required' => ['enable'],
                ],
            ],
        ];
    }

    /**
     * Dispatch a tool call by name.
     *
     * @param  array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function call(string $name, array $arguments): array
    {
        return match ($name) {
            'generate_error'    => $this->generateError($arguments),
            'list_exceptions'   => $this->listExceptions($arguments),
            'inspect_pipeline'  => $this->inspectPipeline(),
            'get_recent_errors' => $this->getRecentErrors(),
            'simulate_error'    => $this->simulateError($arguments),
            'search_error_logs' => $this->searchErrorLogs($arguments),
            'get_global_context' => $this->getGlobalContext(),
            'mock_reporters'    => $this->mockReporters($arguments),
            default             => throw new \InvalidArgumentException("Unknown tool: {$name}"),
        };
    }

    // -------------------------------------------------------------------------
    // Tool Implementations
    // -------------------------------------------------------------------------

    /**
     * @param  array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function generateError(array $args): array
    {
        $name   = trim((string) ($args['name'] ?? ''));
        $http   = (int) ($args['http'] ?? 500);
        $report = trim((string) ($args['report'] ?? ''));
        $env    = trim((string) ($args['env'] ?? ''));
        $domain = trim((string) ($args['domain'] ?? ''));

        if ($name === '') {
            throw new \InvalidArgumentException('generate_error: "name" argument is required.');
        }

        // Path traversal security — reject any domain containing ../
        if ($domain !== '' && str_contains($domain, '..')) {
            throw new \InvalidArgumentException('generate_error: "domain" must not contain path traversal sequences.');
        }

        $command    = $domain !== '' ? 'ddd:error' : 'make:error';
        $parameters = ['name' => $name, '--http' => $http];

        if ($report !== '') {
            $parameters['--report'] = $report;
        }
        if ($env !== '') {
            $parameters['--env'] = $env;
        }
        if ($domain !== '') {
            $parameters['--domain'] = $domain;
        }

        $exitCode = Artisan::call($command, $parameters);
        $output   = Artisan::output();

        return [
            'success'   => $exitCode === 0,
            'exit_code' => $exitCode,
            'output'    => trim($output),
        ];
    }

    /**
     * @param  array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function listExceptions(array $args): array
    {
        $page    = max(1, (int) ($args['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($args['per_page'] ?? 20)));

        $results = [];

        $paths = [
            app_path('Exceptions') => app()->getNamespace() . 'Exceptions\\',
        ];

        // Also scan the lunarstorm/laravel-ddd domain paths if they exist
        $domainPath = rtrim((string) config('ddd.domain_path', 'src/Domain'), '/\\');
        $domainFullPath = base_path($domainPath);
        if (is_dir($domainFullPath)) {
            $domainNamespace = rtrim((string) config('ddd.domain_namespace', 'Domain'), '\\') . '\\';
            $paths[$domainFullPath] = $domainNamespace;
        }

        foreach ($paths as $basePath => $baseNamespace) {
            if (! is_dir($basePath)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($basePath, \FilesystemIterator::SKIP_DOTS)
            );

            /** @var \SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $realPath = $file->getRealPath();
                if ($realPath === false) {
                    continue;
                }

                $relative  = str_replace($basePath . DIRECTORY_SEPARATOR, '', $realPath);

                // For DDD paths, we only care about files in an 'Exceptions' directory
                if ($basePath === $domainFullPath && !str_contains($relative, DIRECTORY_SEPARATOR . 'Exceptions' . DIRECTORY_SEPARATOR)) {
                    continue;
                }

                $classPath = str_replace([DIRECTORY_SEPARATOR, '.php'], ['\\', ''], $relative);
                $fqcn      = $baseNamespace . $classPath;

                if (! class_exists($fqcn)) {
                    continue;
                }

                try {
                    $ref    = new ReflectionClass($fqcn);
                    $attrs  = [];
                    foreach ($ref->getAttributes() as $attr) {
                        $attrs[] = class_basename($attr->getName());
                    }

                    $results[] = [
                        'class'      => $fqcn,
                        'file'       => str_replace(base_path() . DIRECTORY_SEPARATOR, '', $file->getRealPath()),
                        'attributes' => $attrs,
                        'throwable'  => $ref->implementsInterface(Throwable::class) || $ref->isSubclassOf(\Exception::class),
                    ];
                } catch (Throwable) {
                    // Skip unloadable classes
                }
            }
        }

        $total  = count($results);
        $paged  = array_slice($results, ($page - 1) * $perPage, $perPage);

        return ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'items' => $paged];
    }

    /**
     * @return array<string, mixed>
     */
    private function inspectPipeline(): array
    {
        $config    = config('errors', []);
        $contexts  = $config['contexts'] ?? [];
        $reporters = $config['reporters'] ?? [];

        $pipeline = [];
        foreach ($contexts as $detectorClass => $rendererClass) {
            $pipeline[] = [
                'detector' => $detectorClass,
                'renderer' => $rendererClass,
            ];
        }

        return [
            'pipeline'  => $pipeline,
            'reporters' => array_values($reporters),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getRecentErrors(): array
    {
        $reader  = new McpLogReader(McpLogger::logPath());
        $entries = $reader->tail(10);

        return ['count' => count($entries), 'errors' => $entries];
    }

    /**
     * @param  array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function simulateError(array $args): array
    {
        $class           = trim((string) ($args['class'] ?? ''));
        $context         = (array) ($args['context'] ?? []);
        $constructorArgs = (array) ($args['constructor_args'] ?? []);

        if ($class === '') {
            throw new \InvalidArgumentException('simulate_error: "class" argument is required.');
        }

        // Security: only allow Throwable subclasses
        if (! class_exists($class) || ! is_subclass_of($class, Throwable::class)) {
            throw new \InvalidArgumentException("simulate_error: [{$class}] is not a valid Throwable subclass.");
        }

        // Mock the Request object from context
        $url     = (string) ($context['url'] ?? 'http://localhost/simulate');
        $method  = strtoupper((string) ($context['method'] ?? 'GET'));
        $headers = (array) ($context['headers'] ?? []);

        // Convert headers to $_SERVER format expected by Symfony Request
        $serverVars = [];
        foreach ($headers as $key => $value) {
            $serverKey = 'HTTP_' . str_replace('-', '_', strtoupper((string) $key));
            $serverVars[$serverKey] = $value;
        }

        $request = \Illuminate\Http\Request::create($url, $method, [], [], [], $serverVars);

        // Save the real request so we can restore it after simulation.
        $originalRequest = app()->bound('request') ? app('request') : null;
        app()->instance('request', $request);

        // Temporarily lift the MCP bypass so report() and render() actually run.
        // We also track the original state so we can restore it.
        $reflection = new ReflectionClass(ErrorManager::class);
        $bypassProperty = $reflection->getProperty('bypassConsoleExceptions');
        $bypassProperty->setAccessible(true);
        $wasBypassed = $bypassProperty->getValue();

        ErrorManager::resetBypass();

        $result    = ['success' => false, 'error' => 'unknown'];
        $txStarted = false;

        try {
            // Keep beginTransaction() inside try so a PDO disconnect doesn't
            // skip the finally block (which restores the bypass + request).
            try {
                DB::beginTransaction();
                $txStarted = true;
            } catch (Throwable) {
                // Ignore DB connection errors so simulation can proceed without DB.
            }

            $ref       = new ReflectionClass($class);
            $exception = $constructorArgs
                ? $ref->newInstanceArgs($constructorArgs)
                : $ref->newInstance();

            /** @var ErrorManager $manager */
            $manager = app(ErrorManager::class);
            $manager->report($exception);

            $response = $manager->render($exception, $request);

            $result = [
                'success'       => true,
                'exception'     => $class,
                'response_code' => $response?->getStatusCode(),
                'reported'      => true,
            ];
        } catch (Throwable $e) {
            $result = [
                'success' => false,
                'error'   => $e->getMessage(),
            ];
        } finally {
            // Always roll back, but only if we actually opened a transaction.
            if ($txStarted) {
                try {
                    DB::rollBack();
                } catch (Throwable) {
                    // Ignore — DB may have already disconnected.
                }
            }
            
            // Restore the bypass state
            if ($wasBypassed) {
                ErrorManager::bypassConsoleExceptions();
            }
            
            // Restore the original request in the container.
            if ($originalRequest !== null) {
                app()->instance('request', $originalRequest);
            } else {
                app()->forgetInstance('request');
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function searchErrorLogs(array $args): array
    {
        $query = trim((string) ($args['query'] ?? ''));

        if ($query === '') {
            throw new \InvalidArgumentException('search_error_logs: "query" argument is required.');
        }

        $reader  = new McpLogReader(McpLogger::logPath());
        $results = $reader->search($query);

        return ['query' => $query, 'count' => count($results), 'results' => $results];
    }

    /**
     * @return array<string, mixed>
     */
    private function getGlobalContext(): array
    {
        if (! class_exists(Context::class)) {
            return ['available' => false, 'data' => []];
        }

        return ['available' => true, 'data' => Context::all()];
    }

    /**
     * @param  array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function mockReporters(array $args): array
    {
        $enable   = (bool) ($args['enable'] ?? true);
        $lockPath = storage_path('framework/mcp_mock_reporters.lock');

        if ($enable) {
            @mkdir(dirname($lockPath), 0775, recursive: true);
            // Bug 4 fix: check the return value so we never silently lie about mocking being active.
            $written = @file_put_contents($lockPath, date('c'));
            if ($written === false) {
                throw new \RuntimeException("mock_reporters: failed to write lock file at {$lockPath}. Check directory permissions.");
            }
            return ['mocked' => true, 'lock_file' => $lockPath];
        }

        if (file_exists($lockPath)) {
            if (!@unlink($lockPath)) {
                throw new \RuntimeException("mock_reporters: failed to remove lock file at {$lockPath}. Check permissions.");
            }
        }

        return ['mocked' => false, 'lock_file' => $lockPath];
    }
}
