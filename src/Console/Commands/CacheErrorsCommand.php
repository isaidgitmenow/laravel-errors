<?php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Console\Commands;

use Illuminate\Console\Command;
use Isaidgitmenow\LaravelErrors\Support\AttributeCache;
use Isaidgitmenow\LaravelErrors\Support\AttributeScanner;

final class CacheErrorsCommand extends Command
{
    protected $signature = 'errors:cache';
    protected $description = 'Scan exception classes and cache attribute data for production.';

    public function handle(AttributeScanner $scanner): int
    {
        $paths   = $scanner->resolvePaths((array) config('errors.scan_paths', []));
        $classes = $scanner->scan($paths);
        $file    = $this->laravel->bootstrapPath('cache/errors.php');

        if ($paths === []) {
            $this->warn('No scan paths configured in errors.scan_paths. Cache file will be empty.');
        }

        $payload = var_export(['version' => AttributeCache::VERSION, 'classes' => $classes], true);
        file_put_contents($file, '<?php return ' . $payload . ';' . PHP_EOL);

        $this->info('Cached ' . count($classes) . ' attributed exception classes to ' . $file);

        return self::SUCCESS;
    }
}
