<?php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors;

use Illuminate\Contracts\Debug\ExceptionHandler;
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
            // The .stop() below prevents Laravel's default handler from running,
            // so we must ensure at least one logging path exists.
            if (!$manager->hasReporters()) {
                Log::error($e->getMessage(), [
                    'exception' => $e::class,
                    'file'      => $e->getFile(),
                    'line'      => $e->getLine(),
                ]);
            }
        })->stop();
        // .stop() tells Laravel NOT to pass this to the default logger after our reporter runs.
        // Our LogReporter handles logging internally for full control.

        $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request) {
            /** @var ErrorManager $manager */
            $manager = app(ErrorManager::class);
            return $manager->render($e, $request);
            // Returning null falls through to Laravel's default render.
        });
    }
}
