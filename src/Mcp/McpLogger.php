<?php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Mcp;

use Throwable;

/**
 * Writes structured JSONL error logs to storage/framework/mcp/.errors-mcp.jsonl.
 *
 * Design constraints:
 * - Local environment only: caller is responsible for the env check.
 * - Never throws: all file I/O is wrapped in error suppression.
 * - Atomic writes: FILE_APPEND | LOCK_EX prevents corruption under concurrency.
 * - Hidden path: avoids Datadog / Filebeat ingestion from storage/logs/.
 * - Smart stack traces: top-5 app frames, no /vendor/, relative to base_path().
 * - Log rotation: renames to .jsonl.old when the file exceeds 2 MB.
 */
final class McpLogger
{
    private const MAX_BYTES     = 2 * 1024 * 1024; // 2 MB
    private const MAX_FRAMES    = 5;
    private const LOG_DIR       = 'framework/mcp';
    private const LOG_FILE      = '.errors-mcp.jsonl';
    private const LOG_FILE_OLD  = '.errors-mcp.jsonl.old';

    /**
     * Log the exception as a JSONL line.
     * Silently suppresses any I/O errors so this never crashes the application.
     */
    public static function log(Throwable $e): void
    {
        $path = static::logPath();

        static::ensureDirectory($path);
        static::maybeRotate($path);

        $line = json_encode(static::buildEntry($e), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

        set_error_handler(static fn () => true); // suppress E_WARNING
        try {
            file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
            @chmod($path, 0666);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Build a single log entry array for the given exception.
     *
     * @return array<string, mixed>
     */
    private static function buildEntry(Throwable $e): array
    {
        return [
            'timestamp'   => now()->toIso8601String(),
            'class'       => $e::class,
            'message'     => $e->getMessage(),
            'request_url' => static::resolveRequestUrl(),
            'context'     => static::resolveContext($e),
            'stack_trace' => static::buildStackTrace($e),
        ];
    }

    /**
     * Resolve the current request URL or job name for the log entry.
     */
    private static function resolveRequestUrl(): ?string
    {
        try {
            if (app()->runningInConsole()) {
                // Attempt to detect queued job name from the running command
                return 'cli:' . implode(' ', array_slice($_SERVER['argv'] ?? [], 1));
            }

            return request()->fullUrl();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Resolve and sanitize #[WithContext] data from the exception.
     * Sensitive keys (passwords, tokens, etc.) are redacted using the same
     * sanitize list as the rest of the package, so the JSONL log never
     * contains plaintext credentials.
     *
     * @return array<string, mixed>
     */
    private static function resolveContext(Throwable $e): array
    {
        try {
            if (class_exists(\Isaidgitmenow\LaravelErrors\ExceptionInspector::class)) {
                $context  = \Isaidgitmenow\LaravelErrors\ExceptionInspector::context($e);
                $sanitize = (array) config('errors.sanitize', []);

                return \Isaidgitmenow\LaravelErrors\Support\DataSanitizer::sanitize($context, $sanitize);
            }
        } catch (Throwable) {
            // fall through
        }

        return [];
    }

    /**
     * Build a filtered, relative stack trace.
     *
     * Rules:
     * - Drop all /vendor/ frames.
     * - Keep only the top MAX_FRAMES application frames.
     * - Strip base_path() prefix so paths are relative (e.g. app/Http/...).
     *
     * @return array<int, array<string, mixed>>
     */
    private static function buildStackTrace(Throwable $e): array
    {
        $basePath  = rtrim(base_path(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $frames    = $e->getTrace();
        
        // Add the frame where the exception was actually thrown
        array_unshift($frames, [
            'file'     => $e->getFile(),
            'line'     => $e->getLine(),
            'function' => '{main}',
        ]);

        $appFrames = [];

        foreach ($frames as $frame) {
            $file = $frame['file'] ?? null;

            // Skip frames without a file or inside vendor
            if ($file === null || str_contains($file, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)) {
                continue;
            }

            $appFrames[] = [
                'file'     => str_replace($basePath, '', $file),
                'line'     => $frame['line'] ?? null,
                'function' => isset($frame['class'])
                    ? $frame['class'] . ($frame['type'] ?? '::') . $frame['function']
                    : $frame['function'],
            ];

            if (count($appFrames) >= self::MAX_FRAMES) {
                break;
            }
        }

        return $appFrames;
    }

    /**
     * Rotate the log file if it exceeds the size limit.
     */
    private static function maybeRotate(string $path): void
    {
        set_error_handler(static fn () => true);
        try {
            if (file_exists($path) && filesize($path) >= self::MAX_BYTES) {
                $oldPath = dirname($path) . DIRECTORY_SEPARATOR . self::LOG_FILE_OLD;
                rename($path, $oldPath);
            }
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Ensure the log directory exists and is writable.
     */
    private static function ensureDirectory(string $path): void
    {
        $dir = dirname($path);

        set_error_handler(static fn () => true);
        try {
            if (! is_dir($dir)) {
                mkdir($dir, 0775, recursive: true);
            }
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Resolve the absolute path to the JSONL log file.
     */
    public static function logPath(): string
    {
        return storage_path(self::LOG_DIR . DIRECTORY_SEPARATOR . self::LOG_FILE);
    }
}
