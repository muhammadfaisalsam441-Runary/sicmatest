<?php

namespace App\Services;

use App\Enums\ReservationStatus;
use App\Models\AppNotification;
use App\Models\Reservation;
use App\Support\AvailabilityCache;
use App\Support\Wita;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Menandai reservasi yang tidak dimulai setelah jendela check-in dan pemakaian yang melewati batas.
 * Status TIDAK diubah otomatis kecuali config('sicma.reservation.auto_expire_no_show') diaktifkan.
 */
class OverdueReservationService
{
    public function __construct(
        private NotificationService $notifications,
        private AuditLogger $audit,
    ) {}

    /**
     * Reservasi berstatus Dipesan yang jendela check-in-nya sudah lewat.
     *
     * @return Collection<int, Reservation>
     */
    public function notStarted(): Collection
    {
        return Reservation::query()
            ->with(['room', 'classGroup', 'creator'])
            ->where('status', ReservationStatus::Booked)
            ->where('start_at', '<=', now()->subMinutes((int) config('sicma.reservation.checkin_grace_minutes')))
            ->orderBy('start_at')
            ->get();
    }

    /**
     * Reservasi yang masih Digunakan melewati batas penggunaan.
     *
     * @return Collection<int, Reservation>
     */
    public function overrunning(): Collection
    {
        return Reservation::query()
            ->with(['room', 'classGroup', 'creator'])
            ->where('status', ReservationStatus::InUse)
            ->where('end_at', '<', now())
            ->orderBy('end_at')
            ->get();
    }

    /**
     * Kirim pemberitahuan sekali per reservasi; batalkan otomatis bila kebijakannya diaktifkan.
     *
     * @return array{not_started: int, overrunning: int, expired: int}
     */
    public function flag(): array
    {
        $counts = ['not_started' => 0, 'overrunning' => 0, 'expired' => 0];

        foreach ($this->notStarted() as $reservation) {
            if ($this->notifyOnce($reservation, 'reservation.not_started', 'Reservasi belum dimulai: '.$reservation->room->name,
                $reservation->classGroup->name.' belum menekan Mulai gunakan untuk '.Wita::date($reservation->start_at).' '.Wita::range($reservation->start_at, $reservation->end_at).'.')) {
                $counts['not_started']++;
            }

            if (config('sicma.reservation.auto_expire_no_show')) {
                $this->expire($reservation);
                $counts['expired']++;
            }
        }

        foreach ($this->overrunning() as $reservation) {
            if ($this->notifyOnce($reservation, 'reservation.overrun', 'Pemakaian melewati batas: '.$reservation->room->name,
                $reservation->classGroup->name.' belum menekan Selesai menggunakan. Batas penggunaan '.Wita::clock($reservation->end_at).'.')) {
                $counts['overrunning']++;
            }
        }

        return $counts;
    }

    private function notifyOnce(Reservation $reservation, string $type, string $title, string $body): bool
    {
        $alreadySent = AppNotification::query()
            ->where('type', $type)
            ->where('data->reservation_id', $reservation->id)
            ->exists();

        if ($alreadySent) {
            return false;
        }

        $this->notifications->notify([$reservation->creator, ...$this->notifications->admins()], $type, $title, $body, ['reservation_id' => $reservation->id]);

        return true;
    }

    private function expire(Reservation $reservation): void
    {
        DB::transaction(function () use ($reservation): void {
            $reservation->forceFill([
                'status' => ReservationStatus::Cancelled,
                'cancelled_at' => now(),
                'cancel_reason' => 'Tidak dimulai sampai jendela check-in berakhir (otomatis).',
                'lock_version' => $reservation->lock_version + 1,
            ])->save();

            $reservation->block()->update(['is_active' => false]);
            AvailabilityCache::bust();

            $this->audit->log('reservation.expired', $reservation, ['status' => 'booked'], ['status' => 'cancelled'], 'Tidak dimulai (otomatis)');
        });
    }
}
