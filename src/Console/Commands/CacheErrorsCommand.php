<?php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Console\Commands;

use Illuminate\Console\Command;
use Isaidgitmenow\LaravelErrors\Support\AttributeCache;
use Isaidgitmenow\LaravelErrors\Support\AttributeScanner;
use Isaidgitmenow\LaravelErrors\Support\AttributeValidator;

final class CacheErrorsCommand extends Command
{
    protected $signature = 'errors:cache';
    protected $description = 'Scan exception classes and cache attribute data for production.';

    public function handle(AttributeScanner $scanner, AttributeValidator $validator): int
    {
        $paths   = $scanner->resolvePaths((array) config('errors.scan_paths', []));
        $classes = $scanner->scan($paths);
        $file    = $this->laravel->bootstrapPath('cache/errors.php');

        if ($paths === []) {
            $this->warn('No scan paths configured in errors.scan_paths. Cache file will be empty.');
        }

        // N-12: Report any classes that failed during scanning
        foreach ($scanner->failures() as $class => $message) {
            $this->warn("Skipped [{$class}]: {$message}");
        }

        // N-11: Validate attributes BEFORE writing cache — refuse to write with errors
        $issues = $validator->validate($classes);
        $errors = array_filter($issues, fn ($issue) => $issue->level === 'error');

        foreach ($issues as $issue) {
            $method = $issue->level === 'error' ? 'error' : 'warn';
            $this->components->{$method}("[{$issue->class}] {$issue->message}");
        }

        if ($errors !== []) {
            $this->components->error('Attribute validation failed. Cache NOT written. Fix the errors above and retry.');
            return self::FAILURE;
        }

        $payload = var_export(['version' => AttributeCache::VERSION, 'classes' => $classes], true);
        $result  = file_put_contents($file, '<?php return ' . $payload . ';' . PHP_EOL);

        if ($result === false) {
            $this->components->error("Failed to write cache file: {$file}");
            return self::FAILURE;
        }

        $this->info('Cached ' . count($classes) . ' attributed exception classes to ' . $file);

        return self::SUCCESS;
    }
}
