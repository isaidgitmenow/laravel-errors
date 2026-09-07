<?php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Renderers;

use Illuminate\Http\Request;
use Isaidgitmenow\LaravelErrors\Contracts\ExceptionRendererInterface;
use Isaidgitmenow\LaravelErrors\ExceptionInspector;
use Isaidgitmenow\LaravelErrors\Support\CallableResolver;
use Isaidgitmenow\LaravelErrors\Support\ErrorIdentity;
use Isaidgitmenow\LaravelErrors\Support\MessageResolver;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * F-16: JSON doar pentru X-Livewire/ajax()/wantsJson(), altfel null → pagina HTML.
 */
final class FilamentRenderer implements ExceptionRendererInterface
{
    public function __construct(private readonly array $config = []) {}

    public function render(Throwable $e, Request $request): ?Response
    {
        $status  = ExceptionInspector::httpCode($e);
        $message = MessageResolver::public($e, $status, $this->config);

        if (($handler = CallableResolver::resolve($this->config['filament_handler'] ?? null, 'filament_handler')) !== null) {
            $handler($e, $request);
        } else {
            // Default: Filament notification
            try {
                if (class_exists(\Filament\Notifications\Notification::class)) {
                    \Filament\Notifications\Notification::make()
                        ->title($message)
                        ->danger()
                        ->send();
                }
            } catch (Throwable) {
                // Notification requires session; may fail in some contexts
            }
        }

        // F-16: JSON only for AJAX/Livewire requests, otherwise let HTML page render
        if (! $request->hasHeader('X-Livewire') && ! $request->ajax() && ! $request->wantsJson()) {
            return null;
        }

        return response()->json([
            'message'  => $message,
            'errors'   => [],
            'code'     => ExceptionInspector::errorCode($e) ?? 'HTTP_' . $status,
            'error_id' => ErrorIdentity::for($e),
        ], $status);
    }
}
