<?php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Audit;

use Illuminate\Support\Facades\DB;
use Isaidgitmenow\LaravelErrors\Contracts\AuditSink;

final class DatabaseAuditSink implements AuditSink
{
    public function write(AuditRecord $record): void
    {
        DB::table('error_audit_log')->insert($record->toArray());
    }
}
