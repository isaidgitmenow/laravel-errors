<?php
// file: src/Support/CallableResolver.php  (2.0)

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Support;

use Closure;
use Isaidgitmenow\LaravelErrors\Exceptions\InvalidConfigurationException;

/** F-24: config-ul promitea class-string invokable pentru config:cache; codul accepta doar Closure. */
final class CallableResolver
{
    public static function resolve(mixed $value, string $configKey): ?callable
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof Closure) {
            return $value;
        }
        if (is_string($value) && class_exists($value)) {
            $instance = app($value);
            if (is_callable($instance)) {
                return $instance;
            }
        }
        if (is_object($value) && is_callable($value)) {
            return $value;
        }

        throw new InvalidConfigurationException("errors.{$configKey} must be a Closure, an invokable class-string, or null.");
    }
}
