<?php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Support;

/**
 * Sanitizes arrays by redacting sensitive key values.
 * Used before passing data to external reporters or displaying in responses.
 */
final class DataSanitizer
{
    private const REDACTED = '[REDACTED]';

    /**
     * @param array<string, mixed> $data
     * @param string[]             $sensitiveKeys
     * @return array<string, mixed>
     */
    public static function sanitize(array $data, array $sensitiveKeys = []): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if (static::isSensitive((string) $key, $sensitiveKeys)) {
                $result[$key] = self::REDACTED;
            } elseif (is_array($value)) {
                $result[$key] = static::sanitize($value, $sensitiveKeys);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    private static function isSensitive(string $key, array $sensitiveKeys): bool
    {
        $key = strtolower($key);

        foreach ($sensitiveKeys as $sensitiveKey) {
            if (str_contains($key, strtolower($sensitiveKey))) {
                return true;
            }
        }

        return false;
    }
}
