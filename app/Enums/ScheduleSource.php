<?php

namespace App\Enums;

/**
 * Asal versi jadwal.
 */
enum ScheduleSource: string
{
    case Manual = 'manual';
    case Import = 'import';
    case Generated = 'generated';

    /**
     * Label berbahasa Indonesia untuk antarmuka.
     */
    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::Import => 'Impor',
            self::Generated => 'Susun otomatis',
        };
    }
}
