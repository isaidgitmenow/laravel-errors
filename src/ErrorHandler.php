<?php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors;

use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Support\Facades\Log;

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

            // Safety net: if no reporters are configured at all, fall back to
            // Laravel's default logger so exceptions are never silently swallowed.
            // NOTE: we do NOT call .stop() so that when all reporters have
            // shouldReport()→false, Laravel's native logger still runs as a
            // guaranteed fallback (e.g., XdebugReporter alone in production).
            if (!$manager->hasReporters()) {
                Log::error($e->getMessage(), [
                    'exception' => $e::class,
                    'file'      => $e->getFile(),
                    'line'      => $e->getLine(),
                ]);
            }
        });
        // .stop() deliberately removed — our LogReporter handles the primary logging,
        // but Laravel's default handler acts as a guaranteed safety net for exceptions
        // that no reporter handles (e.g., shouldReport()→false for all reporters).

        $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request) {
            /** @var ErrorManager $manager */
            $manager = app(ErrorManager::class);
            return $manager->render($e, $request);
            // Returning null falls through to Laravel's default render.
        });
    }

}
