<?php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Console\Commands;

use Illuminate\Console\Command;
use Isaidgitmenow\LaravelErrors\Support\AttributeCache;

final class ListErrorsCommand extends Command
{
    protected $signature = 'errors:list
        {--attribute= : Filter by attribute name (e.g. error_code, http_code, dont_report)}
        {--json : Output as JSON}';
    protected $description = 'List all attributed exception classes and their metadata.';

    public function handle(AttributeCache $cache): int
    {
        $all    = $cache->all();
        $filter = $this->option('attribute');

        if ($filter !== null) {
            $all = array_filter($all, fn (array $data) => array_key_exists($filter, $data));
        }

        if ($this->option('json')) {
            $this->line(json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($all === []) {
            $this->info('No attributed exception classes found.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($all as $class => $data) {
            $attrs = array_keys($data);
            $rows[] = [
                $class,
                $data['http_code'] ?? '-',
                $data['error_code']['code'] ?? '-',
                $data['log_as'] ?? '-',
                implode(', ', array_diff($attrs, ['http_code', 'error_code', 'log_as'])),
            ];
        }

        $this->table(['Class', 'HTTP', 'Code', 'Level', 'Other Attributes'], $rows);

        return self::SUCCESS;
    }
}
