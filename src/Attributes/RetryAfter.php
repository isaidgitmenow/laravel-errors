<?php
// file: src/Attributes/RetryAfter.php  (3.0)

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class RetryAfter
{
    public function __construct(public int $seconds)
    {
        if ($seconds < 1) {
            throw new \InvalidArgumentException('RetryAfter must be at least 1 second.');
        }
    }
}
