<?php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Attributes;

use Attribute;

/**
 * Route this exception's report to a specific Log channel.
 * This takes precedence over the default channel defined in config/errors.php.
 *
 * Usage:
 * #[ReportTo('slack')]
 * #[ReportTo('slack', environments: ['production'])]
 * class CriticalPaymentException extends \Exception {}
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class ReportTo
{
    /**
     * @param string|string[] $channels      One or more log channel names (e.g. 'slack', ['slack', 'sentry'])
     * @param string[]        $environments  Restrict reporting to these environments. Empty = all environments.
     */
    public function __construct(
        public string|array $channels,
        public array $environments = [],
    ) {}
}
