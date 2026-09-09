<?php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Audit;

use Illuminate\Support\Facades\DB;
use Isaidgitmenow\LaravelErrors\Contracts\AuditSink;

final class DatabaseAuditSink implements AuditSink
{
    public function write(AuditRecord $record): void
    {
        $data = $record->toArray();
        $data['context'] = json_encode($data['context'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        
        DB::table('error_audit_log')->insert($data);
    }
}
