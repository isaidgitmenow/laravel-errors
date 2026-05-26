<?php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Attributes;

use Attribute;

/**
 * Extract public properties from the exception and add them to Laravel's Context,
 * which is then automatically shared with error reporters (Sentry, Flare, etc).
 *
 * Usage:
 * #[WithContext(['user_id', 'transaction_id'])]
 * class PaymentFailedException extends \Exception {
 *     public function __construct(
 *         public readonly int $user_id,
 *         public readonly string $transaction_id,
 *         string $message = ''
 *     ) {
 *         parent::__construct($message);
 *     }
 * }
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class WithContext
{
    /**
     * @param string[] $properties List of public property names to extract from the exception.
     */
    public function __construct(
        public array $properties,
    ) {}
}
