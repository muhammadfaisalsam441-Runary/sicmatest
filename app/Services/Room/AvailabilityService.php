<?php

namespace App\Services\Room;

use App\Enums\ReservationStatus;
use App\Enums\RoomBlockSource;
use App\Enums\RoomStatus;
use App\Models\Academic\Period;
use App\Models\Reservation\Reservation;
use App\Models\Room\Room;
use App\Models\Room\RoomBlock;
use App\Models\Scheduling\ScheduleVersion;
use App\Support\Wita;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Satu-satunya penghitung status ruang untuk Portal Publik, Portal Keti, dan dashboard admin.
 *
 * Urutan keputusan: ruang aktif & bisa dipesan → jam operasional → room_blocks aktif yang beririsan
 * dengan [mulai, selesai) (B03, B05, B06). Blok jadwal hanya dihitung bila versinya berstatus official (B09).
 */
class AvailabilityService
{
    /**
     * Status satu ruang. Tanpa $end, status dihitung untuk satu menit mulai $start ("sekarang").
     */
    public function forRoom(Room $room, CarbonInterface $start, ?CarbonInterface $end = null): AvailabilityResult
    {
        return $this->forRooms(collect([$room]), $start, $end)->first();
    }

    /**
     * Status banyak ruang sekaligus (satu query blok).
     *
     * @param  Collection<int, Room>  $rooms
     * @return Collection<int, AvailabilityResult>
     */
    public function forRooms(Collection $rooms, CarbonInterface $start, ?CarbonInterface $end = null): Collection
    {
        $start = $this->normalize($start);
        $end = $end === null ? $start->addMinute() : $this->normalize($end);
        [$open, $close] = $this->operatingWindow($start);

        $blocks = $this->blocksFor($rooms->map(fn (Room $room): int => $room->id)->values()->all(), $start, $end->max($close));
        $reservationStatuses = $this->reservationStatuses($blocks);
        $hasOfficialSchedule = $this->hasOfficialScheduleOn($start);
        $checkedAt = CarbonImmutable::now();

        return $rooms->values()->map(fn (Room $room): AvailabilityResult => $this->evaluate(
            $room,
            $start,
            $end,
            $open,
            $close,
            $blocks->get($room->id, collect()),
            $reservationStatuses,
            $hasOfficialSchedule,
            $checkedAt,
        ));
    }

    /**
     * Cari ruang sesuai kriteria (kata kunci, gedung, lantai, kapasitas, status, urutan).
     *
     * @return Collection<int, AvailabilityResult>
     */
    public function search(SearchCriteria $criteria): Collection
    {
        $rooms = $this->matchingRooms($criteria);

        $results = $this->forRooms($rooms, $criteria->start, $criteria->end());

        $results = match ($criteria->status) {
            'available' => $results->filter->isAvailable(),
            'busy' => $results->filter(fn (AvailabilityResult $result): bool => in_array($result->status, [RoomStatus::Scheduled, RoomStatus::Booked, RoomStatus::InUse], true)),
            'closed' => $results->where('status', RoomStatus::Closed),
            default => $results,
        };

        return $this->sort($results, $criteria->sort)->values();
    }

    /**
     * Celah kosong harian dan pilihan jam mulai yang cukup untuk durasi pemesanan.
     *
     * @return Collection<int, array{room: Room, windows: list<array{start: CarbonImmutable, end: CarbonImmutable, starts: list<CarbonImmutable>}>}>
     */
    public function dailySlots(SearchCriteria $criteria): Collection
    {
        $now = $this->normalize(CarbonImmutable::now());
        [$open, $close] = $this->operatingWindow($criteria->start);
        $from = $open->max($now);
        $duration = $criteria->durationMinutes;

        if ($from->greaterThanOrEqualTo($close)
            || $open->greaterThan($now->addDays((int) config('sicma.reservation.max_days_ahead'))->endOfDay())
            || $duration < (int) config('sicma.reservation.min_minutes')
            || $duration > (int) config('sicma.reservation.max_minutes')
            || ! $this->hasOfficialScheduleOn($open)) {
            return collect();
        }

        $rooms = $this->matchingRooms($criteria)->filter->is_bookable;
        $blocks = $this->blocksFor($rooms->modelKeys(), $from, $close);

        return $rooms->map(function (Room $room) use ($blocks, $from, $close, $duration): array {
            $cursor = $from;
            $windows = [];
            foreach ($blocks->get($room->id, collect()) as $block) {
                $end = CarbonImmutable::instance($block->start_at)->min($close);
                if ($cursor->addMinutes($duration)->lessThanOrEqualTo($end)) {
                    $windows[] = $this->bookingWindow($cursor, $end, $duration);
                }
                $cursor = $cursor->max($block->end_at);
            }
            if ($cursor->addMinutes($duration)->lessThanOrEqualTo($close)) {
                $windows[] = $this->bookingWindow($cursor, $close, $duration);
            }

            return ['room' => $room, 'windows' => $windows];
        })->filter(fn (array $row): bool => $row['windows'] !== [])->values();
    }

    /**
     * Slot terdekat untuk perencanaan, termasuk ketika ruang sedang tutup atau terpakai.
     */
    public function nextBookingStart(Room $room, int $duration): ?CarbonImmutable
    {
        if (! $room->is_active || ! $room->is_bookable) {
            return null;
        }

        $today = CarbonImmutable::today();
        $lastDate = $today->addDays((int) config('sicma.reservation.max_days_ahead'));
        for ($date = $today; $date->lessThanOrEqualTo($lastDate); $date = $date->addDay()) {
            $row = $this->dailySlots(new SearchCriteria(start: $date, durationMinutes: $duration, roomId: $room->id))->first();
            if ($row !== null) {
                return $row['windows'][0]['start'];
            }
        }

        return null;
    }

    /**
     * @return array{start: CarbonImmutable, end: CarbonImmutable, starts: list<CarbonImmutable>}
     */
    private function bookingWindow(CarbonImmutable $start, CarbonImmutable $end, int $duration): array
    {
        $lastStart = $end->subMinutes($duration);
        $starts = [];
        for ($time = $start; $time->lessThanOrEqualTo($lastStart); $time = $time->addMinutes(15)) {
            $starts[] = $time;
        }
        if (! $starts[array_key_last($starts)]->equalTo($lastStart)) {
            $starts[] = $lastStart;
        }

        return ['start' => $start, 'end' => $end, 'starts' => $starts];
    }

    /**
     * @return Collection<int, Room>
     */
    private function matchingRooms(SearchCriteria $criteria): Collection
    {
        return Room::query()
            ->with(['building', 'facilities'])
            ->where('is_active', true)
            ->when($criteria->roomId !== null, fn (Builder $query) => $query->whereKey($criteria->roomId))
            ->when($criteria->keyword, function (Builder $query, string $keyword): void {
                $query->where(fn (Builder $query) => $query
                    ->where('name', 'like', "%{$keyword}%")
                    ->orWhere('code', 'like', "%{$keyword}%")
                    ->orWhereHas('building', fn (Builder $query) => $query->where('name', 'like', "%{$keyword}%")));
            })
            ->when($criteria->building === 'none', fn (Builder $query) => $query->whereNull('building_id'))
            ->when(is_numeric($criteria->building), fn (Builder $query) => $query->where('building_id', (int) $criteria->building))
            ->when($criteria->floor !== null, fn (Builder $query) => $query->where('floor', $criteria->floor))
            ->when($criteria->minCapacity !== null, fn (Builder $query) => $query->where('capacity', '>=', $criteria->minCapacity))
            ->orderBy('name')
            ->get();
    }

    /**
     * Ringkasan Semua / Tersedia / Digunakan untuk tab ringkasan dan dashboard.
     *
     * @return array{all: int, available: int, busy: int, uncertain: int, by_building: array<string, int>}
     */
    public function summary(CarbonInterface $at): array
    {
        $rooms = Room::query()->with('building')->where('is_active', true)->get();
        $results = $this->forRooms($rooms, $at);

        return [
            'all' => $results->count(),
            'available' => $results->filter->isAvailable()->count(),
            'busy' => $results->filter(fn (AvailabilityResult $result): bool => in_array($result->status, [RoomStatus::Scheduled, RoomStatus::Booked, RoomStatus::InUse], true))->count(),
            'closed' => $results->where('status', RoomStatus::Closed)->count(),
            'uncertain' => $results->where('status', RoomStatus::Uncertain)->count(),
            'by_building' => $rooms->countBy(fn (Room $room): string => $room->building?->name ?? 'Gedung belum didata')->all(),
        ];
    }

    /**
     * "Tersedia sampai": akhir waktu kosong mulai $from, atau null bila ruang sedang terpakai.
     */
    public function freeUntil(Room $room, CarbonInterface $from): ?CarbonImmutable
    {
        return $this->forRoom($room, $from)->freeUntil;
    }

    /**
     * "Selesai pukul": akhir rangkaian pemakaian yang sedang berlangsung, atau null bila ruang kosong.
     */
    public function busyUntil(Room $room, CarbonInterface $from): ?CarbonImmutable
    {
        return $this->forRoom($room, $from)->busyUntil;
    }

    /**
     * Blok aktif yang dihitung untuk ketersediaan, beririsan dengan [from, until).
     * Dengan $lock, dibaca sebagai locking read (data terbaru) untuk pemeriksaan bentrok di dalam transaksi.
     *
     * @param  list<int>  $roomIds
     * @return Collection<int, Collection<int, RoomBlock>>
     */
    public function blocksFor(array $roomIds, CarbonInterface $from, CarbonInterface $until, bool $lock = false): Collection
    {
        if ($roomIds === []) {
            return collect();
        }

        return RoomBlock::query()
            ->whereIn('room_id', $roomIds)
            ->active()
            ->overlapping($from, $until)
            ->where(fn (Builder $query) => $query
                ->where('source_type', '!=', RoomBlockSource::Schedule)
                ->orWhereIn('schedule_version_id', ScheduleVersion::query()->published()->select('id')))
            ->orderBy('start_at')
            ->when($lock, fn (Builder $query) => $query->sharedLock())
            ->get()
            ->groupBy('room_id');
    }

    /**
     * @param  Collection<int, RoomBlock>  $blocks
     * @param  Collection<int, ReservationStatus>  $reservationStatuses
     */
    private function evaluate(
        Room $room,
        CarbonImmutable $start,
        CarbonImmutable $end,
        CarbonImmutable $open,
        CarbonImmutable $close,
        Collection $blocks,
        Collection $reservationStatuses,
        bool $hasOfficialSchedule,
        CarbonImmutable $checkedAt,
    ): AvailabilityResult {
        $result = fn (RoomStatus $status, ?CarbonImmutable $freeUntil, ?CarbonImmutable $busyUntil, ?string $reason, ?RoomBlock $current = null, ?RoomBlock $next = null) => new AvailabilityResult(
            $room, $status, $start, $end, $freeUntil, $busyUntil, $reason, $current, $next, $checkedAt,
        );

        if (! $room->is_active || ! $room->is_bookable) {
            return $result(RoomStatus::Closed, null, null, 'Ruang tidak dapat dipesan');
        }

        if ($start->lessThan($open) || $end->greaterThan($close)) {
            $reopen = $start->lessThan($open) ? $open : $open->addDay();
            $next = $start->lessThan($open) ? $blocks->first(fn (RoomBlock $block): bool => $block->start_at->greaterThanOrEqualTo($start)) : null;

            return $result(RoomStatus::Closed, null, $reopen, 'Di luar jam operasional ('.Wita::clock($open, false).'–'.Wita::clock($close).')', null, $next);
        }

        $overlapping = $blocks->filter(fn (RoomBlock $block): bool => $block->start_at->lessThan($end) && $block->end_at->greaterThan($start));

        if ($overlapping->isNotEmpty()) {
            $current = $this->dominantBlock($overlapping, $start, $reservationStatuses);
            $status = $this->statusFor($current, $reservationStatuses);
            $busyUntil = $this->chainEnd($blocks, $overlapping->min('start_at'));
            $reason = $current->start_at->greaterThan($start)
                ? $status->label().' mulai '.Wita::clock($current->start_at)
                : $status->label();

            return $result($status, null, $busyUntil, $reason, $current);
        }

        $next = $blocks->first(fn (RoomBlock $block): bool => $block->start_at->greaterThanOrEqualTo($end));
        $freeUntil = $next === null ? $close : CarbonImmutable::instance($next->start_at)->min($close);

        if (! $hasOfficialSchedule) {
            return $result(RoomStatus::Uncertain, $freeUntil, null, 'Jadwal resmi belum ditetapkan', null, $next);
        }

        return $result(RoomStatus::Available, $freeUntil, null, null, null, $next);
    }

    /**
     * Blok yang menentukan status: yang sedang berlangsung pada $start lebih dulu, lalu prioritas jenisnya.
     *
     * @param  Collection<int, RoomBlock>  $overlapping
     * @param  Collection<int, ReservationStatus>  $reservationStatuses
     */
    private function dominantBlock(Collection $overlapping, CarbonImmutable $start, Collection $reservationStatuses): RoomBlock
    {
        $priority = fn (RoomBlock $block): int => match ($this->statusFor($block, $reservationStatuses)) {
            RoomStatus::Closed => 0,
            RoomStatus::InUse => 1,
            RoomStatus::Scheduled => 2,
            default => 3,
        };

        return $overlapping
            ->sortBy([
                fn (RoomBlock $a, RoomBlock $b): int => ($a->start_at->lessThanOrEqualTo($start) ? 0 : 1) <=> ($b->start_at->lessThanOrEqualTo($start) ? 0 : 1),
                fn (RoomBlock $a, RoomBlock $b): int => $a->start_at <=> $b->start_at,
                fn (RoomBlock $a, RoomBlock $b): int => $priority($a) <=> $priority($b),
            ])
            ->first();
    }

    /**
     * @param  Collection<int, ReservationStatus>  $reservationStatuses
     */
    private function statusFor(RoomBlock $block, Collection $reservationStatuses): RoomStatus
    {
        return match ($block->source_type) {
            RoomBlockSource::Closure => RoomStatus::Closed,
            RoomBlockSource::Schedule => RoomStatus::Scheduled,
            RoomBlockSource::Reservation => $reservationStatuses->get($block->source_id) === ReservationStatus::InUse
                ? RoomStatus::InUse
                : RoomStatus::Booked,
        };
    }

    /**
     * Akhir rangkaian blok yang bersambung mulai $from (blok yang bersentuhan dianggap bersambung).
     *
     * @param  Collection<int, RoomBlock>  $blocks
     */
    private function chainEnd(Collection $blocks, CarbonInterface $from): CarbonImmutable
    {
        $end = CarbonImmutable::instance($from);

        foreach ($blocks->sortBy('start_at') as $block) {
            if ($block->start_at->lessThanOrEqualTo($end) && $block->end_at->greaterThan($end)) {
                $end = CarbonImmutable::instance($block->end_at);
            }
        }

        return $end;
    }

    /**
     * @param  Collection<int, Collection<int, RoomBlock>>  $blocks
     * @return Collection<int, ReservationStatus>
     */
    private function reservationStatuses(Collection $blocks): Collection
    {
        $ids = $blocks->flatten()
            ->where('source_type', RoomBlockSource::Reservation)
            ->pluck('source_id')
            ->unique()
            ->all();

        return $ids === []
            ? collect()
            : Reservation::query()->whereKey($ids)->pluck('status', 'id');
    }

    /**
     * Ada periode aktif yang mencakup tanggal ini tetapi belum punya jadwal resmi → status "Belum pasti".
     */
    public function hasOfficialScheduleOn(CarbonImmutable $date): bool
    {
        $day = $date->toDateString();

        $period = Period::query()
            ->whereDate('start_date', '<=', $day)
            ->whereDate('end_date', '>=', $day)
            ->first();

        if ($period === null) {
            return true;
        }

        return $period->scheduleVersions()
            ->published()
            ->whereDate('effective_from', '<=', $day)
            ->where(fn (Builder $query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $day))
            ->exists();
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function operatingWindow(CarbonInterface $date): array
    {
        $day = $this->normalize($date)->startOfDay();
        [$openHour, $openMinute] = array_map('intval', explode(':', (string) config('sicma.operating_hours.start')));
        [$closeHour, $closeMinute] = array_map('intval', explode(':', (string) config('sicma.operating_hours.end')));

        return [$day->setTime($openHour, $openMinute), $day->setTime($closeHour, $closeMinute)];
    }

    /**
     * @param  Collection<int, AvailabilityResult>  $results
     * @return Collection<int, AvailabilityResult>
     */
    private function sort(Collection $results, string $sort): Collection
    {
        return match ($sort) {
            SearchCriteria::SORT_NAME => $results->sortBy(fn (AvailabilityResult $result): string => $result->room->name, SORT_NATURAL),
            SearchCriteria::SORT_CAPACITY => $results->sortByDesc(fn (AvailabilityResult $result): int => $result->room->capacity ?? -1),
            default => $results->sortBy([
                fn (AvailabilityResult $a, AvailabilityResult $b): int => ($b->isAvailable() <=> $a->isAvailable()),
                fn (AvailabilityResult $a, AvailabilityResult $b): int => ($b->freeUntil?->getTimestamp() ?? 0) <=> ($a->freeUntil?->getTimestamp() ?? 0),
                fn (AvailabilityResult $a, AvailabilityResult $b): int => strnatcmp($a->room->name, $b->room->name),
            ]),
        };
    }

    private function normalize(CarbonInterface $time): CarbonImmutable
    {
        return CarbonImmutable::instance($time)->setTimezone(config('app.timezone'))->startOfMinute();
    }
}
