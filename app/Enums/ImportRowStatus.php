<?php

namespace App\Enums;

/**
 * Status baris impor jadwal.
 */
enum ImportRowStatus: string
{
    case Ok = 'ok';
    case Warning = 'warning';
    case Error = 'error';
    case Skipped = 'skipped';

    /**
     * Label berbahasa Indonesia untuk antarmuka.
     */
    public function label(): string
    {
        return match ($this) {
            self::Ok => 'Sesuai',
            self::Warning => 'Perlu dicek',
            self::Error => 'Bermasalah',
            self::Skipped => 'Dilewati',
        };
    }
}
