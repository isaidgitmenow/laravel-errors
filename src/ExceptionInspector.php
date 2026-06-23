<?php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors;

use Isaidgitmenow\LaravelErrors\Attributes\DontReport;
use Isaidgitmenow\LaravelErrors\Attributes\HttpCode;
use Isaidgitmenow\LaravelErrors\Attributes\RateLimit;
use Isaidgitmenow\LaravelErrors\Attributes\ReportTo;
use Isaidgitmenow\LaravelErrors\Attributes\TranslatedMessage;
use Isaidgitmenow\LaravelErrors\Attributes\WithContext;
use ReflectionClass;
use Throwable;

/**
 * Inspects a Throwable to extract metadata defined via PHP 8 Attributes.
 *
 * It recursively traverses the exception chain via getPrevious() to find
 * attributes even on exceptions wrapped by Laravel (e.g. QueryException, ViewException).
 *
 * Results are statically cached per-request to avoid repeated Reflection overhead.
 */
final class ExceptionInspector
{
    /**
     * Per-request static cache: class FQCN => extracted attribute data.
     *
     * @var array<string, array<string, mixed>>
     */
    private static array $cache = [];

    /**
     * Per-request origin cache: spl_object_id => resolved origin Throwable.
     * Prevents O(chain_length × inspections) reflection traversals per request.
     *
     * @var array<int, Throwable>
     */
    private static array $originCache = [];

    /**
     * Resolve the "origin" exception - the deepest non-framework exception in the chain
     * that carries our custom attributes, or the root if none found.
     */
    public static function origin(Throwable $e): Throwable
    {
        $oid = spl_object_id($e);

        if (isset(static::$originCache[$oid])) {
            return static::$originCache[$oid];
        }

        // Walk the chain looking for the deepest exception that carries our
        // custom attributes. Track the deepest node as the fallback so that
        // when NO exception in the chain has attributes we return the true
        // root cause (deepest getPrevious()), not the outermost wrapper.
        $attributed = null; // deepest node that has at least one of our attrs
        $deepest    = $e;   // deepest node in the chain (root cause fallback)
        $current    = $e;

        while ($current !== null) {
            $deepest = $current;
            if (static::hasAnyAttribute($current)) {
                $attributed = $current;
            }
            $current = $current->getPrevious();
        }

        $result = $attributed ?? $deepest;

        return static::$originCache[$oid] = $result;
    }

    /**
     * Get the HTTP status code for the exception.
     * Checks #[HttpCode] attribute, then getCode() if in valid HTTP range.
     */
    public static function httpCode(Throwable $e): int
    {
        $origin = static::origin($e);
        $attrs = static::attributes($origin);

        if (isset($attrs['http_code'])) {
            return $attrs['http_code'];
        }

        $code = $origin->getCode();

        if (is_int($code) && $code >= 400 && $code < 600) {
            return $code;
        }

        return 500;
    }

    /**
     * Determine if this exception should NOT be reported to external services.
     */
    public static function shouldNotReport(Throwable $e): bool
    {
        return static::attributes(static::origin($e))['dont_report'] ?? false;
    }

    /**
     * Get the target reporting channels, if any.
     * Returns null if the attribute is restricted to environments that don't match the current one.
     *
     * @return string[]|null
     */
    public static function reportToChannels(Throwable $e): ?array
    {
        $attrs = static::attributes(static::origin($e));

        $channels = $attrs['report_to'] ?? null;

        if ($channels === null) {
            return null;
        }

        // Environment filtering: if environments are specified, suppress in non-matching environments.
        $environments = $attrs['report_to_environments'] ?? [];
        if (!empty($environments) && !app()->environment($environments)) {
            return null;
        }

        return is_array($channels) ? $channels : [$channels];
    }

    /**
     * Get the translated frontend message for this exception, if any.
     */
    public static function translatedMessage(Throwable $e): ?string
    {
        $origin = static::origin($e);
        $key = static::attributes($origin)['translated_message'] ?? null;

        if ($key === null) {
            return null;
        }

        $translated = trans($key);

        // Only return if translation was found (not just echoing back the key)
        return $translated !== $key ? $translated : null;
    }

    /**
     * Extract contextual data from the exception's public properties as defined
     * by the #[WithContext] attribute.
     *
     * @return array<string, mixed>
     */
    public static function context(Throwable $e): array
    {
        $origin = static::origin($e);
        $attrs  = static::attributes($origin);
        $context = [];

        // Class-level #[WithContext]: extract named public properties
        foreach ($attrs['with_context'] ?? [] as $property) {
            if (property_exists($origin, $property)) {
                $context[$property] = $origin->{$property};
            }
        }

        // Method-level #[WithContext]: invoke the method and merge the returned array
        foreach ($attrs['with_context_methods'] ?? [] as $method) {
            if (method_exists($origin, $method)) {
                $result = $origin->{$method}();
                if (is_array($result)) {
                    $context = array_merge($context, $result);
                }
            }
        }

        return $context;
    }

    /**
     * Get the rate limit configuration for this exception, if any.
     */
    public static function rateLimit(Throwable $e): ?RateLimit
    {
        return static::attributes(static::origin($e))['rate_limit'] ?? null;
    }

    /**
     * Check if the exception (or any in its chain) has at least one of our custom attributes.
     */
    private static function hasAnyAttribute(Throwable $e): bool
    {
        $reflection = new ReflectionClass($e);
        $ourAttributes = [
            HttpCode::class,
            DontReport::class,
            ReportTo::class,
            TranslatedMessage::class,
            WithContext::class,
            RateLimit::class,
        ];

        // Check class-level attributes
        foreach ($ourAttributes as $attributeClass) {
            if (!empty($reflection->getAttributes($attributeClass))) {
                return true;
            }
        }

        // Also check public methods for method-level #[WithContext]
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (!empty($method->getAttributes(WithContext::class))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract and cache all attributes from the exception's class.
     *
     * @return array<string, mixed>
     */
    private static function attributes(Throwable $e): array
    {
        $class = $e::class;

        if (isset(static::$cache[$class])) {
            return static::$cache[$class];
        }

        $reflection = new ReflectionClass($e);
        $data = [];

        // #[HttpCode]
        $httpCodeAttrs = $reflection->getAttributes(HttpCode::class);
        if (!empty($httpCodeAttrs)) {
            $data['http_code'] = $httpCodeAttrs[0]->newInstance()->code;
        }

        // #[DontReport]
        $dontReportAttrs = $reflection->getAttributes(DontReport::class);
        if (!empty($dontReportAttrs)) {
            $data['dont_report'] = true;
        }

        // #[ReportTo]
        $reportToAttrs = $reflection->getAttributes(ReportTo::class);
        if (!empty($reportToAttrs)) {
            $instance = $reportToAttrs[0]->newInstance();
            $data['report_to'] = $instance->channels;
            $data['report_to_environments'] = $instance->environments;
        }

        // #[TranslatedMessage]
        $translatedAttrs = $reflection->getAttributes(TranslatedMessage::class);
        if (!empty($translatedAttrs)) {
            $data['translated_message'] = $translatedAttrs[0]->newInstance()->key;
        }

        // #[WithContext] — class level (property list)
        $withContextAttrs = $reflection->getAttributes(WithContext::class);
        if (!empty($withContextAttrs)) {
            $data['with_context'] = $withContextAttrs[0]->newInstance()->properties;
        }

        // #[WithContext] — method level (callable returning array)
        $withContextMethods = [];
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (!empty($method->getAttributes(WithContext::class))) {
                $withContextMethods[] = $method->getName();
            }
        }
        if (!empty($withContextMethods)) {
            $data['with_context_methods'] = $withContextMethods;
        }

        // #[RateLimit]
        $rateLimitAttrs = $reflection->getAttributes(RateLimit::class);
        if (!empty($rateLimitAttrs)) {
            $data['rate_limit'] = $rateLimitAttrs[0]->newInstance();
        }

        return static::$cache[$class] = $data;
    }

    /**
     * Flush the static cache. Used in tests to prevent state leaking between runs.
     */
    public static function flushCache(): void
    {
        static::$cache      = [];
        static::$originCache = [];
    }
}
