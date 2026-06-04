<?php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Console\Concerns;

/**
 * Shared stub-building logic for make:error and ddd:error commands.
 *
 * Extracted to avoid code duplication between MakeExceptionCommand
 * and MakeDddErrorCommand (DRY / SRP).
 */
trait BuildsErrorStubs
{
    /**
     * Build the stub contents with all replacements applied.
     */
    private function buildStub(string $namespace, string $class): string
    {
        $stub = $this->files->get($this->stubPath());

        $useStatements = $this->buildUseStatements();
        $attributes    = $this->buildAttributes();

        return str_replace(
            ['{{ namespace }}', '{{ class }}', '{{ use_statements }}', '{{ class_attributes }}'],
            [$namespace, $class, $useStatements, $attributes],
            $stub,
        );
    }

    /**
     * Build the `use` import statements based on options.
     */
    private function buildUseStatements(): string
    {
        $uses = [];

        $http = (int) $this->option('http');
        if ($http !== 500) {
            $uses[] = 'use Isaidgitmenow\\LaravelErrors\\Attributes\\HttpCode;';
        }

        if ($this->option('report')) {
            $uses[] = 'use Isaidgitmenow\\LaravelErrors\\Attributes\\ReportTo;';
        }

        return implode("\n", $uses);
    }

    /**
     * Build the PHP 8 Attribute annotations for the class.
     */
    private function buildAttributes(): string
    {
        $lines = [];

        $http = (int) $this->option('http');
        if ($http !== 500) {
            $lines[] = "#[HttpCode({$http})]";
        }

        if ($reportOption = $this->option('report')) {
            $channels = array_map(
                fn (string $ch) => "'" . trim($ch) . "'",
                explode(',', $reportOption),
            );

            $envOption = $this->option('env');
            if ($envOption) {
                $envs = array_map(
                    fn (string $env) => "'" . trim($env) . "'",
                    explode(',', $envOption),
                );
                $lines[] = count($channels) === 1
                    ? "#[ReportTo({$channels[0]}, environments: [" . implode(', ', $envs) . "])]"
                    : "#[ReportTo([" . implode(', ', $channels) . "], environments: [" . implode(', ', $envs) . "])]";
            } else {
                $lines[] = count($channels) === 1
                    ? "#[ReportTo({$channels[0]})]"
                    : "#[ReportTo([" . implode(', ', $channels) . "])]";
            }
        }

        return empty($lines) ? '' : implode("\n", $lines) . "\n";
    }

    /**
     * Get the absolute path to the stub file, respecting user-published stubs.
     */
    private function stubPath(): string
    {
        $customStub = base_path('stubs/error.stub');

        return $this->files->exists($customStub)
            ? $customStub
            : __DIR__ . '/../../../stubs/error.stub';
    }
}
