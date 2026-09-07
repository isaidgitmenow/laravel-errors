<?php
// file: src/Support/MessageResolver.php  (2.0)

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Support;

use Isaidgitmenow\LaravelErrors\ExceptionInspector;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Singurul loc care decide ce mesaj ajunge la client.
 * 'attributed' (implicit): brut doar pentru #[TranslatedMessage] / #[HttpCode] / HttpExceptionInterface real; restul generic.
 * 'always': comportamentul pre-2.0 (NU în producție). 'never': generic chiar și pentru cele atribuite.
 * APP_DEBUG=true: brut întotdeauna.
 */
final class MessageResolver
{
    public static function public(Throwable $e, int $statusCode, array $config = []): string
    {
        if (($translated = ExceptionInspector::translatedMessage($e)) !== null) {
            return $translated;
        }

        // Un HttpExceptionInterface REAL (abort(503, 'Down'), chiar cu previous: PDOException) poartă un mesaj
        // scris deliberat pentru client. Wrapper-ul nostru intră tot aici — mesajul lui e deja public. Idempotent.
        if ($e instanceof HttpExceptionInterface) {
            return $e->getMessage();
        }

        $mode = $config['expose_messages'] ?? 'attributed';

        if ($mode === 'always' || app()->hasDebugModeEnabled()) {
            return ExceptionInspector::origin($e)->getMessage();
        }

        if ($mode === 'attributed' && ExceptionInspector::hasHttpCodeAttribute($e)) {
            return ExceptionInspector::origin($e)->getMessage();
        }

        return self::fallback($statusCode, $e, $config);
    }

    private static function fallback(int $statusCode, Throwable $e, array $config): string
    {
        $prefix = (string) ($config['fallback_message_prefix'] ?? 'errors.http');
        $key    = "{$prefix}.{$statusCode}";
        $params = ($config['error_id']['in_message'] ?? true) ? ['error_id' => ErrorIdentity::for($e)] : [];

        foreach ([$key, "laravel-errors::http.{$statusCode}"] as $candidate) {   // ale aplicației, apoi ale pachetului
            $translated = trans($candidate, $params);
            if (is_string($translated) && $translated !== $candidate) {
                return $translated;
            }
        }

        $text = Response::$statusTexts[$statusCode] ?? 'Server Error';

        return $params === [] ? $text : "{$text} (ref: {$params['error_id']})";
    }
}
