<?php

namespace App\Models\Room;

use App\Enums\RoomBlockSource;
use App\Models\Scheduling\ScheduleVersion;
use Carbon\CarbonInterface;
use Database\Factories\RoomBlockFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pemblokiran ruang — satu-satunya sumber pengecekan bentrok.
 */
#[Fillable(['room_id', 'start_at', 'end_at', 'source_type', 'source_id', 'schedule_version_id', 'is_active'])]
class RoomBlock extends Model
{
    /** @use HasFactory<RoomBlockFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'source_type' => RoomBlockSource::class,
            'source_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * @return BelongsTo<ScheduleVersion, $this>
     */
    public function scheduleVersion(): BelongsTo
    {
        return $this->belongsTo(ScheduleVersion::class);
    }

    /**
     * Blok yang masih berlaku.
     */
    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Blok yang beririsan dengan interval setengah terbuka [start, end) (B03).
     */
    #[Scope]
    protected function overlapping(Builder $query, CarbonInterface $start, CarbonInterface $end): Builder
    {
        return $query->where('start_at', '<', $end)->where('end_at', '>', $start);
    }
}
