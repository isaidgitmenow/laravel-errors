<?php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Contracts;

/**
 * Marker interface for context detectors that represent interactive SPA/component contexts.
 *
 * When a detector implements this interface, the ErrorManager will NOT yield to Ignition
 * in debug mode, because interactive frameworks (Livewire, Inertia, etc.) need partial
 * renders rather than a full-page Ignition error screen.
 *
 * Third-party packages can implement this interface on their own detectors
 * (e.g. for HTMX or Turbo) without modifying the package's core code (OCP).
 */
interface InteractiveContextDetector {}
