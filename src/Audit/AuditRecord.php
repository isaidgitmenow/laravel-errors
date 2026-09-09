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
        public \DateTimeInterface $retainUntil,
        public array $context,
        public ?string $userId = null,
        public ?string $requestId = null,
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
            'retain_until'    => $this->retainUntil->format('Y-m-d H:i:s'),
            'context'         => $this->context,
            'user_id'         => $this->userId,
            'request_id'      => $this->requestId,
            'occurred_at'     => ($this->occurredAt ?? new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ];
    }
}
