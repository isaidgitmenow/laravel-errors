<?php
// file: src/Attributes/Audit.php  (3.0)

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Audit
{
    public function __construct(
        public string $retention = '7 years',   // orice string acceptat de DateInterval / Carbon::add()
        public string $category = 'general',
    ) {
        if (! preg_match('/^[a-z][a-z0-9_\-]{1,63}$/', $category)) {
            throw new \InvalidArgumentException("Audit category [{$category}] must be lowercase kebab/snake, 2–64 chars.");
        }
    }
}
