<?php
// file: src/Support/ErrorIdentity.php  (2.1)

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Support;

use Illuminate\Support\Str;
use Isaidgitmenow\LaravelErrors\Exceptions\AttributedHttpException;
use Throwable;
use WeakMap;

final class ErrorIdentity
{
    private static ?WeakMap $ids = null;

    /** Generează întotdeauna; config `error_id.enabled` gate-uiește doar injectarea în răspuns/log/context. */
    public static function for(Throwable $e): string
    {
        self::$ids ??= new WeakMap();

        $key = $e instanceof AttributedHttpException ? $e->original() : $e;

        return self::$ids[$key] ??= (string) Str::ulid();
    }

    public static function flush(): void
    {
        self::$ids = new WeakMap();
    }
}
