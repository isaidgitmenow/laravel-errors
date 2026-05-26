<?php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Renderers;

use Illuminate\Http\Request;
use Isaidgitmenow\LaravelErrors\Contracts\ExceptionRendererInterface;
use Isaidgitmenow\LaravelErrors\ExceptionInspector;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Renders exceptions for API (JSON) contexts.
 *
 * Supports a configurable 'json_formatter' Closure in config/errors.php.
 * Defaults to a Laravel-style {message, errors} response structure.
 */
final class ApiRenderer implements ExceptionRendererInterface
{
    public function __construct(
        private readonly array $config = [],
    ) {}

    public function render(Throwable $e, Request $request): ?Response
    {
        $statusCode = ExceptionInspector::httpCode($e);
        $message = ExceptionInspector::translatedMessage($e) ?? $e->getMessage();

        $formatter = $this->config['json_formatter'] ?? null;

        if ($formatter instanceof \Closure) {
            $payload = $formatter($e, $request);
        } else {
            $payload = $this->defaultPayload($e, $message);
        }

        return response()->json($payload, $statusCode);
    }

    private function defaultPayload(Throwable $e, string $message): array
    {
        return [
            'message' => $message,
            'errors'  => [],
        ];
    }
}
