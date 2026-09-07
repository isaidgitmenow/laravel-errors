<?php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Contracts;

use Isaidgitmenow\LaravelErrors\Audit\AuditRecord;

interface AuditSink
{
    public function write(AuditRecord $record): void;
}
