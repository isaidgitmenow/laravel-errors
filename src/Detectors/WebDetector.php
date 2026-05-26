<?php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Detectors;

use Illuminate\Http\Request;
use Isaidgitmenow\LaravelErrors\Contracts\ContextDetectorInterface;
use Throwable;

/**
 * Fallback detector for standard web (HTML) requests.
 *
 * This should always be last in the context pipeline,
 * as it acts as a catch-all for any HTTP request that
 * wasn't matched by a more specific detector.
 */
final class WebDetector implements ContextDetectorInterface
{
    public function detect(Throwable $e, Request $request): bool
    {
        return true; // Always matches - must be last in pipeline
    }
}
