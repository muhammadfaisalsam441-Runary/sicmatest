<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\ClassRepresentativeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Keti yang terhubung ke satu kelas pada rentang tanggal tertentu.
 */
#[Fillable(['user_id', 'class_group_id', 'valid_from', 'valid_to'])]
class ClassRepresentative extends Model
{
    /** @use HasFactory<ClassRepresentativeFactory> */
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<ClassGroup, $this>
     */
    public function classGroup(): BelongsTo
    {
        return $this->belongsTo(ClassGroup::class);
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
}
