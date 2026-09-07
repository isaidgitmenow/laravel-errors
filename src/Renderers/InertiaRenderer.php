<?php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Renderers;

use Illuminate\Http\Request;
use Isaidgitmenow\LaravelErrors\Contracts\ExceptionRendererInterface;
use Isaidgitmenow\LaravelErrors\Exceptions\InvalidConfigurationException;
use Isaidgitmenow\LaravelErrors\ExceptionInspector;
use Isaidgitmenow\LaravelErrors\Support\ErrorIdentity;
use Isaidgitmenow\LaravelErrors\Support\MessageResolver;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Modes: page (Inertia::render), flash (back()->with()), redirect (alias for page).
 * 'props' throws InvalidConfigurationException at boot (F-07).
 */
final class InertiaRenderer implements ExceptionRendererInterface
{
    public function __construct(private readonly array $config = []) {}

    public function render(Throwable $e, Request $request): ?Response
    {
        if (! class_exists(\Inertia\Inertia::class)) {
            return null;
        }

        $status    = ExceptionInspector::httpCode($e);
        $message   = MessageResolver::public($e, $status, $this->config);
        $mode      = $this->config['inertia_mode'] ?? 'page';
        $component = $this->config['inertia_error_component'] ?? 'ErrorPage';

        $payload = [
            'status'   => $status,
            'message'  => $message,
            'code'     => ExceptionInspector::errorCode($e) ?? 'HTTP_' . $status,
            'error_id' => ErrorIdentity::for($e),
        ];

        return match ($mode) {
            'page', 'redirect' => \Inertia\Inertia::render($component, $payload)
                ->toResponse($request)
                ->setStatusCode($status),

            'flash' => $this->flash($request, $payload, $status),

            default => null,
        };
    }

    private function flash(Request $request, array $payload, int $status): Response
    {
        try {
            return back()->with('error', $payload)->setStatusCode($status);
        } catch (Throwable) {
            // No previous URL or session — fallback
            return response()->redirectTo('/')->with('error', $payload)->setStatusCode(302);
        }
    }
}
