<?php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Contracts;

/**
 * Marker interface for reporters that must never be wrapped with RateLimitedReporter.
 *
 * Implement this on any reporter that must fire on every exception, regardless of
 * how many times the same exception has already been reported. This is ideal for
 * local developer tooling (e.g. Xdebug, Debugbar) where missing even a single
 * notification would be confusing.
 */
interface BypassesRateLimiting
{
}
