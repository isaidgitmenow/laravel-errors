<?php
// file: src/Support/Masker.php  (2.1)

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Support;

final class Masker
{
    public const REDACTED = '[REDACTED]';

    public static function mask(mixed $value, string $mask): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }
        if (! is_scalar($value) && ! $value instanceof \Stringable) {
            return self::REDACTED;   // array/obiect marcat sensibil: nu încercăm să fim deștepți
        }

        $s = (string) $value;

        return match ($mask) {
            'last4'      => strlen($s) > 4 ? str_repeat('*', 4) . substr($s, -4) : str_repeat('*', strlen($s)),
            'first_last' => strlen($s) > 2 ? $s[0] . str_repeat('*', 3) . $s[-1] : str_repeat('*', strlen($s)),
            'email'      => self::email($s),
            // HMAC, nu sha256 simplu: IBAN/CNP/PAN au spații mici cu checksum → brute-force-abile cu un dicționar.
            'hash'       => 'hmac:' . substr(hash_hmac('sha256', $s, (string) config('app.key')), 0, 12),
            'length'     => '[REDACTED:' . strlen($s) . ']',
            default      => self::REDACTED,
        };
    }

    private static function email(string $s): string
    {
        $at = strrpos($s, '@');
        if ($at === false || $at === 0) {
            return self::REDACTED;
        }

        return $s[0] . '***' . substr($s, $at);
    }
}
