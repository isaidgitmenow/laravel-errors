<?php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Console\Commands;

use Illuminate\Console\Command;

final class ClearErrorsCacheCommand extends Command
{
    protected $signature = 'errors:clear';
    protected $description = 'Remove the cached errors attribute file.';

    public function handle(): int
    {
        $file = $this->laravel->bootstrapPath('cache/errors.php');

        if (is_file($file)) {
            @unlink($file);
            $this->info('Errors cache cleared.');
        } else {
            $this->info('No errors cache file found.');
        }

        return self::SUCCESS;
    }
}
