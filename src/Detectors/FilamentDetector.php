<?php
// file: src/Detectors/FilamentDetector.php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Detectors;

use Illuminate\Http\Request;
use Isaidgitmenow\LaravelErrors\Contracts\ContextDetectorInterface;
use Isaidgitmenow\LaravelErrors\Contracts\InteractiveContextDetector;
use Throwable;

/**
 * F-16: în Filament v3 panoul default e „current" pe ORICE request,
 * deci getCurrentPanel() !== null nu spune nimic. Verificăm prefixul panoului pe request.
 */
final class FilamentDetector implements ContextDetectorInterface, InteractiveContextDetector
{
    public function detect(Throwable $e, Request $request): bool
    {
        if (! class_exists(\Filament\Facades\Filament::class)) {
            return false;
        }

        try {
            $panel = \Filament\Facades\Filament::getCurrentPanel();
            if ($panel === null) {
                return false;
            }

            $path = trim((string) $panel->getPath(), '/');

            return $path === ''
                ? $request->hasHeader('X-Livewire')
                : $request->is($path, $path . '/*');
        } catch (Throwable) {
            return false;
        }
    }
}
