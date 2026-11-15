<?php

namespace App\Enums;

/**
 * Mode penggantian satu pertemuan kuliah oleh Keti: memindahkan ke ruang fisik lain atau mengalihkan ke Online.
 */
enum RescheduleMode: string
{
    case Room = 'room';
    case Online = 'online';

    /**
     * Label berbahasa Indonesia untuk antarmuka.
     */
    public function label(): string
    {
        return match ($this) {
            self::Room => 'Ruangan pengganti',
            self::Online => 'Online',
        };
    }
}
