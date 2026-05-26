<?php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Detectors;

use Illuminate\Http\Request;
use Isaidgitmenow\LaravelErrors\Contracts\ContextDetectorInterface;
use Throwable;

/**
 * Detects if the current request is inside a Filament admin panel.
 *
 * Filament runs on top of Livewire, so this detector MUST run before
 * LivewireDetector in the context pipeline to avoid misidentification.
 *
 * Detection strategy:
 * - Filament v3 sets the 'X-Livewire' header (it's Livewire-based)
 * - But Filament routes are registered under a specific path prefix
 * - We also check if the Filament facade/class is available
 */
final class FilamentDetector implements ContextDetectorInterface
{
    public function detect(Throwable $e, Request $request): bool
    {
        if (!class_exists(\Filament\Facades\Filament::class)) {
            return false;
        }

        // Check if there is an active Filament panel for the current request
        try {
            return \Filament\Facades\Filament::getCurrentPanel() !== null;
        } catch (\Throwable) {
            return false;
        }
    }
}
