<?php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Detectors;

use Illuminate\Http\Request;
use Isaidgitmenow\LaravelErrors\Contracts\ContextDetectorInterface;
use Throwable;

/**
 * Detects if the current request originates from a Livewire component lifecycle call.
 *
 * Livewire sends AJAX requests with the 'X-Livewire' header.
 */
final class LivewireDetector implements ContextDetectorInterface
{
    public function detect(Throwable $e, Request $request): bool
    {
        if (!class_exists(\Livewire\Livewire::class)) {
            return false;
        }

        return $request->hasHeader('X-Livewire');
    }
}
