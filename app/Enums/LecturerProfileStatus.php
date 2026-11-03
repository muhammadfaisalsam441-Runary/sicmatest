<?php

namespace App\Enums;

/**
 * Status verifikasi profil dosen.
 */
enum LecturerProfileStatus: string
{
    case Unverified = 'unverified';
    case Verified = 'verified';

    /**
     * Label berbahasa Indonesia untuk antarmuka.
     */
    public function label(): string
    {
        return match ($this) {
            self::Unverified => 'Perlu verifikasi',
            self::Verified => 'Terverifikasi',
        };
    }
}
