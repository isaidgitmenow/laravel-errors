<?php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Attributes;

use Attribute;

/**
 * Provide a translation key to be sent to the frontend instead of the raw PHP exception message.
 *
 * Usage:
 * #[TranslatedMessage('errors.payment_failed')]
 * #[TranslatedMessage('errors.insufficient_funds', params: ['amount'], choice: 'count')]
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class TranslatedMessage
{
    /** @param array<int|string, string> $params  ['amount'] sau ['suma' => 'amount']; valori = proprietăți/metode publice */
    public function __construct(public string $key, public array $params = [], public ?string $choice = null) {}
}
