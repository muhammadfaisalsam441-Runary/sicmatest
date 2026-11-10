<?php

namespace App\Models\Scheduling;

use App\Enums\IssueSeverity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Masalah pada draf jadwal (error = masalah wajib).
 */
#[Fillable(['schedule_version_id', 'code', 'severity', 'session_ids', 'message', 'resolved_at'])]
class ScheduleIssue extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'severity' => IssueSeverity::class,
            'session_ids' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ScheduleVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(ScheduleVersion::class, 'schedule_version_id');
    }

    /**
     * Masalah wajib yang belum diselesaikan (menghalangi Tetapkan).
     */
    #[Scope]
    protected function blocking(Builder $query): Builder
    {
        return $query->where('severity', IssueSeverity::Error)->whereNull('resolved_at');
    }
}
