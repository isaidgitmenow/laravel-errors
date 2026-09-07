<?php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Console\Commands;

use Illuminate\Console\Command;
use Isaidgitmenow\LaravelErrors\Support\AttributeCache;
use Isaidgitmenow\LaravelErrors\Support\AttributeScanner;
use Isaidgitmenow\LaravelErrors\Support\AttributeValidator;

final class DoctorCommand extends Command
{
    protected $signature = 'errors:doctor';
    protected $description = 'Validate all attributed exception classes for common issues.';

    public function handle(AttributeScanner $scanner, AttributeValidator $validator): int
    {
        $paths   = $scanner->resolvePaths((array) config('errors.scan_paths', []));
        $classes = $scanner->scan($paths);
        $issues  = $validator->validate($classes);

        if ($classes === []) {
            $this->warn('No exception classes found in scan_paths. Check errors.scan_paths config.');

            return self::SUCCESS;
        }

        $this->info('Scanned ' . count($classes) . ' attributed exception class(es).');

        if ($issues === []) {
            $this->info('✅ No issues found.');

            return self::SUCCESS;
        }

        $errors = 0;
        foreach ($issues as $issue) {
            $emoji = match ($issue['level']) {
                'error'   => '❌',
                'warning' => '⚠️ ',
                default   => 'ℹ️ ',
            };
            if ($issue['level'] === 'error') {
                $errors++;
            }
            $this->line("  {$emoji} <comment>{$issue['class']}</comment>: {$issue['message']}");
        }

        $this->newLine();

        if ($errors > 0) {
            $this->error("Found {$errors} error(s) that should be fixed.");

            return self::FAILURE;
        }

        $this->warn('Found warnings. Review them above.');

        return self::SUCCESS;
    }
}
