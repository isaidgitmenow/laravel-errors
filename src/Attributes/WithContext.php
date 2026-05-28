<?php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Attributes;

use Attribute;

/**
 * Extract public properties from the exception and add them to Laravel's Context,
 * which is then automatically shared with error reporters (Sentry, Flare, etc).
 *
 * Usage on a class (extract public properties):
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
 *
 * Usage on a method (call method at report time, must return array):
 * class StripeChargeFailedException extends \Exception {
 *     #[WithContext]
 *     public function gatherStripeIntel(): array {
 *         return ['amount' => $this->amount, 'customer' => $this->customerId];
 *     }
 * }
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class WithContext
{
    /**
     * @param string[] $properties List of public property names to extract from the exception.
     *                             Leave empty when using on a method — the method return value
     *                             is used as the context array instead.
     */
    public function __construct(
        public array $properties = [],
    ) {}
}
