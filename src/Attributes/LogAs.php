<?php
// file: src/Attributes/LogAs.php  (2.2)  — nu „LogLevel": s-ar ciocni cu Psr\Log\LogLevel

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class LogAs
{
    public const LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

    public function __construct(public string $level)
    {
        if (! in_array($level, self::LEVELS, true)) {
            throw new \InvalidArgumentException("Invalid PSR-3 level [{$level}].");
        }
    }
}
