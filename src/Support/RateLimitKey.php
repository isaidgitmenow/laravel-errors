<?php
// file: src/Support/RateLimitKey.php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Support;

use Isaidgitmenow\LaravelErrors\ExceptionInspector;
use Throwable;

final class RateLimitKey
{
    /** Lucrează pe origin(): pe wrapper, getFile()/getLine() ar fi ale mapper-ului și toate ar împărți un buget. */
    public static function for(Throwable $e, string $by, string $scope = ''): string
    {
        $o = ExceptionInspector::origin($e);

        $raw = match ($by) {
            'class'    => $o::class,
            'message'  => $o::class . ':' . md5($o->getMessage()),
            'code'     => ExceptionInspector::errorCode($e) ?? $o::class,   // fallback la class când nu există #[ErrorCode]
            default    => $o::class . ':' . $o->getFile() . ':' . $o->getLine(),
        };

        return 'laravel-errors:rl:' . md5($scope . '|' . $raw);
    }
}
