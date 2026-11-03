<?php

namespace App\Policies;

use App\Models\Reservation;
use App\Models\User;

/**
 * Reservasi milik kelas: hanya Keti aktif kelas tersebut yang boleh melihat & mengelola (B02, B17).
 */
class ReservationPolicy
{
    public function view(User $user, Reservation $reservation): bool
    {
        return $user->isAdmin() || $this->isRepresentativeOf($user, $reservation);
    }

    /**
     * Mulai gunakan, selesai menggunakan, atau batalkan sebagai Keti.
     */
    public function manage(User $user, Reservation $reservation): bool
    {
        return $user->isApprovedKeti() && $this->isRepresentativeOf($user, $reservation);
    }

    /**
     * Admin membatalkan reservasi dengan alasan (Riwayat Reservasi).
     */
    public function cancelAsAdmin(User $user, Reservation $reservation): bool
    {
        return $user->isAdmin();
    }

    private function isRepresentativeOf(User $user, Reservation $reservation): bool
    {
        return $user->isKeti() && $user->activeClassGroup()?->id === $reservation->class_group_id;
    }
}
