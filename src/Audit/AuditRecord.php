<?php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Audit;

final readonly class AuditRecord
{
    public function __construct(
        public string $errorId,
        public string $exceptionClass,
        public string $message,
        public int $httpStatus,
        public ?string $errorCode,
        public string $logLevel,
        public string $category,
        public string $retention,
        public array $context,
        public ?\DateTimeImmutable $occurredAt = null,
    ) {}

    public function toArray(): array
    {
        return [
            'error_id'        => $this->errorId,
            'exception_class' => $this->exceptionClass,
            'message'         => $this->message,
            'http_status'     => $this->httpStatus,
            'error_code'      => $this->errorCode,
            'log_level'       => $this->logLevel,
            'category'        => $this->category,
            'retention'       => $this->retention,
            'context'         => $this->context,
            'occurred_at'     => ($this->occurredAt ?? new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339_EXTENDED),
        ];
    }
}
