<?php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors;

use Illuminate\Foundation\Configuration\Exceptions;

/**
 * The public-facing facade for bootstrapping the package.
 *
 * Usage in bootstrap/app.php:
 *
 *   ->withExceptions(function (Exceptions $exceptions) {
 *       \Isaidgitmenow\LaravelErrors\ErrorHandler::handle($exceptions);
 *   })
 */
final class ErrorHandler
{
    /**
     * Register the package's report and render hooks with Laravel's exception handler.
     *
     * This is the single public entry point for the entire package.
     */
    public static function handle(Exceptions $exceptions): void
    {
        $exceptions->report(function (\Throwable $e) {
            /** @var ErrorManager $manager */
            $manager = app(ErrorManager::class);
            $manager->report($e);

            // .stop() is deliberately NOT called so that Laravel's default exception
            // logger always runs as a guaranteed fallback safety net. This means any
            // exception that all reporters skip (shouldReport()→false) is still logged
            // by Laravel's native handler, and no exception is ever silently swallowed.
        });

        $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request) {
            /** @var ErrorManager $manager */
            $manager = app(ErrorManager::class);
            return $manager->render($e, $request);
            // Returning null falls through to Laravel's default render.
        });
    }

}
