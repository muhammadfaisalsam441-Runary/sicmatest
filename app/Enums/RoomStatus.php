<?php

namespace App\Enums;

/**
 * Status ruang hasil AvailabilityService. Di UI hanya ada badge "Tersedia" / "Digunakan" (+ "Belum pasti").
 */
enum RoomStatus: string
{
    case Available = 'available';
    case Scheduled = 'scheduled';
    case Booked = 'booked';
    case InUse = 'in_use';
    case Closed = 'closed';
    case Uncertain = 'uncertain';

    /**
     * Badge di UI.
     */
    public function badge(): string
    {
        return match ($this) {
            self::Available => 'Tersedia',
            self::Closed => 'Tutup',
            self::Uncertain => 'Belum pasti',
            default => 'Digunakan',
        };
    }

    /**
     * Keterangan kecil tanpa data pribadi (aman untuk Portal Publik, B19).
     */
    public function label(): string
    {
        return match ($this) {
            self::Available => 'Tersedia',
            self::Scheduled => 'Kuliah terjadwal',
            self::Booked => 'Dipesan',
            self::InUse => 'Sedang dipakai',
            self::Closed => 'Ditutup',
            self::Uncertain => 'Data belum lengkap',
        };
    }

    public function isAvailable(): bool
    {
        return $this === self::Available;
    }
}
