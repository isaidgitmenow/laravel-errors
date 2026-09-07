<?php
// file: src/Integrations/Sentry/AttributesEventProcessor.php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Integrations\Sentry;

use Isaidgitmenow\LaravelErrors\ExceptionInspector;
use Isaidgitmenow\LaravelErrors\Support\ErrorIdentity;

/**
 * NU capturăm: sentry-laravel capturează deja prin Integration::handles(). Un al doilea captureException
 * ar dubla evenimentele în două issue-uri. Decorăm ce capturează Sentry — inclusiv report()-urile manuale.
 * Înregistrat cu Scope::addGlobalEventProcessor() (supraviețuiește resetărilor de scope din Octane/queue).
 */
final class AttributesEventProcessor
{
    public function __invoke(\Sentry\Event $event, ?\Sentry\EventHint $hint): \Sentry\Event
    {
        $e = $hint?->exception;
        if (! $e instanceof \Throwable) {
            return $event;
        }

        $origin = ExceptionInspector::origin($e);       // după mapare, $e e wrapper-ul

        $event->setTag('error_id', ErrorIdentity::for($e));
        $event->setTag('exception_class', $origin::class);   // titlul issue-ului va fi al wrapper-ului; tag-ul păstrează clasa reală

        if (($code = ExceptionInspector::errorCode($e)) !== null) {
            $event->setTag('error_code', $code);
            $event->setFingerprint([$code]);
        }

        $event->setContext('error', ExceptionInspector::sanitizedContext($e));

        // Nivelul doar când e declarat/derivabil din atribute — altfel am suprascrie scope-ul setat de aplicație.
        $attrs = ExceptionInspector::attributes($origin);
        if (isset($attrs['log_as']) || isset($attrs['http_code'])) {
            $event->setLevel(self::severity(ExceptionInspector::logLevel($e)));
        }

        return $event;
    }

    private static function severity(string $psr): \Sentry\Severity
    {
        return match ($psr) {
            'debug'                          => \Sentry\Severity::debug(),
            'info', 'notice'                 => \Sentry\Severity::info(),
            'warning'                        => \Sentry\Severity::warning(),
            'critical', 'alert', 'emergency' => \Sentry\Severity::fatal(),
            default                          => \Sentry\Severity::error(),
        };
    }
}
