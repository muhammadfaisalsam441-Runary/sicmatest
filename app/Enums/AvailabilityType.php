<?php

namespace App\Enums;

/**
 * Jenis ketersediaan dosen.
 */
enum AvailabilityType: string
{
    case Available = 'available';
    case Unavailable = 'unavailable';

    /**
     * Label berbahasa Indonesia untuk antarmuka.
     */
    public function label(): string
    {
        return match ($this) {
            self::Available => 'Bersedia',
            self::Unavailable => 'Tidak bersedia',
        };
    }
}
