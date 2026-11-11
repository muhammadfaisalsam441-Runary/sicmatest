<?php

namespace App\View\Composers;

use App\Models\User\User;
use App\Services\Room\ReservationService;
use Illuminate\View\View;

/**
 * Data modal "Pesan ruang" Portal Keti: kelas, pilihan kegiatan, dan kegiatan bawaan.
 * Halaman pemakai mengirim $academicAllowed sesuai tanggal yang sedang dicari.
 */
class BookingModalComposer
{
    public function __construct(private ReservationService $reservations) {}

    public function compose(View $view): void
    {
        /** @var User|null $user */
        $user = auth()->user();
        $classGroup = $user?->activeClassGroup();
        $academicAllowed = (bool) ($view->getData()['academicAllowed'] ?? true);
        $academic = config('sicma.reservation.academic_purposes');
        $purposes = config('sicma.reservation.purposes');
        $allowed = array_values(array_filter($purposes, fn (string $purpose): bool => $academicAllowed || ! in_array($purpose, $academic, true)));

        $last = $classGroup === null ? null : $this->reservations->lastPurpose($classGroup);
        $default = match (true) {
            $last !== null && in_array($last, $allowed, true) => $last,
            default => $allowed[0] ?? null,
        };

        $view->with([
            'bookingClassGroup' => $classGroup,
            'bookingPurposes' => array_combine($purposes, $purposes),
            'bookingDisabledPurposes' => $academicAllowed ? [] : $academic,
            'bookingDefaultPurpose' => $default,
            'academicAllowed' => $academicAllowed,
        ]);
    }
}
