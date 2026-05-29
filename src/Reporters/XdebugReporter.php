<?php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Reporters;

use Isaidgitmenow\LaravelErrors\Contracts\BypassesRateLimiting;
use Isaidgitmenow\LaravelErrors\Contracts\ErrorReporterInterface;
use Isaidgitmenow\LaravelErrors\ExceptionInspector;
use Isaidgitmenow\LaravelErrors\Support\DataSanitizer;
use Throwable;

/**
 * Sends #[WithContext] exception payload to the IDE via Xdebug's notify channel.
 *
 * This reporter is silent in production and has zero impact when Xdebug is absent.
 * It bypasses rate limiting so the IDE popup fires on every refresh during debugging.
 *
 * Requirements:
 *  - APP_DEBUG=true
 *  - Xdebug 3 installed and listening (xdebug.start_with_request=yes or trigger)
 *  - config('errors.enrich_xdebug') === true
 *  - The exception must carry #[WithContext] data (otherwise the notification is empty and useless)
 */
final class XdebugReporter implements ErrorReporterInterface, BypassesRateLimiting
{
    public function __construct(
        private readonly array $config = [],
    ) {}

    public function shouldReport(Throwable $e): bool
    {
        if (! app()->hasDebugModeEnabled()) {
            return false;
        }

        if (! ($this->config['enrich_xdebug'] ?? true)) {
            return false;
        }

        if (! function_exists('xdebug_notify')) {
            return false;
        }

        return ! empty(ExceptionInspector::context($e));
    }

    public function report(Throwable $e): bool
    {
        $context = ExceptionInspector::context($e);

        $sanitized = DataSanitizer::sanitize(
            $context,
            $this->config['sanitize'] ?? [],
        );

        xdebug_notify(['LaravelErrors Context for ' . class_basename($e) => $sanitized]);

        return true;
    }
}
