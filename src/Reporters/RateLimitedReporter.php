<?php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Reporters;

use Illuminate\Support\Facades\Cache;
use Isaidgitmenow\LaravelErrors\Contracts\ErrorReporterInterface;
use Isaidgitmenow\LaravelErrors\ExceptionInspector;
use Throwable;

/**
 * Decorator that wraps any reporter with rate-limiting behaviour.
 *
 * It reads #[RateLimit(max: 10, intervalInMinutes: 5)] from the exception class.
 * If the exception has been reported more than `max` times in the interval,
 * this decorator suppresses further reports to prevent quota exhaustion
 * (e.g., when a database goes down and triggers thousands of exceptions/minute).
 */
final class RateLimitedReporter implements ErrorReporterInterface
{
    public function __construct(
        private readonly ErrorReporterInterface $inner,
    ) {}

    public function shouldReport(Throwable $e): bool
    {
        return $this->inner->shouldReport($e);
    }

    public function report(Throwable $e): bool
    {
        $rateLimit = ExceptionInspector::rateLimit($e);

        if ($rateLimit === null) {
            return $this->inner->report($e);
        }

        $key = $this->buildCacheKey($e);
        $count = (int) Cache::get($key, 0);

        if ($count >= $rateLimit->max) {
            // Rate limit exceeded: render the response but skip reporting
            return true;
        }

        Cache::put($key, $count + 1, now()->addMinutes($rateLimit->intervalInMinutes));

        return $this->inner->report($e);
    }

    private function buildCacheKey(Throwable $e): string
    {
        return 'laravel-errors:rate-limit:' . md5(
            $e::class . $e->getFile() . $e->getLine()
        );
    }
}
