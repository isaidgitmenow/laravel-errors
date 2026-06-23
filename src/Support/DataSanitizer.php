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
            } elseif ($value instanceof \Closure) {
                $result[$key] = '[Closure]';
            } elseif (is_resource($value)) {
                // PHP resource handles (file, socket, db cursor, etc.) cannot be
                // serialized. Convert to a safe string to prevent TypeError.
                $result[$key] = '[resource:' . get_resource_type($value) . ']';
            } elseif (is_object($value)) {
                // Convert objects to a safe string representation.
                // Stringable objects get their string value; others get their class name.
                $result[$key] = $value instanceof \Stringable
                    ? (string) $value
                    : '[' . $value::class . ']';
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
