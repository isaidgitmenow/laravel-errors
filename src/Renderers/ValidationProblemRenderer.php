<?php
// file: src/Renderers/ValidationProblemRenderer.php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Renderers;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Isaidgitmenow\LaravelErrors\Support\ErrorIdentity;
use Isaidgitmenow\LaravelErrors\Support\HandlerSlots;
use Symfony\Component\HttpFoundation\Response;

/**
 * Unifies 422 validation errors into Problem Details format.
 * Only active when problem_details.enabled && problem_details.unify_validation are true.
 */
final class ValidationProblemRenderer
{
    public function __construct(private readonly array $config = []) {}

    public function render(ValidationException $e, Request $request): ?Response
    {
        // Non-JSON: let Laravel redirect with errors in session
        if (! app(HandlerSlots::class)->shouldRenderJson($request, $e)) {
            return null;
        }

        // Honour $e->response (withResponse(), FormRequest::failedValidation)
        if ($e->response !== null) {
            return $e->response;
        }

        $status = $e->status;

        return response()->json([
            'type'     => 'about:blank',
            'title'    => Response::$statusTexts[$status] ?? 'Unprocessable Content',
            'status'   => $status,
            'detail'   => $e->getMessage(),
            'instance' => $request->getRequestUri(),
            'code'     => 'VALIDATION_FAILED',
            'error_id' => ErrorIdentity::for($e),
            'errors'   => $e->errors(),
        ], $status, ['Content-Type' => 'application/problem+json']);
    }
}
