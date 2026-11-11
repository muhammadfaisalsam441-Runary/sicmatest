<?php

namespace App\Policies;

use App\Models\Reservation\Reservation;
use App\Models\User\User;

/**
 * Reservasi milik kelas: hanya Keti aktif kelas tersebut yang boleh melihat & mengelola (B02, B17).
 */
class ReservationPolicy
{
    public function view(User $user, Reservation $reservation): bool
    {
        return $user->isAdmin() || $this->isRepresentativeOf($user, $reservation) || $this->isJointClassOf($user, $reservation);
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

    /**
     * Keti kelas peserta lain dari pertemuan kuliah yang dipindah boleh melihat penggantinya (kelas gabungan).
     */
    private function isJointClassOf(User $user, Reservation $reservation): bool
    {
        $classGroupId = $user->isKeti() ? $user->activeClassGroup()?->id : null;
        $participantKey = $reservation->sessionReschedule?->participant_key;

        return $classGroupId !== null && $participantKey !== null
            && in_array((string) $classGroupId, explode(',', $participantKey), true);
    }

    private function isRepresentativeOf(User $user, Reservation $reservation): bool
    {
        return $user->isKeti() && $user->activeClassGroup()?->id === $reservation->class_group_id;
    }
}
