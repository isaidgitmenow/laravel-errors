<?php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Renderers;

use Illuminate\Http\Request;
use Isaidgitmenow\LaravelErrors\Contracts\ExceptionRendererInterface;
use Isaidgitmenow\LaravelErrors\ExceptionInspector;
use Isaidgitmenow\LaravelErrors\Support\ErrorIdentity;
use Isaidgitmenow\LaravelErrors\Support\MessageResolver;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Web renderer: tries app views (errors.{status}), then Laravel views, then package view.
 * In 2.0-2.2 this is a simple fallback; in 3.0 it renders a proper error page.
 */
final class WebRenderer implements ExceptionRendererInterface
{
    public function __construct(private readonly array $config = []) {}

    public function render(Throwable $e, Request $request): ?Response
    {
        $status  = ExceptionInspector::httpCode($e);
        $message = MessageResolver::public($e, $status, $this->config);

        $data = [
            'status'   => $status,
            'message'  => $message,
            'code'     => ExceptionInspector::errorCode($e) ?? 'HTTP_' . $status,
            'error_id' => ErrorIdentity::for($e),
            'context'  => app()->hasDebugModeEnabled() ? ExceptionInspector::sanitizedContext($e) : [],
        ];

        // Try app views first, then package views
        foreach (["errors.{$status}", "laravel-errors::error"] as $view) {
            if (view()->exists($view)) {
                return response()->view($view, $data, $status);
            }
        }

        // Ultimate fallback: minimal HTML
        return response(
            "<h1>{$status}</h1><p>{$message}</p>",
            $status,
            ['Content-Type' => 'text/html']
        );
    }
}
