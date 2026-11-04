<?php

namespace App\Models;

use App\Enums\ScheduleSource;
use App\Enums\ScheduleVersionStatus;
use Database\Factories\ScheduleVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Versi jadwal. Hanya status official yang dibaca portal (B09).
 */
#[Fillable(['period_id', 'version_no', 'status', 'source', 'based_on_version_id', 'effective_from', 'effective_to', 'published_by', 'published_at', 'lock_version'])]
class ScheduleVersion extends Model
{
    /** @use HasFactory<ScheduleVersionFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version_no' => 'integer',
            'status' => ScheduleVersionStatus::class,
            'source' => ScheduleSource::class,
            'effective_from' => 'date',
            'effective_to' => 'date',
            'published_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Period, $this>
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    /**
     * @return BelongsTo<ScheduleVersion, $this>
     */
    public function basedOn(): BelongsTo
    {
        return $this->belongsTo(ScheduleVersion::class, 'based_on_version_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    /**
     * @return HasMany<ScheduleSession, $this>
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(ScheduleSession::class);
    }

    /**
     * @return HasMany<ScheduleIssue, $this>
     */
    public function issues(): HasMany
    {
        return $this->hasMany(ScheduleIssue::class);
    }

    /**
     * @return HasMany<OptimizationJob, $this>
     */
    public function optimizationJobs(): HasMany
    {
        return $this->hasMany(OptimizationJob::class);
    }

    /**
     * @return HasMany<RoomBlock, $this>
     */
    public function roomBlocks(): HasMany
    {
        return $this->hasMany(RoomBlock::class);
    }

    /**
     * Versi berstatus Jadwal resmi.
     */
    #[Scope]
    protected function official(Builder $query): Builder
    {
        return $query->where('status', ScheduleVersionStatus::Official);
    }

    /**
     * Versi yang pernah ditetapkan (resmi atau digantikan). Versi digantikan tetap berlaku sampai effective_to,
     * dan blok ruangnya sesudah tanggal itu sudah dinonaktifkan saat penetapan versi baru.
     */
    #[Scope]
    protected function published(Builder $query): Builder
    {
        return $query->whereIn('status', [ScheduleVersionStatus::Official, ScheduleVersionStatus::Superseded]);
    }
}
