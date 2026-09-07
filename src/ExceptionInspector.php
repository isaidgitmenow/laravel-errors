<?php
// file: src/ExceptionInspector.php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors;

use Isaidgitmenow\LaravelErrors\Attributes\RateLimit;
use Isaidgitmenow\LaravelErrors\Exceptions\AttributedHttpException;
use Isaidgitmenow\LaravelErrors\Support\AttributeCache;
use Isaidgitmenow\LaravelErrors\Support\DataSanitizer;
use Isaidgitmenow\LaravelErrors\Support\Masker;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;
use WeakMap;

/**
 * Citește metadatele unei excepții. Static pentru compatibilitate cu API-ul existent,
 * dar toată reflecția e delegată către AttributeCache (WP-01) și tot cache-ul per obiect
 * e WeakMap (F-01: spl_object_id se reciclează; F-29: context() era executat de 5×).
 */
final class ExceptionInspector
{
    private static ?WeakMap $originCache  = null;
    private static ?WeakMap $contextCache = null;

    // ---------------------------------------------------------------- origin & attributes

    /**
     * Excepția „de interes": dezpachetează wrapper-ul nostru, apoi alege cel mai adânc nod
     * care poartă atributele pachetului; altfel cauza-rădăcină.
     */
    public static function origin(Throwable $e): Throwable
    {
        self::$originCache ??= new WeakMap();

        if (isset(self::$originCache[$e])) {
            return self::$originCache[$e];
        }

        $current = $e instanceof AttributedHttpException ? $e->original() : $e;
        $attributed = null;
        $deepest    = $current;

        while ($current !== null) {
            $deepest = $current;
            if (self::attributes($current) !== []) {
                $attributed = $current;
            }
            $current = $current->getPrevious();
        }

        return self::$originCache[$e] = $attributed ?? $deepest;
    }

    /** @return array<string, mixed> */
    public static function attributes(Throwable $e): array
    {
        return app(AttributeCache::class)->for($e::class);
    }

    public static function hasOwnAttributes(Throwable $e): bool
    {
        return self::attributes($e) !== [];
    }

    // ---------------------------------------------------------------- derived values

    public static function httpCode(Throwable $e): int
    {
        $origin = self::origin($e);
        $attrs  = self::attributes($origin);

        if (isset($attrs['http_code'])) {
            return (int) $attrs['http_code'];
        }

        // Wrapper-ul nostru sau un HttpException real: statusul e de încredere.
        if ($e instanceof HttpExceptionInterface) {
            return $e->getStatusCode();
        }

        // F-26: getCode() ca status HTTP e o convenție a APLICAȚIEI, nu a PHP-ului.
        // Opt-in, și doar pe excepția aruncată, nu pe cauza adâncă (SDK-uri terțe pun acolo statusul upstream).
        if (config('errors.http_code_from_exception_code', false)) {
            $code = $e->getCode();
            if (is_int($code) && $code >= 400 && $code < 600) {
                return $code;
            }
        }

        return (int) config('errors.default_status', 500);
    }

    public static function hasHttpCodeAttribute(Throwable $e): bool
    {
        return isset(self::attributes(self::origin($e))['http_code']);
    }

    public static function shouldNotReport(Throwable $e): bool
    {
        return (bool) (self::attributes(self::origin($e))['dont_report'] ?? false);
    }

    public static function errorCode(Throwable $e): ?string
    {
        return self::attributes(self::origin($e))['error_code']['code'] ?? null;
    }

    /** @return array{code: string, type: ?string, title: ?string}|null */
    public static function errorCodeDefinition(Throwable $e): ?array
    {
        return self::attributes(self::origin($e))['error_code'] ?? null;
    }

    /** PSR-3. #[LogAs] explicit → derivat din status → 'error'. */
    public static function logLevel(Throwable $e): string
    {
        $attrs = self::attributes(self::origin($e));

        if (isset($attrs['log_as'])) {
            return $attrs['log_as'];
        }

        if (isset($attrs['http_code'])) {
            $status = (int) $attrs['http_code'];

            return match (true) {
                $status === 429 => 'notice',
                $status === 503 => 'critical',
                $status < 500   => 'warning',
                default         => 'error',
            };
        }

        return 'error';
    }

    public static function retryAfter(Throwable $e): ?int
    {
        return self::attributes(self::origin($e))['retry_after'] ?? null;
    }

    public static function rateLimit(Throwable $e): ?RateLimit
    {
        $rl = self::attributes(self::origin($e))['rate_limit'] ?? null;

        return $rl === null ? null : new RateLimit($rl['max'], $rl['interval'], $rl['by']);
    }

    /** @return array{retention: string, category: string}|null */
    public static function audit(Throwable $e): ?array
    {
        return self::attributes(self::origin($e))['audit'] ?? null;
    }

    /** @return string[]|null  null = nu e restricționat / nu are atributul */
    public static function reportToChannels(Throwable $e): ?array
    {
        $attrs    = self::attributes(self::origin($e));
        $channels = $attrs['report_to'] ?? null;

        if ($channels === null) {
            return null;
        }

        $envs = $attrs['report_to_environments'] ?? [];
        if ($envs !== [] && ! app()->environment($envs)) {
            return null;
        }

        return $channels;
    }

    /** F-28: trans() poate întoarce array pentru o cheie de grup. I-06: parametri din proprietăți. */
    public static function translatedMessage(Throwable $e): ?string
    {
        $origin = self::origin($e);
        $def    = self::attributes($origin)['translated_message'] ?? null;

        if ($def === null) {
            return null;
        }

        $replace = [];
        foreach ($def['params'] ?? [] as $placeholder => $source) {
            if (is_int($placeholder)) {
                $placeholder = $source;
            }
            $replace[$placeholder] = self::paramValue($origin, $source);
        }

        $translated = isset($def['choice'])
            ? trans_choice($def['key'], (int) self::paramValue($origin, $def['choice']), $replace)
            : trans($def['key'], $replace);

        if (! is_string($translated) || $translated === $def['key']) {
            return null;
        }

        return $translated;
    }

    /**
     * Contextul #[WithContext], cu #[Sensitive] aplicat la extragere.
     * Calculat O SINGURĂ DATĂ per obiect (F-29) — metodele #[WithContext] pot fi scumpe.
     *
     * @return array<string, mixed>
     */
    public static function context(Throwable $e): array
    {
        self::$contextCache ??= new WeakMap();

        $origin = self::origin($e);   // cheia e originalul: wrapper-ul (Handler) și originalul (hook, Sentry) → același calcul

        if (isset(self::$contextCache[$origin])) {
            return self::$contextCache[$origin];
        }

        $attrs  = self::attributes($origin);
        $masks  = $attrs['sensitive'] ?? [];
        $ctx    = [];

        foreach ($attrs['with_context'] ?? [] as $property) {
            if (property_exists($origin, $property)) {
                $value = $origin->{$property};
                $ctx[$property] = isset($masks[$property]) ? Masker::mask($value, $masks[$property]) : $value;
            }
        }

        $methodMasks = $attrs['with_context_sensitive'] ?? [];
        foreach ($attrs['with_context_methods'] ?? [] as $method) {
            if (! method_exists($origin, $method)) {
                continue;
            }
            $result = $origin->{$method}();
            if (! is_array($result)) {
                continue;
            }
            foreach ($result as $k => $v) {
                $ctx[$k] = isset($methodMasks[$k]) ? Masker::mask($v, $methodMasks[$k]) : $v;
            }
        }

        return self::$contextCache[$origin] = $ctx;
    }

    /** Contextul trecut și prin lista globală de chei sensibile — forma care iese din pachet. */
    public static function sanitizedContext(Throwable $e): array
    {
        return DataSanitizer::sanitize(self::context($e), (array) config('errors.sanitize', []));
    }

    // ---------------------------------------------------------------- housekeeping

    public static function flushCache(): void
    {
        self::$originCache  = new WeakMap();
        self::$contextCache = new WeakMap();
        if (app()->bound(AttributeCache::class)) {
            app(AttributeCache::class)->flushRuntime();
        }
    }

    private static function paramValue(Throwable $origin, string $source): mixed
    {
        $masks = self::attributes($origin)['sensitive'] ?? [];

        if (property_exists($origin, $source)) {
            $v = $origin->{$source};

            return isset($masks[$source]) ? Masker::mask($v, $masks[$source]) : $v;
        }

        if (method_exists($origin, $source)) {
            return $origin->{$source}();
        }

        return '';
    }
}
