<?php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Reporters;

use Isaidgitmenow\LaravelErrors\Contracts\BypassesRateLimiting;
use Isaidgitmenow\LaravelErrors\Contracts\ErrorReporterInterface;
use Isaidgitmenow\LaravelErrors\Contracts\ReportsIgnoredExceptions;
use Isaidgitmenow\LaravelErrors\ExceptionInspector;
use Isaidgitmenow\LaravelErrors\Support\DataSanitizer;
use Throwable;

/**
 * Reports exceptions to Laravel Debugbar when available.
 *
 * This reporter also sends #[WithContext] data as debug messages,
 * giving developers full exception context without dd() calls.
 * Context data is sanitized before display to redact sensitive values.
 *
 * It is always called for #[DontReport] exceptions (see ErrorManager),
 * so developers still see suppressed exceptions locally.
 *
 * Requires: barryvdh/laravel-debugbar
 */
final class DebugbarReporter implements ErrorReporterInterface, BypassesRateLimiting, ReportsIgnoredExceptions
{
    public function __construct(
        private readonly array $config = [],
    ) {}

    public function shouldReport(Throwable $e): bool
    {
        return $this->isDebugbarAvailable() && app()->hasDebugModeEnabled();
    }

    public function report(Throwable $e): bool
    {
        if (!$this->isDebugbarAvailable()) {
            return true;
        }

        $debugbar = \Barryvdh\Debugbar\Facades\Debugbar::getFacadeRoot();

        // Add the exception to the Exceptions tab
        $debugbar->addThrowable($e);

        // Add #[WithContext] data to the Messages tab (sanitized)
        $context = ExceptionInspector::context($e);
        if (!empty($context)) {
            $sanitized = DataSanitizer::sanitize(
                $context,
                $this->config['sanitize'] ?? [],
            );
            $debugbar->addMessage(
                '[' . class_basename($e) . '] Context: ' . json_encode($sanitized, JSON_PRETTY_PRINT),
                'error'
            );
        }

        return true;
    }

    private function isDebugbarAvailable(): bool
    {
        return class_exists(\Barryvdh\Debugbar\Facades\Debugbar::class);
    }
}
