<?php

namespace App\Enums;

/**
 * Mode sesi kuliah. Online tidak memblokir ruang (B14), unscheduled = belum terjadwal (B15).
 */
enum SessionMode: string
{
    case Offline = 'offline';
    case Online = 'online';
    case Unscheduled = 'unscheduled';

    /**
     * Label berbahasa Indonesia untuk antarmuka.
     */
    public function label(): string
    {
        return match ($this) {
            self::Offline => 'Luring',
            self::Online => 'Online',
            self::Unscheduled => 'Belum terjadwal',
        };
    }
}
