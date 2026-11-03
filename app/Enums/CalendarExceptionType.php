<?php

namespace App\Enums;

/**
 * Jenis pengecualian kalender akademik.
 */
enum CalendarExceptionType: string
{
    case Holiday = 'holiday';
    case NoClass = 'no_class';

    /**
     * Label berbahasa Indonesia untuk antarmuka.
     */
    public function label(): string
    {
        return match ($this) {
            self::Holiday => 'Hari libur',
            self::NoClass => 'Tidak ada perkuliahan',
        };
    }
}
