<?php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Detectors;

use Illuminate\Http\Request;
use Isaidgitmenow\LaravelErrors\Contracts\ContextDetectorInterface;
use Throwable;

/**
 * Detects if the current request expects a JSON response (API clients).
 *
 * Matches requests that:
 * - Have Accept: application/json header
 * - Or explicitly call for JSON (wantsJson())
 * - Or are AJAX requests without Inertia/Livewire headers
 */
final class ApiDetector implements ContextDetectorInterface
{
    public function detect(Throwable $e, Request $request): bool
    {
        return $request->wantsJson() || $request->is('api/*');
    }
}
