<?php

namespace App\Enums;

/**
 * Sumber pemblokiran ruang di tabel room_blocks.
 */
enum RoomBlockSource: string
{
    case Schedule = 'schedule';
    case Reservation = 'reservation';
    case Closure = 'closure';

    /**
     * Label berbahasa Indonesia untuk antarmuka.
     */
    public function label(): string
    {
        return match ($this) {
            self::Schedule => 'Jadwal resmi',
            self::Reservation => 'Reservasi',
            self::Closure => 'Penutupan ruang',
        };
    }
}
