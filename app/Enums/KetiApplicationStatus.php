<?php

namespace App\Enums;

/**
 * Status pengajuan akun Keti.
 */
enum KetiApplicationStatus: string
{
    case Pending = 'pending';
    case NeedsRevision = 'needs_revision';
    case Approved = 'approved';
    case Rejected = 'rejected';

    /**
     * Label berbahasa Indonesia untuk antarmuka.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Sedang diperiksa',
            self::NeedsRevision => 'Perlu diperbaiki',
            self::Approved => 'Disetujui',
            self::Rejected => 'Ditolak',
        };
    }
}
