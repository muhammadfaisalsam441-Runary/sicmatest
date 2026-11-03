<?php

namespace App\Enums;

/**
 * Peran dosen dalam tim pengajar.
 */
enum TeachingRole: string
{
    case Lead = 'lead';
    case Partner = 'partner';

    /**
     * Label berbahasa Indonesia untuk antarmuka.
     */
    public function label(): string
    {
        return match ($this) {
            self::Lead => 'Pengampu',
            self::Partner => 'Mitra',
        };
    }
}
