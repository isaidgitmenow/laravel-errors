<?php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Contracts;

use Throwable;

interface ErrorReporterInterface
{
    /**
     * Report the exception to an external tracking service.
     *
     * Return false to stop further reporters from running.
     */
    public function report(Throwable $e): bool;

    /**
     * Determine if this reporter should handle the given exception.
     */
    public function shouldReport(Throwable $e): bool;
}
