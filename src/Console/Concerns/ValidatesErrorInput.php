<?php
// file: src/Console/Concerns/ValidatesErrorInput.php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Console\Concerns;

/**
 * TOATĂ validarea rulează la începutul lui handle(), înainte de orice atingere a filesystem-ului.
 */
trait ValidatesErrorInput
{
    private const SEGMENT = '/^[A-Za-z_][A-Za-z0-9_]*$/';
    private const CLASS_NAME = '/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/';
    private const LIST_ITEM = '/^[A-Za-z0-9_\-.]+$/';

    /** Acceptă Payments/PaymentFailed și Payments\PaymentFailed; refuză orice altceva (inclusiv `..`). */
    private function validatedClassName(string $raw): string
    {
        $name = trim(str_replace('/', '\\', trim($raw)), '\\');
        if (! preg_match(self::CLASS_NAME, $name)) {
            throw new \InvalidArgumentException("Invalid exception name [{$raw}]. Use letters, digits, underscores and \\ or / as namespace separator.");
        }

        return $name;
    }

    /** Un singur identificator: domeniul DDD și clasa DDD. */
    private function validatedSegment(string $raw, string $label): string
    {
        $v = trim($raw);
        if (! preg_match(self::SEGMENT, $v)) {
            throw new \InvalidArgumentException("Invalid {$label} [{$raw}]. Use a single PHP identifier.");
        }

        return $v;
    }

    private function validatedHttp(mixed $raw): int
    {
        if (! is_numeric($raw)) {
            throw new \InvalidArgumentException("The --http option must be numeric, got [{$raw}].");
        }
        $http = (int) $raw;
        if ($http !== 500 && ($http < 400 || $http > 599)) {
            throw new \InvalidArgumentException("The --http option must be 400–599, got [{$http}]. (#[HttpCode] would throw at runtime and silently disable the package for this class.)");
        }

        return $http;
    }

    /** @return list<string> */
    private function validatedList(string $raw, string $label): array
    {
        if (trim($raw) === '') {
            return [];
        }
        $items = array_values(array_filter(array_map('trim', explode(',', $raw))));
        foreach ($items as $item) {
            if (! preg_match(self::LIST_ITEM, $item)) {
                throw new \InvalidArgumentException("Invalid {$label} [{$item}]. Allowed: letters, digits, _ - .");
            }
        }

        return $items;
    }

    /** Normalizare lexicală (fără filesystem) + containment. Apelată ÎNAINTE de ensureDirectoryExists(). */
    private function assertWithinBase(string $target, string $base): void
    {
        $normalize = static function (string $p): string {
            $p = str_replace('\\', '/', $p);
            $drive = '';
            if (preg_match('/^([A-Za-z]:)(\/.*)?$/', $p, $m)) {
                $drive = $m[1];
                $p = $m[2] ?? '/';
            }
            $out = [];
            foreach (explode('/', $p) as $seg) {
                if ($seg === '' || $seg === '.') {
                    continue;
                }
                if ($seg === '..') {
                    array_pop($out);
                    continue;
                }
                $out[] = $seg;
            }

            return $drive . '/' . implode('/', $out);
        };

        $b = rtrim($normalize(realpath($base) ?: $base), '/') . '/';
        if (! str_starts_with($normalize($target), $b)) {
            throw new \InvalidArgumentException('Refusing to write outside of the application directory.');
        }
    }
}
