<?php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Contracts;

/**
 * Marker interface for reporters that should still receive exception reports
 * even if the exception is configured to be ignored (shouldNotReport).
 */
interface ReportsIgnoredExceptions
{
}
