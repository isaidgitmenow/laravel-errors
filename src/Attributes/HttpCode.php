<?php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Attributes;

use Attribute;

/**
 * Define the HTTP status code for this exception.
 *
 * Usage:
 * #[HttpCode(402)]
 * class PaymentFailedException extends \Exception {}
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class HttpCode
{
    public function __construct(
        public int $code,
    ) {
        if ($code < 400 || $code > 599) {
            throw new \InvalidArgumentException("HTTP status code must be between 400 and 599 (client/server error), got {$code}.");
        }
    }
}
