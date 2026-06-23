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
        $ttl = now()->addMinutes($rateLimit->intervalInMinutes);

        // Atomic strategy: try to add the key with value 1 (the first report).
        // - add() succeeds   → first occurrence in this window → count = 1, always report.
        // - add() fails      → key already exists → increment and compare to max.
        // This avoids the add(0) + increment() two-step that could double-count on
        // non-atomic cache drivers (file, database) and is correct on Redis too.
        if (Cache::add($key, 1, $ttl)) {
            // First report in this window — always delegate.
            return $this->inner->report($e);
        }

        $count = (int) Cache::increment($key);

        if ($count > $rateLimit->max) {
            // Rate limit exceeded: suppress without stopping the pipeline.
            return true;
        }

        return $this->inner->report($e);
    }

    private function buildCacheKey(Throwable $e): string
    {
        return 'laravel-errors:rate-limit:' . md5(
            $this->inner::class . ':' . $e::class . ':' . $e->getFile() . ':' . $e->getLine()
        );
    }
}
