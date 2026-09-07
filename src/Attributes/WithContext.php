<?php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Attributes;

use Attribute;

/**
 * Extract public properties and/or method return values as contextual data.
 *
 * Usage on a class (extract public properties):
 * #[WithContext(['user_id', 'transaction_id'])]
 *
 * Usage on a method (call method at report time, must return array):
 * #[WithContext]
 * public function gatherStripeIntel(): array { ... }
 *
 * The `sensitive` parameter applies masks to array keys returned by methods:
 * #[WithContext(sensitive: ['iban' => 'last4'])]
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class WithContext
{
    /** @param string[] $properties  @param array<string, string> $sensitive  cheie → mască, pentru array-urile întoarse de metode */
    public function __construct(public array $properties = [], public array $sensitive = []) {}
}
