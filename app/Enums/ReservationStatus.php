<?php

namespace App\Enums;

/**
 * Status reservasi (B12): booked → in_use → finished, atau booked → cancelled.
 */
enum ReservationStatus: string
{
    case Booked = 'booked';
    case InUse = 'in_use';
    case Finished = 'finished';
    case Cancelled = 'cancelled';

    /**
     * Label berbahasa Indonesia untuk antarmuka.
     */
    public function label(): string
    {
        return match ($this) {
            self::Booked => 'Dipesan',
            self::InUse => 'Digunakan',
            self::Finished => 'Selesai',
            self::Cancelled => 'Dibatalkan',
        };
    }

    /**
     * Reservasi berstatus ini masih memblokir ruang.
     */
    public function isActive(): bool
    {
        return in_array($this, [self::Booked, self::InUse], true);
    }

    /**
     * Varian badge di UI: Dipesan (biru), Digunakan (merah), Selesai (hijau), Dibatalkan (netral).
     */
    public function badgeVariant(): string
    {
        return match ($this) {
            self::Booked => 'primary',
            self::InUse => 'danger',
            self::Finished => 'success',
            self::Cancelled => 'neutral',
        };
    }
}
