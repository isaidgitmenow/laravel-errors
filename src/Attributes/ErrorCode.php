<?php
// file: src/Attributes/ErrorCode.php  (2.2)

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class ErrorCode
{
    public function __construct(
        public string $code,            // INSUFFICIENT_FUNDS — stabil, documentat
        public ?string $type = null,    // URI RFC 9457; implicit {type_base_url}/insufficient-funds
        public ?string $title = null,   // implicit: codul umanizat („Insufficient funds"), NU textul statusului
    ) {
        if (! preg_match('/^[A-Z][A-Z0-9_]{2,63}$/', $code)) {
            throw new \InvalidArgumentException("Error code [{$code}] must be SCREAMING_SNAKE_CASE, 3–64 chars.");
        }
    }
}
