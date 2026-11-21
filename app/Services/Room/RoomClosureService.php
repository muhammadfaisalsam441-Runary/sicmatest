<?php

namespace App\Services\Room;

use App\Enums\RescheduleStatus;
use App\Enums\ReservationStatus;
use App\Enums\RoomBlockSource;
use App\Exceptions\DomainException;
use App\Models\Academic\SessionReschedule;
use App\Models\Reservation\Reservation;
use App\Models\Room\Room;
use App\Models\Room\RoomBlock;
use App\Models\Room\RoomClosure;
use App\Models\User\User;
use App\Services\Account\AuditLogger;
use App\Services\Account\NotificationService;
use App\Support\AvailabilityCache;
use App\Support\Wita;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
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

    /**
     * Daftar pasangan waktu [start, end] untuk aturan penutupan berulang.
     *
     * @param  list<int>  $daysOfWeek
     * @return SupportCollection<int, array{start: CarbonImmutable, end: CarbonImmutable}>
     */
    public function buildRecurringWindows(
        CarbonInterface $startDate,
        CarbonInterface $endDate,
        string $startTime,
        string $endTime,
        array $daysOfWeek,
    ): SupportCollection {
        [$startHour, $startMinute] = array_map('intval', explode(':', $startTime));
        [$endHour, $endMinute] = array_map('intval', explode(':', $endTime));

        if (($endHour * 60 + $endMinute) <= ($startHour * 60 + $startMinute)) {
            throw new DomainException('INVALID_DURATION', 'Waktu selesai penutupan harus setelah waktu mulai.');
        }

        $timezone = (string) config('app.timezone');
        $from = CarbonImmutable::instance($startDate)->setTimezone($timezone)->startOfDay();
        $to = CarbonImmutable::instance($endDate)->setTimezone($timezone)->startOfDay();

        if ($to->lessThan($from)) {
            throw new DomainException('INVALID_DURATION', 'Tanggal selesai harus sama atau setelah tanggal mulai.');
        }

        $days = array_values(array_unique(array_map('intval', $daysOfWeek)));
        $windows = collect();

        for ($date = $from; $date->lessThanOrEqualTo($to); $date = $date->addDay()) {
            if (! in_array($date->dayOfWeekIso, $days, true)) {
                continue;
            }

            $windows->push([
                'start' => $date->setTime($startHour, $startMinute),
                'end' => $date->setTime($endHour, $endMinute),
            ]);
        }

        return $windows;
    }

    /**
     * Reservasi aktif yang beririsan dengan salah satu jendela waktu penutupan berulang.
     *
     * @param  SupportCollection<int, array{start: CarbonImmutable, end: CarbonImmutable}>  $windows
     * @return Collection<int, Reservation>
     */
    public function impactForWindows(Room $room, SupportCollection $windows): Collection
    {
        if ($windows->isEmpty()) {
            return new Collection;
        }

        return Reservation::query()
            ->whereBelongsTo($room)
            ->whereIn('status', [ReservationStatus::Booked, ReservationStatus::InUse])
            ->where(function (Builder $query) use ($windows): void {
                foreach ($windows as $window) {
                    $query->orWhere(fn (Builder $sub): Builder => $sub
                        ->where('start_at', '<', $window['end'])
                        ->where('end_at', '>', $window['start']));
                }
            })
            ->with(['classGroup', 'creator'])
            ->orderBy('start_at')
            ->get();
    }

    public function create(Room $room, CarbonInterface $start, CarbonInterface $end, string $reason, User $admin, bool $cancelImpacted = false): RoomClosure
    {
        if ($end->lessThanOrEqualTo($start)) {
            throw new DomainException('INVALID_DURATION', 'Waktu selesai penutupan harus setelah waktu mulai.');
        }

        return DB::transaction(function () use ($room, $start, $end, $reason, $admin, $cancelImpacted): RoomClosure {
            Room::query()->whereKey($room->id)->lockForUpdate()->first();

            $closure = RoomClosure::create([
                'room_id' => $room->id,
                'start_at' => $start,
                'end_at' => $end,
                'is_recurring' => false,
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

            $impacted = $this->impact($room, $start, $end);
            $this->handleImpacted($room, $impacted, $reason, $admin, $cancelImpacted);

            return $closure;
        });
    }

    /**
     * Buat penutupan ruang berulang per hari/jam tertentu dalam rentang tanggal.
     *
     * @param  list<int>  $daysOfWeek
     */
    public function createRecurring(
        Room $room,
        CarbonInterface $startDate,
        CarbonInterface $endDate,
        string $startTime,
        string $endTime,
        array $daysOfWeek,
        string $reason,
        User $admin,
        bool $cancelImpacted = false,
    ): RoomClosure {
        $windows = $this->buildRecurringWindows($startDate, $endDate, $startTime, $endTime, $daysOfWeek);

        if ($windows->isEmpty()) {
            throw new DomainException('INVALID_DURATION', 'Tidak ada tanggal yang cocok dengan hari perulangan.');
        }

        $days = array_values(array_unique(array_map('intval', $daysOfWeek)));
        sort($days);

        return DB::transaction(function () use ($room, $windows, $days, $startTime, $endTime, $reason, $admin, $cancelImpacted): RoomClosure {
            Room::query()->whereKey($room->id)->lockForUpdate()->first();

            $closure = RoomClosure::create([
                'room_id' => $room->id,
                'start_at' => $windows->first()['start'],
                'end_at' => $windows->last()['end'],
                'is_recurring' => true,
                'days_of_week' => $days,
                'daily_start_time' => $startTime,
                'daily_end_time' => $endTime,
                'reason' => $reason,
                'created_by' => $admin->id,
            ]);

            $timestamp = now()->format('Y-m-d H:i:s');
            $rows = $windows->map(fn (array $window): array => [
                'room_id' => $room->id,
                'start_at' => $window['start']->format('Y-m-d H:i:s'),
                'end_at' => $window['end']->format('Y-m-d H:i:s'),
                'source_type' => RoomBlockSource::Closure->value,
                'source_id' => $closure->id,
                'is_active' => true,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ])->all();

            foreach (array_chunk($rows, 200) as $chunk) {
                RoomBlock::query()->insert($chunk);
            }

            AvailabilityCache::bust();
            $this->audit->log(
                'room_closure.created',
                $closure,
                null,
                $closure->only(['room_id', 'start_at', 'end_at', 'is_recurring', 'days_of_week', 'daily_start_time', 'daily_end_time', 'reason']),
                $reason,
            );

            $impacted = $this->impactForWindows($room, $windows);
            $this->handleImpacted($room, $impacted, $reason, $admin, $cancelImpacted);

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

            $this->audit->log('room_closure.removed', $closure, $closure->only(['room_id', 'start_at', 'end_at', 'is_recurring', 'days_of_week', 'daily_start_time', 'daily_end_time', 'reason']), null);
            $closure->delete();

            AvailabilityCache::bust();
        });
    }

    /**
     * @param  Collection<int, Reservation>  $impacted
     */
    private function handleImpacted(Room $room, Collection $impacted, string $reason, User $admin, bool $cancelImpacted): void
    {
        $reservations = $cancelImpacted ? app(ReservationService::class) : null;

        foreach ($impacted as $reservation) {
            if (! $cancelImpacted) {
                $this->notifications->notify(
                    $reservation->creator,
                    'room.closed',
                    'Ruang '.$room->name.' ditutup',
                    'Ruang yang kamu pesan ditutup: '.$reason.'. Hubungi admin atau cari ruangan lain.',
                    ['reservation_id' => $reservation->id],
                );

                continue;
            }

            if ($reservation->status === ReservationStatus::InUse) {
                $reservations->finish($reservation, $admin);

                continue;
            }

            $cancelReason = "Penutupan ruang {$room->name}: {$reason}";

            try {
                $reservations->cancel($reservation, $admin, $cancelReason);
            } catch (DomainException $exception) {
                if ($exception->errorCode !== 'RESTORE_NOT_POSSIBLE') {
                    throw $exception;
                }

                $this->cancelUnrestorableReschedule($reservation, $room, $reason, $cancelReason, $admin);
            }
        }
    }

    private function cancelUnrestorableReschedule(Reservation $reservation, Room $room, string $reason, string $cancelReason, User $admin): void
    {
        $locked = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->first();
        if (! $locked || $locked->status !== ReservationStatus::Booked) {
            return;
        }

        $before = $locked->only(['status']);
        $locked->forceFill([
            'status' => ReservationStatus::Cancelled,
            'cancelled_at' => now(),
            'cancelled_by' => $admin->id,
            'cancel_reason' => $cancelReason,
            'lock_version' => $locked->lock_version + 1,
        ])->save();

        $locked->block()->update(['is_active' => false]);

        $reschedule = $locked->session_reschedule_id !== null
            ? SessionReschedule::query()->whereKey($locked->session_reschedule_id)->lockForUpdate()->first()
            : null;

        if ($reschedule !== null && $reschedule->status === RescheduleStatus::Active) {
            $reschedule->forceFill([
                'status' => RescheduleStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $admin->id,
            ])->save();

            $this->audit->log(
                'meeting.reschedule_cancelled',
                $reschedule,
                ['status' => RescheduleStatus::Active->value],
                ['status' => RescheduleStatus::Cancelled->value],
                $cancelReason,
            );

            $classIds = array_map('intval', array_filter(explode(',', $reschedule->participant_key)));
            $this->notifications->notify(
                $this->notifications->ketisOf($classIds)->reject(fn (User $user): bool => in_array($user->id, [$admin->id, $locked->created_by], true)),
                'meeting.reschedule_cancelled',
                'Pemindahan pertemuan dibatalkan',
                "{$reschedule->session_title} di {$room->name} dibatalkan karena penutupan ruang: {$reason}. Jadwal asli tidak dapat dipulihkan.",
                ['reservation_id' => $locked->id],
            );
        }

        AvailabilityCache::bust();

        $this->audit->log('reservation.cancelled', $locked, $before, $locked->only(['status', 'cancelled_at', 'cancelled_by']), $cancelReason);

        $this->notifications->notify(
            $locked->creator,
            'reservation.cancelled',
            'Reservasi dibatalkan admin',
            $room->name.' '.Wita::date($locked->start_at).' '.Wita::range($locked->start_at, $locked->end_at).' dibatalkan. Alasan: '.$cancelReason,
            ['reservation_id' => $locked->id],
        );
    }
}
