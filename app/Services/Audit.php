<?php

namespace App\Services;

use App\Models\AuditLog;

class Audit
{
    public static function record(int $workspace, ?int $actor, string $action, string $subject, array $metadata = []): void
    {
        AuditLog::create(['workspace_id' => $workspace, 'user_id' => $actor, 'action' => $action, 'subject' => $subject, 'metadata' => $metadata, 'created_at' => now()]);
    }
}
