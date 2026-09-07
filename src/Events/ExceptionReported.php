<?php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Events;

use Throwable;

final readonly class ExceptionReported
{
    public function __construct(
        public Throwable $exception,
        public string $errorId,
        public ?string $errorCode,
        public int $httpStatus,
        public string $logLevel,
        public array $sanitizedContext,
        public array $reportersRun,
    ) {}
}
