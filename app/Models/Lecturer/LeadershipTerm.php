<?php

namespace App\Models\Lecturer;

use Carbon\CarbonInterface;
use Database\Factories\LeadershipTermFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Masa jabatan pimpinan seorang dosen.
 */
#[Fillable(['lecturer_id', 'position', 'valid_from', 'valid_to'])]
class LeadershipTerm extends Model
{
    /** @use HasFactory<LeadershipTermFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'valid_from' => 'date',
            'valid_to' => 'date',
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
     * Batasi ke data yang berlaku pada tanggal tertentu (valid_to kosong = masih berlaku).
     */
    #[Scope]
    protected function activeOn(Builder $query, CarbonInterface $date): Builder
    {
        $day = $date->toDateString();

        return $query->whereDate('valid_from', '<=', $day)
            ->where(fn (Builder $query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $day));
    }

    /**
     * Masa jabatan berlaku pada tanggal tersebut (inklusif di kedua ujung).
     */
    public function isActiveOn(CarbonInterface $date): bool
    {
        $day = $date->toDateString();

        return $this->valid_from->toDateString() <= $day
            && ($this->valid_to === null || $this->valid_to->toDateString() >= $day);
    }
}
