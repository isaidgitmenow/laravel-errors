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

final class LivewireRenderer implements ExceptionRendererInterface
{
    public function __construct(private readonly array $config = []) {}

    public function render(Throwable $e, Request $request): ?Response
    {
        $status  = ExceptionInspector::httpCode($e);
        $message = MessageResolver::public($e, $status, $this->config);

        if (($handler = CallableResolver::resolve($this->config['livewire_handler'] ?? null, 'livewire_handler')) !== null) {
            $handler($e, $request);
        }

        return response()->json([
            'message'  => $message,
            'errors'   => [],
            'code'     => ExceptionInspector::errorCode($e) ?? 'HTTP_' . $status,
            'error_id' => ErrorIdentity::for($e),
        ], $status);
    }
}
