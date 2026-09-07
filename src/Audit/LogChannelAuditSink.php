<?php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Audit;

use Illuminate\Support\Facades\Log;
use Isaidgitmenow\LaravelErrors\Contracts\AuditSink;

final class LogChannelAuditSink implements AuditSink
{
    public function __construct(private readonly string $channel = 'audit') {}

    public function write(AuditRecord $record): void
    {
        Log::channel($this->channel)->info('[AUDIT] ' . $record->exceptionClass, $record->toArray());
    }
}
