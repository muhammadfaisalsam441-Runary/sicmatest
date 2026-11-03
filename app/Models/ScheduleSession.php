<?php

namespace App\Models;

use App\Enums\SessionMode;
use App\Enums\SessionPhase;
use Database\Factories\ScheduleSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Sesi kuliah mingguan dalam satu versi jadwal.
 */
#[Fillable(['schedule_version_id', 'course_offering_id', 'title', 'mode', 'day_of_week', 'start_minute', 'end_minute', 'room_id', 'phase', 'source_file', 'source_row', 'source_raw', 'is_locked'])]
class ScheduleSession extends Model
{
    /** @use HasFactory<ScheduleSessionFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mode' => SessionMode::class,
            'day_of_week' => 'integer',
            'start_minute' => 'integer',
            'end_minute' => 'integer',
            'phase' => SessionPhase::class,
            'source_row' => 'integer',
            'source_raw' => 'array',
            'is_locked' => 'boolean',
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
     * @return BelongsTo<CourseOffering, $this>
     */
    public function courseOffering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class);
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * @return BelongsToMany<ClassGroup, $this>
     */
    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(ClassGroup::class, 'session_participants');
    }

    /**
     * @return BelongsToMany<Lecturer, $this>
     */
    public function lecturers(): BelongsToMany
    {
        return $this->belongsToMany(Lecturer::class, 'session_lecturers')->withPivot('role');
    }

    /**
     * Sesi menempati ruang fisik: luring, punya ruang, hari, dan jam (B14, B15).
     */
    public function blocksRoom(): bool
    {
        return $this->mode === SessionMode::Offline
            && $this->room_id !== null
            && $this->day_of_week !== null
            && $this->start_minute !== null
            && $this->end_minute !== null
            && $this->end_minute > $this->start_minute;
    }
}
