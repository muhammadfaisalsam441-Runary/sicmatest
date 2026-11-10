<?php

namespace App\Services\Account;

use App\Models\User\AuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Mencatat perubahan penting ke audit_logs (B18): pelaku, waktu, sebelum–sesudah, alasan.
 */
class AuditLogger
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function log(string $action, Model $auditable, ?array $before = null, ?array $after = null, ?string $reason = null): AuditLog
    {
        return AuditLog::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'auditable_type' => $auditable->getMorphClass(),
            'auditable_id' => $auditable->getKey(),
            'before' => $before,
            'after' => $after,
            'reason' => $reason,
            'ip' => request()->ip(),
            'created_at' => now(),
        ]);
    }
}
