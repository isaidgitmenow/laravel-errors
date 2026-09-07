<?php
// file: src/ErrorHandler.php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Isaidgitmenow\LaravelErrors\Contracts\ErrorManagerInterface;
use Isaidgitmenow\LaravelErrors\Exceptions\AttributedHttpException;
use Isaidgitmenow\LaravelErrors\Renderers\ValidationProblemRenderer;
use Isaidgitmenow\LaravelErrors\Support\AttributeCache;
use Isaidgitmenow\LaravelErrors\Support\AttributedExceptionMapper;
use Isaidgitmenow\LaravelErrors\Support\ErrorIdentity;
use Isaidgitmenow\LaravelErrors\Support\HandlerSlots;
use Isaidgitmenow\LaravelErrors\Support\RateLimitKey;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Singurul punct de contact cu Handler-ul Laravel. Ordinea și semantica: §1.1.
 *
 *   ->withExceptions(fn (Exceptions $e) => ErrorHandler::handle($e))
 */
final class ErrorHandler
{
    public static function handle(Exceptions $exceptions): void
    {
        $config = (array) config('errors', []);
        $flags  = (array) ($config['integrate_with_laravel'] ?? []);
        $cache  = app(AttributeCache::class);
        $slots  = app(HandlerSlots::class);

        // 1. map() PER CLASĂ pentru orice clasă cu http_code | translated_message | error_code.
        //    Nu o închidere pe Throwable: s-ar înregistra cu cheia Throwable și, fiind prima potrivire,
        //    ar bloca toate map()-urile aplicației.
        if ($flags['map_http_code'] ?? false) {
            $mapper = app(AttributedExceptionMapper::class);
            foreach ($cache->classesWith('http_code', 'translated_message', 'error_code') as $class) {
                $exceptions->map($class, fn (Throwable $e) => $mapper->wrap($e));
            }
        }

        // 2. #[DontReport]. Mapate → SuppressedAttributedHttpException implements ShouldntReport (nativ).
        //    Nemapate → dontReport([...]). Clasele cu #[Audit] sunt EXCLUSE: pipeline-ul nostru trebuie să ruleze.
        if ($flags['dont_report'] ?? false) {
            $mapped = ($flags['map_http_code'] ?? false)
                ? array_flip($cache->classesWith('http_code', 'translated_message', 'error_code'))
                : [];

            $plain = [];
            foreach ($cache->classesWith('dont_report') as $class) {
                $data = $cache->for($class);
                if (isset($data['audit']) || isset($mapped[$class])) {
                    continue;
                }
                $plain[] = $class;
            }
            if ($plain !== []) {
                $exceptions->dontReport($plain);
            }
        }

        // 3. Nivelul de log. Pe familia de wrapper-e (o singură dată, indiferent câte clase există)
        //    și pe clasele nemapate din cache.
        foreach (AttributedHttpException::levelMap() as $psr => $wrapperClass) {
            $exceptions->level($wrapperClass, $psr);
        }
        foreach ($cache->all() as $class => $data) {
            $level = $data['log_as'] ?? self::derivedLevel($data);
            if ($level !== null) {
                $exceptions->level($class, $level);
            }
        }

        // 4. Îmbogățirea liniei de log Laravel — LogReporter e scos din default încă din 2.0.
        $exceptions->context(fn (Throwable $e) => [
            'error_id'         => ErrorIdentity::for($e),
            'error_code'       => ExceptionInspector::errorCode($e),
            'original_message' => ExceptionInspector::origin($e)->getMessage(),   // wrapper-ul are mesajul public
            'error_context'    => ExceptionInspector::sanitizedContext($e),
        ]);

        // 5. throttle() nativ — DOAR în producție: rulează în shouldntReport() și când se declanșează
        //    nu mai rulează NICIUN reporter (nici Debugbar/Xdebug). Fail-open garantat de Laravel (rescue).
        if (app()->isProduction() && ($config['native_throttle'] ?? true)) {
            $exceptions->throttle(function (Throwable $e) {
                $limit = ExceptionInspector::rateLimit($e);

                return $limit === null
                    ? null
                    : Limit::perMinutes($limit->intervalInMinutes, $limit->max)
                        ->by(RateLimitKey::for($e, $limit->by, 'laravel'));
            });
        }

        // 6. Sloturile unice, compuse.
        $exceptions->respond(fn (Response $r, Throwable $e, Request $req) => $slots->runRespond($r, $e, $req));

        if ($flags['json_decision'] ?? false) {
            $exceptions->shouldRenderJsonWhen(fn (Request $req, Throwable $e) => $slots->shouldRenderJson($req, $e));
        }

        // 7. 422 unificat (opt-in, cu Problem Details).
        if (($config['problem_details']['enabled'] ?? false) && ($config['problem_details']['unify_validation'] ?? false)) {
            $exceptions->render(fn (ValidationException $e, Request $req) => app(ValidationProblemRenderer::class)->render($e, $req));
        }

        // 8. Pipeline-ul pachetului. Contractul report(): true = Laravel loghează; false DOAR pentru #[DontReport].
        $exceptions->report(fn (Throwable $e): bool => app(ErrorManagerInterface::class)->report($e));
        $exceptions->render(fn (Throwable $e, Request $req) => app(ErrorManagerInterface::class)->render($e, $req));
    }

    private static function derivedLevel(array $data): ?string
    {
        if (! isset($data['http_code'])) {
            return null;
        }
        $status = (int) $data['http_code'];

        return match (true) {
            $status === 429 => 'notice',
            $status === 503 => 'critical',
            $status < 500   => 'warning',
            default         => 'error',
        };
    }
}
