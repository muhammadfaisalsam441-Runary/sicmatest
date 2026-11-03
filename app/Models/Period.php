<?php

namespace App\Models;

use Database\Factories\PeriodFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Periode/semester akademik, mis. "Ganjil 2026/2027".
 */
#[Fillable(['name', 'start_date', 'end_date', 'timezone', 'uts_start', 'uts_end', 'uas_start', 'uas_end', 'is_active'])]
class Period extends Model
{
    /** @use HasFactory<PeriodFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'uts_start' => 'date',
            'uts_end' => 'date',
            'uas_start' => 'date',
            'uas_end' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<CalendarException, $this>
     */
    public function calendarExceptions(): HasMany
    {
        return $this->hasMany(CalendarException::class);
    }

    /**
     * @return HasMany<TimeSlot, $this>
     */
    public function timeSlots(): HasMany
    {
        return $this->hasMany(TimeSlot::class);
    }

    /**
     * @return HasMany<SchedulingRule, $this>
     */
    public function schedulingRules(): HasMany
    {
        return $this->hasMany(SchedulingRule::class);
    }

    /**
     * @return HasMany<CourseOffering, $this>
     */
    public function courseOfferings(): HasMany
    {
        return $this->hasMany(CourseOffering::class);
    }

    /**
     * @return HasMany<ScheduleVersion, $this>
     */
    public function scheduleVersions(): HasMany
    {
        return $this->hasMany(ScheduleVersion::class);
    }

    /**
     * Periode yang sedang aktif.
     */
    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
