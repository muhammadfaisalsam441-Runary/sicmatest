<?php

namespace App\Enums;

/**
 * Jenis ruang.
 */
enum RoomType: string
{
    case Classroom = 'classroom';
    case Lab = 'lab';
    case Workshop = 'workshop';
    case Other = 'other';

    /**
     * Label berbahasa Indonesia untuk antarmuka.
     */
    public function label(): string
    {
        return match ($this) {
            self::Classroom => 'Ruang kelas',
            self::Lab => 'Laboratorium',
            self::Workshop => 'Bengkel',
            self::Other => 'Lainnya',
        };
    }
}
