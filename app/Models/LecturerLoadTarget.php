<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Target dan batas beban SKS dosen per periode (nilai belum ditetapkan = NULL).
 */
#[Fillable(['lecturer_id', 'period_id', 'target_sks', 'min_sks', 'max_sks'])]
class LecturerLoadTarget extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'target_sks' => 'decimal:2',
            'min_sks' => 'decimal:2',
            'max_sks' => 'decimal:2',
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
