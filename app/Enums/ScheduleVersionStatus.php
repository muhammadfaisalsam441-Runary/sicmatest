<?php

namespace App\Enums;

/**
 * Status versi jadwal. Hanya official yang dibaca portal (B09).
 */
enum ScheduleVersionStatus: string
{
    case Draft = 'draft';
    case Ready = 'ready';
    case Official = 'official';
    case Superseded = 'superseded';
    case Archived = 'archived';

    /**
     * Label berbahasa Indonesia untuk antarmuka.
     */
    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draf',
            self::Ready => 'Siap ditetapkan',
            self::Official => 'Jadwal resmi',
            self::Superseded => 'Digantikan',
            self::Archived => 'Diarsipkan',
        };
    }
}
