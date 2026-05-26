<?php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Attributes;

use Attribute;

/**
 * Marks an exception so it is never sent to external reporting services (Sentry, Flare, etc).
 * The exception will still be rendered to the user and may still appear in Debugbar locally.
 *
 * Usage:
 * #[DontReport]
 * class UserCancelledException extends \Exception {}
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class DontReport {}
