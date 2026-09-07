<?php
// file: src/Support/CriticalLog.php  (2.0)

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Un pachet de error handling care tace când eșuează nu e „self-healing", e invizibil. */
final class CriticalLog
{
    public static function once(string $message, Throwable $failure, array $context = []): void
    {
        try {
            $key = 'laravel-errors:critical:' . md5($message . '|' . $failure::class . '|' . $failure->getMessage());

            if (! Cache::add($key, 1, 60)) {
                return;
            }

            Log::critical("[laravel-errors] {$message}: {$failure->getMessage()}", $context + [
                'exception' => $failure,
            ]);
        } catch (Throwable) {
            // ultimul strat: dacă nici asta nu merge, nu mai avem ce face în siguranță
        }
    }
}
