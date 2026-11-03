<?php

namespace App\Enums;

/**
 * Status proses susun jadwal otomatis.
 */
enum OptimizationJobStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Finished = 'finished';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /**
     * Label berbahasa Indonesia untuk antarmuka.
     */
    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Dalam antrean',
            self::Running => 'Sedang menyusun',
            self::Finished => 'Selesai',
            self::Failed => 'Gagal',
            self::Cancelled => 'Dibatalkan',
        };
    }
}
