<?php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Renderers;

use Illuminate\Http\Request;
use Isaidgitmenow\LaravelErrors\Contracts\ExceptionRendererInterface;
use Isaidgitmenow\LaravelErrors\ExceptionInspector;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Renders exceptions for Filament panel requests.
 *
 * Uses Filament's native Notification system by default for a seamless
 * visual experience within the admin panel.
 *
 * Supports a configurable 'filament_handler' Closure in config/errors.php
 * for custom notification logic.
 */
final class FilamentRenderer implements ExceptionRendererInterface
{
    public function __construct(
        private readonly array $config = [],
    ) {}

    public function render(Throwable $e, Request $request): ?Response
    {
        $message = ExceptionInspector::translatedMessage($e) ?? $e->getMessage();
        $statusCode = ExceptionInspector::httpCode($e);

        $handler = $this->config['filament_handler'] ?? null;

        if ($handler instanceof \Closure) {
            $result = $handler($e, $request);
            // If the Closure returns a Response, honour it directly.
            if ($result instanceof Response) {
                return $result;
            }
        } elseif ($this->isFilamentNotificationAvailable()) {
            // Use native Filament Notification - dynamic to avoid hard dependency
            \Filament\Notifications\Notification::make()
                ->title(__('Error'))
                ->body($message)
                ->danger()
                ->send();
        }

        // Return a Livewire-compatible JSON response (Filament is Livewire-based)
        return response()->json([
            'message' => $message,
        ], $statusCode);
    }


    private function isFilamentNotificationAvailable(): bool
    {
        return class_exists(\Filament\Notifications\Notification::class);
    }
}
