<?php
// file: src/Attributes/Sensitive.php  (2.1)

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Attributes;

use Attribute;

/**
 * TARGET_PROPERTY | TARGET_PARAMETER: pe o proprietate promovată PHP aplică atributul și parametrului;
 * cu TARGET_PROPERTY singur, ReflectionParameter::getAttributes()[0]->newInstance() aruncă Error (PHP 8.4.21).
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final readonly class Sensitive
{
    public const MASKS = ['full', 'last4', 'first_last', 'email', 'hash', 'length'];

    public function __construct(public string $mask = 'full')
    {
        if (! in_array($mask, self::MASKS, true)) {
            throw new \InvalidArgumentException("Unknown mask [{$mask}]. Allowed: " . implode(', ', self::MASKS));
        }
    }
}
