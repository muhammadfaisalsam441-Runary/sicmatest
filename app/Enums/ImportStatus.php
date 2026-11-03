<?php

namespace App\Enums;

/**
 * Status file impor jadwal.
 */
enum ImportStatus: string
{
    case Uploaded = 'uploaded';
    case Checked = 'checked';
    case Committed = 'committed';
    case Failed = 'failed';

    /**
     * Label berbahasa Indonesia untuk antarmuka.
     */
    public function label(): string
    {
        return match ($this) {
            self::Uploaded => 'Diunggah',
            self::Checked => 'Sudah diperiksa',
            self::Committed => 'Disimpan ke draf',
            self::Failed => 'Gagal',
        };
    }
}
