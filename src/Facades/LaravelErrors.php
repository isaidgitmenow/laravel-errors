<?php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Facades;

use Illuminate\Support\Facades\Facade;
use Isaidgitmenow\LaravelErrors\Contracts\ErrorManagerInterface;

/**
 * @method static bool report(\Throwable $e)
 * @method static \Symfony\Component\HttpFoundation\Response|null render(\Throwable $e, \Illuminate\Http\Request $request)
 * @method static \Isaidgitmenow\LaravelErrors\ErrorManager addContext(string $detector, string $renderer)
 * @method static \Isaidgitmenow\LaravelErrors\ErrorManager addReporter(string $reporter)
 * @method static void addPassThrough(string $exceptionClass)
 * @method static bool isPassThrough(\Throwable $e)
 *
 * @see \Isaidgitmenow\LaravelErrors\ErrorManager
 */
class LaravelErrors extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ErrorManagerInterface::class;
    }

    /** Register a respond callback with HandlerSlots. */
    public static function respond(\Closure $callback): void
    {
        app(\Isaidgitmenow\LaravelErrors\Support\HandlerSlots::class)->onRespond($callback);
    }

    /** Register a JSON decision callback with HandlerSlots. */
    public static function renderJsonWhen(\Closure $callback): void
    {
        app(\Isaidgitmenow\LaravelErrors\Support\HandlerSlots::class)->onRenderJson($callback);
    }
}
