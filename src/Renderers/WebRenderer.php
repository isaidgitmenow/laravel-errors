<?php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Renderers;

use Illuminate\Http\Request;
use Isaidgitmenow\LaravelErrors\Contracts\ExceptionRendererInterface;
use Isaidgitmenow\LaravelErrors\ExceptionInspector;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Renders exceptions for standard web (Blade) requests.
 *
 * Returns null to fall through to Laravel's default web error pages
 * (resources/views/errors/{code}.blade.php), which is the most
 * standard and expected behavior for web requests.
 *
 * This renderer exists mainly to allow overriding in the future or
 * to serve as the hook for custom Blade error views.
 */
final class WebRenderer implements ExceptionRendererInterface
{
    public function __construct(
        private readonly array $config = [],
    ) {}

    public function render(Throwable $e, Request $request): ?Response
    {
        // Fall through to Laravel's default error page rendering.
        // Laravel will look for resources/views/errors/{status_code}.blade.php
        // and fall back to its own error pages if not found.
        return null;
    }
}
