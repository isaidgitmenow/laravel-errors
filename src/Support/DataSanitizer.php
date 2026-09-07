<?php
// file: src/Support/DataSanitizer.php  (2.0)

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Support;

final class DataSanitizer
{
    private const MAX_DEPTH  = 8;
    private const MAX_NODES  = 2000;
    private const MAX_STRING = 8192;

    /** @param array<array-key, mixed> $data  @param string[] $sensitiveKeys */
    public static function sanitize(array $data, array $sensitiveKeys = []): array
    {
        $budget = self::MAX_NODES;

        return self::walk($data, $sensitiveKeys, 0, $budget);
    }

    private static function walk(array $data, array $keys, int $depth, int &$budget): array
    {
        if ($depth >= self::MAX_DEPTH) {
            return ['__truncated' => '[max-depth-' . self::MAX_DEPTH . ']'];   // F-08: array auto-referențiat → fatal neprins
        }

        $out = [];
        foreach ($data as $k => $v) {
            if (--$budget <= 0) {
                $out['__truncated'] = '[max-nodes-' . self::MAX_NODES . ']';
                break;
            }

            $out[$k] = match (true) {
                self::isSensitive((string) $k, $keys)  => Masker::REDACTED,
                is_array($v)                          => self::walk($v, $keys, $depth + 1, $budget),
                $v instanceof \Closure                => '[Closure]',
                is_resource($v) || str_starts_with(gettype($v), 'resource') => '[resource]',
                is_object($v)                         => self::stringify($v),
                is_string($v) && strlen($v) > self::MAX_STRING => substr($v, 0, self::MAX_STRING) . '…[truncated]',
                default                               => $v,
            };
        }

        return $out;
    }

    private static function stringify(object $v): string
    {
        if (! $v instanceof \Stringable) {
            return '[' . $v::class . ']';
        }
        try {
            $s = (string) $v;   // __toString() poate arunca (model neîncărcat)
        } catch (\Throwable) {
            return '[' . $v::class . ']';
        }

        return strlen($s) > self::MAX_STRING ? substr($s, 0, self::MAX_STRING) . '…[truncated]' : $s;
    }

    private static function isSensitive(string $key, array $sensitiveKeys): bool
    {
        $key = strtolower($key);
        foreach ($sensitiveKeys as $s) {
            if (str_contains($key, strtolower((string) $s))) {
                return true;
            }
        }

        return false;
    }
}
