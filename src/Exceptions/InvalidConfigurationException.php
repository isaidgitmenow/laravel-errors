<?php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Exceptions;

/**
 * Thrown at boot time when the package configuration is invalid.
 * §1.2 rule 3: config errors explode at boot, never silently at runtime.
 */
final class InvalidConfigurationException extends \RuntimeException {}
