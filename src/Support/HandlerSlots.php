<?php
// file: src/Support/HandlerSlots.php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Support;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Isaidgitmenow\LaravelErrors\Contracts\ErrorManagerInterface;
use Isaidgitmenow\LaravelErrors\Events\ExceptionRendered;
use Isaidgitmenow\LaravelErrors\ExceptionInspector;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Handler::respondUsing() și Handler::shouldRenderJsonWhen() sunt câte UN slot: ultimul apel câștigă, tăcut.
 * Pachetul deține sloturile și compune: pipeline-ul intern + închiderile înregistrate de aplicație.
 */
final class HandlerSlots
{
    /** @var list<Closure(Response, Throwable, Request): ?Response> */
    private array $respond = [];

    /** @var list<Closure(Request, Throwable): ?bool> */
    private array $json = [];

    /** Config prin Repository (citire lazy — F-21: testele fac config()->set() după ce Handler-ul e rezolvat). */
    public function __construct(
        private readonly Repository $config,
        private readonly Dispatcher $events,
    ) {}

    private function cfg(string $key, mixed $default = null): mixed
    {
        return $this->config->get("errors.{$key}", $default);
    }

    private function dispatch(object $event): void
    {
        try {
            $this->events->dispatch($event);
        } catch (Throwable $failure) {
            CriticalLog::once('laravel-errors event listener failed', $failure, ['event' => $event::class]);
        }
    }

    public function onRespond(Closure $callback): void
    {
        $this->respond[] = $callback;
    }

    public function onRenderJson(Closure $callback): void
    {
        $this->json[] = $callback;
    }

    /** Marker intern pus de ErrorManager::render() pe răspunsurile proprii, ca să nu emitem ExceptionRendered de două ori. */
    public const RENDERED_HEADER = 'X-Laravel-Errors-Rendered';

    public function runRespond(Response $response, Throwable $e, Request $request): Response
    {
        // Răspunsurile construite de utilizator (HttpResponseException, ValidationException) nu se ating.
        $ours = ! app(ErrorManagerInterface::class)->isPassThrough($e);

        if ($ours && $this->cfg('error_id.enabled', true)) {
            $response = $this->injectErrorId($response, $e);
        }
        if ($ours && ($retry = ExceptionInspector::retryAfter($e)) !== null && ! $response->headers->has('Retry-After')) {
            $response->headers->set('Retry-After', (string) $retry);   // acoperă toate căile de randare, nu doar clasele mapate
        }

        if ($ours && ! $response->headers->has(self::RENDERED_HEADER)) {
            // Laravel a randat (renderele noastre au întors null / n-au fost atinse): emitem noi evenimentul.
            $this->dispatch(new ExceptionRendered(
                exception: ExceptionInspector::origin($e),
                errorId:   ErrorIdentity::for($e),
                response:  $response,
                renderer:  null,
                context:   'laravel',
            ));
        }
        $response->headers->remove(self::RENDERED_HEADER);

        foreach ($this->respond as $cb) {
            $response = $cb($response, $e, $request) ?? $response;
        }

        return $response;
    }

    public function shouldRenderJson(Request $request, Throwable $e): bool
    {
        foreach ($this->json as $cb) {
            $decision = $cb($request, $e);
            if ($decision !== null) {
                return (bool) $decision;   // prima închidere care decide, câștigă
            }
        }

        if ($request->expectsJson()) {
            return true;
        }

        foreach ((array) $this->cfg('api_prefixes', ['api']) as $prefix) {
            $prefix = trim((string) $prefix, '/');
            if ($prefix !== '' && $request->is($prefix, $prefix . '/*')) {
                return true;
            }
        }

        return false;
    }

    private function injectErrorId(Response $response, Throwable $e): Response
    {
        $id     = ErrorIdentity::for($e);
        $header = (string) $this->cfg('error_id.header', 'X-Error-Id');
        $key    = (string) $this->cfg('error_id.payload_key', 'error_id');

        $response->headers->set($header, $id);

        if ($response instanceof JsonResponse) {
            $data = $response->getData(true);
            if (is_array($data) && ! array_key_exists($key, $data)) {
                $response->setData($data + [$key => $id]);
            }
        }

        return $response;
    }
}
