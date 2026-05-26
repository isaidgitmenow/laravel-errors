<?php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Reporters;

use Illuminate\Support\Facades\Log;
use Isaidgitmenow\LaravelErrors\Contracts\ErrorReporterInterface;
use Isaidgitmenow\LaravelErrors\ExceptionInspector;
use Isaidgitmenow\LaravelErrors\Support\DataSanitizer;
use Throwable;

/**
 * Reports exceptions through Laravel's Log system.
 *
 * Respects #[ReportTo('channel')] to route logs to specific channels.
 * Enriches log entries with context data extracted via #[WithContext].
 */
final class LogReporter implements ErrorReporterInterface
{
    public function __construct(
        private readonly array $config = [],
    ) {}

    public function shouldReport(Throwable $e): bool
    {
        return true;
    }

    public function report(Throwable $e): bool
    {
        $context = $this->buildContext($e);
        $channels = ExceptionInspector::reportToChannels($e);

        if ($channels !== null) {
            foreach ($channels as $channel) {
                Log::channel($channel)->error($e->getMessage(), $context);
            }
        } else {
            Log::error($e->getMessage(), $context);
        }

        return true;
    }

    private function buildContext(Throwable $e): array
    {
        $context = ExceptionInspector::context($e);

        // FIX #4: Sanitize the context data before writing to log files.
        $sanitizedContext = DataSanitizer::sanitize(
            $context,
            $this->config['sanitize'] ?? []
        );

        return array_merge(
            [
                'exception' => $e::class,
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
            ],
            $sanitizedContext,
        );
    }
}
