<?php

namespace App\Services\Room;

use App\Enums\ReservationStatus;
use App\Enums\RoomBlockSource;
use App\Exceptions\DomainException;
use App\Models\Reservation\Reservation;
use App\Models\Room\Room;
use App\Models\Room\RoomBlock;
use App\Models\Room\RoomClosure;
use App\Models\User\User;
use App\Services\Account\AuditLogger;
use App\Services\Account\NotificationService;
use App\Support\AvailabilityCache;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Penutupan ruang. Reservasi yang terdampak TIDAK dibatalkan diam-diam (B10): admin melihat
 * pratinjau dampak dan membatalkannya sendiri dengan alasan bila perlu.
 */
class RoomClosureService
{
    public function __construct(
        private AuditLogger $audit,
        private NotificationService $notifications,
    ) {}

    /**
     * Reservasi aktif yang beririsan dengan rencana penutupan.
     *
     * @return Collection<int, Reservation>
     */
    public function impact(Room $room, CarbonInterface $start, CarbonInterface $end): Collection
    {
        return Reservation::query()
            ->whereBelongsTo($room)
            ->whereIn('status', [ReservationStatus::Booked, ReservationStatus::InUse])
            ->where('start_at', '<', $end)
            ->where('end_at', '>', $start)
            ->with(['classGroup', 'creator'])
            ->orderBy('start_at')
            ->get();
    }

    public function create(Room $room, CarbonInterface $start, CarbonInterface $end, string $reason, User $admin): RoomClosure
    {
        if ($end->lessThanOrEqualTo($start)) {
            throw new DomainException('INVALID_DURATION', 'Waktu selesai penutupan harus setelah waktu mulai.');
        }

        return DB::transaction(function () use ($room, $start, $end, $reason, $admin): RoomClosure {
            Room::query()->whereKey($room->id)->lockForUpdate()->first();

            $closure = RoomClosure::create([
                'room_id' => $room->id,
                'start_at' => $start,
                'end_at' => $end,
                'reason' => $reason,
                'created_by' => $admin->id,
            ]);

            RoomBlock::create([
                'room_id' => $room->id,
                'start_at' => $start,
                'end_at' => $end,
                'source_type' => RoomBlockSource::Closure,
                'source_id' => $closure->id,
                'is_active' => true,
            ]);

            AvailabilityCache::bust();
            $this->audit->log('room_closure.created', $closure, null, $closure->only(['room_id', 'start_at', 'end_at', 'reason']), $reason);

            foreach ($this->impact($room, $start, $end) as $reservation) {
                $this->notifications->notify(
                    $reservation->creator,
                    'room.closed',
                    'Ruang '.$room->name.' ditutup',
                    'Ruang yang kamu pesan ditutup: '.$reason.'. Hubungi admin atau cari ruangan lain.',
                    ['reservation_id' => $reservation->id],
                );
            }

            return $closure;
        });
    }

    public function remove(RoomClosure $closure, User $admin): void
    {
        DB::transaction(function () use ($closure): void {
            RoomBlock::query()
                ->where('source_type', RoomBlockSource::Closure)
                ->where('source_id', $closure->id)
                ->update(['is_active' => false]);

            $this->audit->log('room_closure.removed', $closure, $closure->only(['room_id', 'start_at', 'end_at', 'reason']), null);
            $closure->delete();

            AvailabilityCache::bust();
        });
    }
}
