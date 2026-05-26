<?php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Attributes;

use Attribute;

/**
 * Provide a translation key to be sent to the frontend (Livewire, Inertia, Filament)
 * instead of the raw PHP exception message.
 *
 * The package will automatically resolve the translation via Laravel's `trans()` helper.
 *
 * Usage:
 * #[TranslatedMessage('errors.payment_failed')]
 * class PaymentFailedException extends \Exception {}
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class TranslatedMessage
{
    public function __construct(
        public string $key,
    ) {}
}
