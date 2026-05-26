<?php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Renderers;

use Illuminate\Http\Request;
use Isaidgitmenow\LaravelErrors\Contracts\ExceptionRendererInterface;
use Isaidgitmenow\LaravelErrors\ExceptionInspector;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Renders exceptions for Livewire component requests.
 *
 * Supports a configurable 'livewire_handler' Closure in config/errors.php.
 * The Closure receives the exception and request, and is responsible for
 * surfacing the error to the user (e.g. session flash, dispatching events).
 *
 * Falls back to a JSON error response consumable by Livewire's error handling.
 */
final class LivewireRenderer implements ExceptionRendererInterface
{
    public function __construct(
        private readonly array $config = [],
    ) {}

    public function render(Throwable $e, Request $request): ?Response
    {
        $message = ExceptionInspector::translatedMessage($e) ?? $e->getMessage();
        $statusCode = ExceptionInspector::httpCode($e);

        $handler = $this->config['livewire_handler'] ?? null;

        if ($handler instanceof \Closure) {
            $handler($e, $request);
        }

        // Livewire expects a JSON response with the error
        return response()->json([
            'message' => $message,
        ], $statusCode);
    }
}
