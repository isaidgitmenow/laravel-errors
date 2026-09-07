<?php
// file: src/Renderers/ApiRenderer.php  — stare finală 2.2

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Renderers;

use Illuminate\Http\Request;
use Isaidgitmenow\LaravelErrors\Contracts\ExceptionRendererInterface;
use Isaidgitmenow\LaravelErrors\ExceptionInspector;
use Isaidgitmenow\LaravelErrors\Support\CallableResolver;
use Isaidgitmenow\LaravelErrors\Support\ErrorIdentity;
use Isaidgitmenow\LaravelErrors\Support\MessageResolver;
use Isaidgitmenow\LaravelErrors\Support\ProblemDetailsFormatter;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class ApiRenderer implements ExceptionRendererInterface
{
    public function __construct(private readonly array $config = []) {}

    public function render(Throwable $e, Request $request): ?Response
    {
        $status  = ExceptionInspector::httpCode($e);
        $message = MessageResolver::public($e, $status, $this->config);

        if (($formatter = CallableResolver::resolve($this->config['json_formatter'] ?? null, 'json_formatter')) !== null) {
            return response()->json($formatter($e, $request), $status);
        }

        if ($this->config['problem_details']['enabled'] ?? false) {
            $payload = app(ProblemDetailsFormatter::class)->format($e, $request, $status, $message);
            if ($this->config['problem_details']['legacy_message'] ?? true) {
                $payload['message'] = $message;
            }

            return response()->json($payload, $status, ['Content-Type' => 'application/problem+json']);
        }

        // Forma legacy — `errors: []` rămâne: clienții fac response.errors.length.
        return response()->json([
            'message'  => $message,
            'errors'   => [],
            'code'     => ExceptionInspector::errorCode($e) ?? 'HTTP_' . $status,
            'error_id' => ErrorIdentity::for($e),
        ], $status);
    }
}
