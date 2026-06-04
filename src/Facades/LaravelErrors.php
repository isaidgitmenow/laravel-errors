<?php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Facades;

use Illuminate\Support\Facades\Facade;
use Isaidgitmenow\LaravelErrors\ErrorManager;

/**
 * @method static \Isaidgitmenow\LaravelErrors\ErrorManager addContext(string $detector, string $renderer)
 * @method static \Isaidgitmenow\LaravelErrors\ErrorManager addReporter(string $reporter)
 * @method static void report(\Throwable $e)
 * @method static \Symfony\Component\HttpFoundation\Response|null render(\Throwable $e, \Illuminate\Http\Request $request)
 *
 * @see \Isaidgitmenow\LaravelErrors\ErrorManager
 */
class LaravelErrors extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ErrorManager::class;
    }
}
