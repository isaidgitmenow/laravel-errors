<?php
// file: src/Mcp/ReporterMock.php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Mcp;

/**
 * Single owner of the `mock_reporters` lock file (previously hard-coded in 3 places).
 * Active ONLY in the local environment — a stray lock file must never silence production reporters.
 */
final class ReporterMock
{
    public static function path(): string
    {
        return storage_path('framework/mcp_mock_reporters.lock');
    }

    public static function isActive(): bool
    {
        return app()->environment('local') && file_exists(self::path());
    }

    public static function enable(): string
    {
        $path = self::path();

        @mkdir(dirname($path), 0775, recursive: true);

        if (@file_put_contents($path, date('c')) === false) {
            throw new \RuntimeException("mock_reporters: failed to write lock file at {$path}. Check directory permissions.");
        }

        return $path;
    }

    public static function disable(): string
    {
        $path = self::path();

        if (file_exists($path) && ! @unlink($path)) {
            throw new \RuntimeException("mock_reporters: failed to remove lock file at {$path}. Check permissions.");
        }

        return $path;
    }
}
