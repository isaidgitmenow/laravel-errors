<?php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Contracts;

use Illuminate\Http\Request;
use Throwable;

interface ContextDetectorInterface
{
    /**
     * Determine if this detector applies to the current request/exception context.
     */
    public function detect(Throwable $e, Request $request): bool;
}
