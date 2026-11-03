<?php

namespace App\Models;

use App\Enums\CalendarExceptionType;
use Database\Factories\CalendarExceptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hari libur / hari tanpa perkuliahan dalam periode.
 */
#[Fillable(['period_id', 'date', 'type', 'note'])]
class CalendarException extends Model
{
    /** @use HasFactory<CalendarExceptionFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'type' => CalendarExceptionType::class,
        ];
    }

    /**
     * @return BelongsTo<Period, $this>
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }
}
