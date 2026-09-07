<?php
// file: src/Reporters/LogReporter.php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Reporters;

use Illuminate\Support\Facades\Log;
use Isaidgitmenow\LaravelErrors\Contracts\ErrorReporterInterface;
use Isaidgitmenow\LaravelErrors\ExceptionInspector;
use Isaidgitmenow\LaravelErrors\Support\ErrorIdentity;
use Throwable;

final class LogReporter implements ErrorReporterInterface
{
    public function __construct(private readonly array $config = []) {}

    public function shouldReport(Throwable $e): bool
    {
        return true;
    }

    public function report(Throwable $e): bool
    {
        $level   = ExceptionInspector::logLevel($e);
        $message = ExceptionInspector::origin($e)->getMessage();
        $context = [
            'exception'     => ExceptionInspector::origin($e),      // F-25: Throwable → Monolog randează stack trace-ul (originalul, nu wrapper-ul)
            'error_id'      => ErrorIdentity::for($e),
            'error_code'    => ExceptionInspector::errorCode($e),
            'error_context' => ExceptionInspector::sanitizedContext($e),   // sub cheie proprie: nu suprascrie meta
        ];

        $channels = ExceptionInspector::reportToChannels($e);

        if ($channels === null) {
            Log::log($level, $message, $context);

            return true;
        }

        $configured = array_keys((array) config('logging.channels', []));

        foreach ($channels as $channel) {
            if (! in_array($channel, $configured, true)) {
                // F-12: LogManager ar degrada TĂCUT pe emergency logger. Facem zgomot pe canalul default.
                Log::log($level, $message, $context + ['laravel_errors_warning' => "Unknown log channel [{$channel}] in #[ReportTo]"]);
                continue;
            }
            Log::channel($channel)->log($level, $message, $context);
        }

        return true;
    }
}
