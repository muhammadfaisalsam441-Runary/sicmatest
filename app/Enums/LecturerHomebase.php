<?php

namespace App\Enums;

/**
 * Homebase dosen.
 */
enum LecturerHomebase: string
{
    case Jtik = 'jtik';
    case External = 'external';

    /**
     * Label berbahasa Indonesia untuk antarmuka.
     */
    public function label(): string
    {
        return match ($this) {
            self::Jtik => 'Homebase JTIK',
            self::External => 'Luar JTIK',
        };
    }
}
