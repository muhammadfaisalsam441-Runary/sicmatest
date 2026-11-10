<?php

namespace App\Models\Lecturer;

use App\Enums\AvailabilityType;
use App\Models\Academic\Period;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ketersediaan/ketidaktersediaan dosen per hari dan rentang menit.
 */
#[Fillable(['lecturer_id', 'period_id', 'day_of_week', 'start_minute', 'end_minute', 'type'])]
class LecturerAvailability extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'start_minute' => 'integer',
            'end_minute' => 'integer',
            'type' => AvailabilityType::class,
        ];
    }

    /**
     * @return BelongsTo<Lecturer, $this>
     */
    public function lecturer(): BelongsTo
    {
        return $this->belongsTo(Lecturer::class);
    }

    /**
     * @return BelongsTo<Period, $this>
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }
}
