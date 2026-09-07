<?php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Events;

use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class ExceptionRendered
{
    public function __construct(
        public Throwable $exception,
        public string $errorId,
        public Response $response,
        public ?string $renderer,
        public string $context,
    ) {}
}
