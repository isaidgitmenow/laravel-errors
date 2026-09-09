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
 * Web renderer: tries app views (errors.{status}), then Laravel views (errors::{status}),
 * then package view (laravel-errors::error), then returns null (let Laravel handle it).
 *
 * N-03: $message is escaped in fallback HTML to prevent XSS. The preferred path is to
 * return null when no views exist so Laravel's own error page renders.
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

        // 1. App views first (published error pages)
        if (view()->exists("errors.{$status}")) {
            return response()->view("errors.{$status}", $data, $status);
        }

        // 2. Laravel's default error views (after Handler::registerErrorViewPaths())
        if (view()->exists("errors::{$status}")) {
            return response()->view("errors::{$status}", $data, $status);
        }

        // 3. Package view
        /** @phpstan-ignore method.impossibleType (Larastan cannot resolve package-namespaced views statically) */
        if (view()->exists('laravel-errors::error')) {
            return response()->view('laravel-errors::error', $data, $status);
        }

        // 4. Return null → let Laravel handle it with its own error pages
        // This is safer than rendering raw HTML, especially for 404/403/419/405.
        return null;
    }
}
