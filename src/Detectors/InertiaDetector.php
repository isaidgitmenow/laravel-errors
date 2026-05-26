<?php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Detectors;

use Illuminate\Http\Request;
use Isaidgitmenow\LaravelErrors\Contracts\ContextDetectorInterface;
use Throwable;

/**
 * Detects if the current request is an Inertia.js navigation request.
 *
 * Inertia sends all navigation requests with the 'X-Inertia' header.
 */
final class InertiaDetector implements ContextDetectorInterface
{
    public function detect(Throwable $e, Request $request): bool
    {
        if (!class_exists(\Inertia\Inertia::class)) {
            return false;
        }

        return $request->hasHeader('X-Inertia');
    }
}
