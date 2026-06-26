<?php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Renderers;

use Illuminate\Http\Request;
use Isaidgitmenow\LaravelErrors\Contracts\ExceptionRendererInterface;
use Isaidgitmenow\LaravelErrors\ExceptionInspector;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Renders exceptions for Inertia.js contexts.
 *
 * Supports two modes (configured via config/errors.php 'inertia_mode'):
 * - 'props': Share error as a shared prop accessible in all Inertia pages.
 * - 'redirect': Render a dedicated error component via Inertia::render().
 */
final class InertiaRenderer implements ExceptionRendererInterface
{
    public function __construct(
        private readonly array $config = [],
    ) {}

    public function render(Throwable $e, Request $request): ?Response
    {
        if (!class_exists(\Inertia\Inertia::class)) {
            return null;
        }

        $message = ExceptionInspector::translatedMessage($e) ?? $e->getMessage();
        $statusCode = ExceptionInspector::httpCode($e);
        $mode = $this->config['inertia_mode'] ?? 'props';

        if ($mode === 'redirect') {
            $component = $this->config['inertia_error_component'] ?? 'ErrorPage';

            return \Inertia\Inertia::render($component, [
                'status'  => $statusCode,
                'message' => $message,
            ])->toResponse($request)->setStatusCode($statusCode);
        }

        // Default: 'props' mode - share error as Inertia shared props
        \Inertia\Inertia::share([
            'error' => [
                'status'  => $statusCode,
                'message' => $message,
            ],
        ]);

        // Return a redirect to trigger a re-render of the current page with the
        // error shared as Inertia props. Redirects MUST use 3xx status codes per
        // HTTP spec — the actual error status is already in the shared props above.
        // Using a non-3xx code on a RedirectResponse produces undefined behavior
        // in HTTP clients (blank pages, ignored Location headers).
        // Wrap in try/catch because back()->withInput() requires an active session,
        // which may be absent in stateless API routes or certain test environments.
        try {
            return back()->withInput();
        } catch (\Throwable) {
            return new \Illuminate\Http\RedirectResponse(
                $request->fullUrl(),
                302,
            );
        }
    }

}
