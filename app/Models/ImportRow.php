<?php

namespace App\Models;

use App\Enums\ImportRowStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris file impor beserta jejak asal dan masalahnya.
 */
#[Fillable(['import_id', 'row_no', 'raw', 'parsed', 'issues', 'status'])]
class ImportRow extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'row_no' => 'integer',
            'raw' => 'array',
            'parsed' => 'array',
            'issues' => 'array',
            'status' => ImportRowStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Import, $this>
     */
    public function import(): BelongsTo
    {
        return $this->belongsTo(Import::class);
    }
}
