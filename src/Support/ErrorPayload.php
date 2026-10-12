<?php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Support;

use Isaidgitmenow\LaravelErrors\ExceptionInspector;
use Throwable;

/**
 * Sursa unică a câmpurilor comune din răspunsurile de eroare ale renderelor.
 */
final class ErrorPayload
{
    /** @return array{message: string, code: string, error_id: string} */
    public static function base(Throwable $e, int $status, string $message): array
    {
        return [
            'message'  => $message,
            'code'     => ExceptionInspector::errorCode($e) ?? 'HTTP_' . $status,
            'error_id' => ErrorIdentity::for($e),
        ];
    }

    /** Forma JSON legacy — `errors: []` rămâne: clienții fac response.errors.length. */
    public static function legacyJson(Throwable $e, int $status, string $message): array
    {
        $base = self::base($e, $status, $message);

        return ['message' => $base['message'], 'errors' => []] + $base;
    }

    /** Pentru pagini (Inertia/Blade): statusul face parte din props. */
    public static function page(Throwable $e, int $status, string $message): array
    {
        return ['status' => $status] + self::base($e, $status, $message);
    }
}
