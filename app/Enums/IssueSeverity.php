<?php

namespace App\Enums;

/**
 * Tingkat masalah draf jadwal.
 */
enum IssueSeverity: string
{
    case Error = 'error';
    case Warning = 'warning';

    /**
     * Label berbahasa Indonesia untuk antarmuka.
     */
    public function label(): string
    {
        return match ($this) {
            self::Error => 'Masalah wajib',
            self::Warning => 'Peringatan',
        };
    }
}
