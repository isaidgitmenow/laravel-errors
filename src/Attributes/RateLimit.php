<?php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Attributes;

use Attribute;

/**
 * Limit how often this exception type is reported to external services.
 *
 * Usage:
 * #[RateLimit(max: 10, intervalInMinutes: 5)]
 * #[RateLimit(max: 5, by: 'class')]
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class RateLimit
{
    public const BY = ['class', 'location', 'message', 'code'];

    public function __construct(public int $max, public int $intervalInMinutes = 5, public string $by = 'location')
    {
        if ($max < 1 || $intervalInMinutes < 1 || ! in_array($by, self::BY, true)) {
            throw new \InvalidArgumentException('Invalid RateLimit configuration.');
        }
    }
}
