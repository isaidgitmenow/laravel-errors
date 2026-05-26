<?php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Attributes;

use Attribute;

/**
 * Limit how often this exception type is reported to external services.
 * Uses Laravel's Cache to track report counts, preventing quota exhaustion
 * when a single error occurs at high volume (e.g., database connection lost).
 *
 * Usage:
 * #[RateLimit(max: 10, intervalInMinutes: 5)]
 * class DatabaseConnectionException extends \Exception {}
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class RateLimit
{
    public function __construct(
        public int $max,
        public int $intervalInMinutes = 5,
    ) {}
}
