<?php
// file: src/Reporters/RateLimitedReporter.php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Reporters;

use Illuminate\Support\Facades\Cache;
use Isaidgitmenow\LaravelErrors\Contracts\ErrorReporterInterface;
use Isaidgitmenow\LaravelErrors\ExceptionInspector;
use Isaidgitmenow\LaravelErrors\Support\RateLimitKey;
use Throwable;

/**
 * Rămâne alături de throttle() nativ pentru că e singurul strat cu granularitate PER REPORTER
 * (BypassesRateLimiting pentru Debugbar/Xdebug; AuditReporter nu se limitează niciodată).
 */
final class RateLimitedReporter implements ErrorReporterInterface
{
    public function __construct(private readonly ErrorReporterInterface $inner) {}

    public function shouldReport(Throwable $e): bool
    {
        return $this->inner->shouldReport($e);
    }

    public function report(Throwable $e): bool
    {
        $limit = ExceptionInspector::rateLimit($e);
        if ($limit === null) {
            return $this->inner->report($e);
        }

        try {
            $key = RateLimitKey::for($e, $limit->by, $this->inner::class);
            $ttl = now()->addMinutes($limit->intervalInMinutes);

            if (Cache::add($key, 1, $ttl)) {
                return $this->inner->report($e);
            }
            if ((int) Cache::increment($key) > $limit->max) {
                return true;   // suprimat, fără a opri pipeline-ul
            }
        } catch (Throwable) {
            // F-27: cache indisponibil → nu putem limita → NU suprimăm. Fail-open.
        }

        return $this->inner->report($e);
    }
}
