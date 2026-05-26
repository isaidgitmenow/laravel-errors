<?php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Contracts;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

interface ExceptionRendererInterface
{
    /**
     * Render the exception into an HTTP response appropriate for this context.
     *
     * Return null to fall through to the next renderer or Laravel's default handler.
     */
    public function render(Throwable $e, Request $request): ?Response;
}
