<?php

namespace App\Services\Account;

use App\Enums\LecturerProfileStatus;
use App\Enums\RoomStatus;
use App\Enums\ScheduleVersionStatus;
use App\Models\Academic\Period;
use App\Models\Lecturer\Lecturer;
use App\Models\Room\Room;
use App\Models\Scheduling\ScheduleVersion;
use App\Services\Room\AvailabilityResult;
use App\Services\Room\AvailabilityService;
use App\Services\Room\RoomAgendaDescriber;
use App\Support\Wita;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Data dashboard admin. Status ruang memakai AvailabilityService yang sama dengan Portal Publik & Keti.
 */
class AdminDashboardService
{
    /**
     * Ringkasan kode masalah wajib untuk kartu "Masalah wajib".
     *
     * @var array<string, string>
     */
    public const ISSUE_LABELS = [
        'ROOM_CONFLICT' => 'bentrok ruang',
        'LECTURER_CONFLICT' => 'bentrok dosen',
        'CLASS_CONFLICT' => 'bentrok kelas',
        'LEADERSHIP_THURSDAY' => 'pimpinan Kamis',
        'SESSION_WITHOUT_TIME' => 'sesi tanpa waktu',
        'CAPACITY_EXCEEDED' => 'kapasitas kurang',
        'LOAD_EXCEEDED' => 'beban melebihi batas',
        'RESERVATION_CONFLICT' => 'bentrok reservasi',
    ];

    public function __construct(
        private AvailabilityService $availability,
        private RoomAgendaDescriber $agenda,
    ) {}

    /**
     * Baris tabel "Status ruang saat ini".
     *
     * @return Collection<int, array{room: string, class: string|null, title: string, until: string, badge: string, variant: string}>
     */
    public function roomStatuses(CarbonInterface $at): Collection
    {
        $rooms = Room::query()->with('building')->where('is_active', true)->get();
        $results = $this->availability->forRooms($rooms, $at)
            ->sortBy(fn (AvailabilityResult $result): string => $result->room->name, SORT_NATURAL)
            ->values();
        $agendas = $this->agenda->describeMany(
            $results->map(fn (AvailabilityResult $result) => $result->currentBlock)->filter()->values()
        );

        return $results
            ->map(function (AvailabilityResult $result) use ($agendas): array {
                $agenda = $result->currentBlock !== null ? $agendas->get($result->currentBlock->id) : null;
                $until = $result->isAvailable() ? $result->freeUntil : $result->busyUntil;

                return [
                    'room' => $result->room->name,
                    'class' => $agenda['class'] ?? null,
                    'title' => $agenda['title'] ?? ($result->isAvailable() ? 'Tersedia untuk kelas' : ($result->reason ?? '—')),
                    'until' => $until !== null ? ($result->status === RoomStatus::Closed ? 'Buka ' : 'Hingga ').Wita::clock($until, false) : '—',
                    'badge' => $result->badge(),
                    'variant' => match (true) {
                        $result->isAvailable() => 'success',
                        $result->badge() === 'Digunakan' => 'danger',
                        default => 'neutral',
                    },
                ];
            });
    }

    /**
     * Kartu panel "Penyusunan jadwal <periode>".
     *
     * @return array{period: Period|null, official: ScheduleVersion|null, draft: ScheduleVersion|null, blocking_count: int, blocking_summary: string|null, unverified_lecturers: int}
     */
    public function scheduleProgress(): array
    {
        $period = Period::query()->active()->first();

        $official = $period?->scheduleVersions()->official()->latest('version_no')->first();
        $draft = $period?->scheduleVersions()
            ->whereIn('status', [ScheduleVersionStatus::Draft, ScheduleVersionStatus::Ready])
            ->latest('version_no')
            ->first();

        $issues = $draft?->issues()->blocking()->pluck('code') ?? collect();

        $summary = $issues->unique()
            ->map(fn (string $code): string => self::ISSUE_LABELS[$code] ?? strtolower(str_replace('_', ' ', $code)))
            ->implode(', ');

        return [
            'period' => $period,
            'official' => $official,
            'draft' => $draft,
            'blocking_count' => $issues->count(),
            'blocking_summary' => $summary === '' ? null : ucfirst($summary),
            'unverified_lecturers' => Lecturer::query()->where('profile_status', LecturerProfileStatus::Unverified)->count(),
        ];
    }
}
