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
     * N-07: Accepts validated http, channels, envs — no longer reads options directly.
     */
    private function buildStub(string $namespace, string $class, int $http = 500, array $channels = [], array $envs = []): string
    {
        $stub = $this->files->get($this->stubPath());

        $useStatements = $this->buildUseStatements($http, $channels);
        $attributes    = $this->buildAttributes($http, $channels, $envs);

        return str_replace(
            ['{{ namespace }}', '{{ class }}', '{{ use_statements }}', '{{ class_attributes }}'],
            [$namespace, $class, $useStatements, $attributes],
            $stub,
        );
    }

    /**
     * Build the `use` import statements based on options.
     */
    private function buildUseStatements(int $http, array $channels): string
    {
        $uses = [];

        if ($http !== 500) {
            $uses[] = 'use Isaidgitmenow\\LaravelErrors\\Attributes\\HttpCode;';
        }

        if ($channels !== []) {
            $uses[] = 'use Isaidgitmenow\\LaravelErrors\\Attributes\\ReportTo;';
        }

        return empty($uses) ? '' : "\n" . implode("\n", $uses);
    }

    /**
     * Build the PHP 8 Attribute annotations for the class.
     * N-07: Uses var_export() for channel/env values instead of string concatenation
     * to prevent PHP injection through crafted --env values.
     */
    private function buildAttributes(int $http, array $channels, array $envs): string
    {
        $lines = [];

        if ($http !== 500) {
            $lines[] = "#[HttpCode({$http})]";
        }

        if ($channels !== []) {
            $channelArgs = array_map(fn (string $v) => var_export($v, true), $channels);
            $envArgs     = array_map(fn (string $v) => var_export($v, true), $envs);

            if ($envs !== []) {
                $lines[] = count($channelArgs) === 1
                    ? "#[ReportTo({$channelArgs[0]}, environments: [" . implode(', ', $envArgs) . "])]"
                    : "#[ReportTo([" . implode(', ', $channelArgs) . "], environments: [" . implode(', ', $envArgs) . "])]";
            } else {
                $lines[] = count($channelArgs) === 1
                    ? "#[ReportTo({$channelArgs[0]})]"
                    : "#[ReportTo([" . implode(', ', $channelArgs) . "])]";
            }
        }

        return empty($lines) ? '' : "\n" . implode("\n", $lines) . "\n";
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
