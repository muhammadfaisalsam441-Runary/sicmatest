<?php

namespace App\Models\Room;

use App\Enums\RoomBlockSource;
use App\Models\User\User;
use App\Support\Wita;
use Carbon\CarbonInterface;
use Database\Factories\RoomClosureFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Penutupan ruang sementara maupun berulang (memblokir ruang lewat room_blocks).
 */
#[Fillable(['room_id', 'start_at', 'end_at', 'is_recurring', 'days_of_week', 'daily_start_time', 'daily_end_time', 'reason', 'created_by'])]
class RoomClosure extends Model
{
    /** @use HasFactory<RoomClosureFactory> */
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
            'is_recurring' => 'boolean',
            'days_of_week' => 'array',
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
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<RoomBlock, $this>
     */
    public function blocks(): HasMany
    {
        return $this->hasMany(RoomBlock::class, 'source_id')
            ->where('source_type', RoomBlockSource::Closure);
    }

    /**
     * Penutupan yang sedang memblokir ruang pada waktu $at.
     *
     * @param  Builder<RoomClosure>  $query
     * @return Builder<RoomClosure>
     */
    #[Scope]
    protected function activeAt(Builder $query, CarbonInterface $at): Builder
    {
        return $query->where(function (Builder $q) use ($at): void {
            $q->where(function (Builder $once) use ($at): void {
                $once->where('is_recurring', false)
                    ->where('start_at', '<=', $at)
                    ->where('end_at', '>', $at);
            })->orWhere(function (Builder $recurring) use ($at): void {
                $recurring->where('is_recurring', true)
                    ->whereHas('blocks', fn (Builder $b): Builder => $b
                        ->active()
                        ->where('start_at', '<=', $at)
                        ->where('end_at', '>', $at));
            });
        });
    }

    /**
     * Ringkasan jadwal penutupan untuk tampilan admin.
     */
    public function scheduleSummary(): string
    {
        if (! $this->is_recurring) {
            return Wita::date($this->start_at).' '.Wita::range($this->start_at, $this->end_at);
        }

        $days = collect($this->days_of_week ?? [])
            ->map(fn (mixed $day): int => (int) $day)
            ->unique()
            ->sort()
            ->values()
            ->all();

        $shortNames = [1 => 'Sen', 2 => 'Sel', 3 => 'Rab', 4 => 'Kam', 5 => 'Jum', 6 => 'Sab', 7 => 'Min'];
        $dayLabel = match ($days) {
            [1, 2, 3, 4, 5] => 'Sen–Jum',
            [1, 2, 3, 4, 5, 6, 7] => 'Setiap Hari',
            default => implode(', ', array_map(fn (int $day): string => $shortNames[$day] ?? (string) $day, $days)),
        };

        $startClock = str_replace(':', '.', (string) $this->daily_start_time);
        $endClock = str_replace(':', '.', (string) $this->daily_end_time);

        return "{$dayLabel}, {$startClock}–{$endClock} WITA (".Wita::date($this->start_at).' – '.Wita::date($this->end_at).')';
    }
}
