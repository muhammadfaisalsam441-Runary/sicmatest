<?php

namespace App\Models;

use App\Enums\OptimizationJobStatus;
use App\Enums\SolverStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Proses susun jadwal otomatis. solver_status disimpan terpisah dari status proses.
 */
#[Fillable(['schedule_version_id', 'scope', 'time_limit_seconds', 'status', 'solver_status', 'input_hash', 'result', 'started_at', 'finished_at', 'created_by'])]
class OptimizationJob extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope' => 'array',
            'time_limit_seconds' => 'integer',
            'status' => OptimizationJobStatus::class,
            'solver_status' => SolverStatus::class,
            'result' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
