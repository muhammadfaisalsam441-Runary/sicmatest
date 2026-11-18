<?php

namespace App\Services\Academic;

use App\Exceptions\DomainException;
use App\Models\Academic\SessionReschedule;
use App\Models\Academic\TimeSlot;
use App\Models\Scheduling\ScheduleSession;
use App\Models\User\User;
use App\Services\Room\AvailabilityService;
use App\Services\Room\SearchCriteria;
use App\Support\RoomCardPresenter;
use App\Support\Wita;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Pencari rekomendasi slot waktu dan ruangan kosong untuk pemindahan pertemuan kuliah.
 */
class MeetingRecommendationFinder
{
    public function __construct(
        private MeetingLocator $meetings,
        private AvailabilityService $availability,
        private ClassScheduleService $schedules,
        private MeetingMoveValidator $validator,
    ) {}

    /**
     * Rekomendasi slot waktu dan ruangan kosong untuk memindahkan pertemuan kuliah pada tanggal target.
     *
     * @param  list<int>  $lecturerIds
     * @return Collection<int, array{slot: string, start: CarbonImmutable, end: CarbonImmutable, time_range: string, rooms: list<array<string, mixed>>, rooms_count: int}>
     */
    public function recommendations(
        User $keti,
        ScheduleSession $session,
        CarbonInterface $originalDate,
        CarbonInterface $targetDate,
        array $lecturerIds,
        ?SessionReschedule $replacing = null,
        ?int $manualStartMinute = null,
    ): Collection {
        $session->loadMissing(['participants', 'lecturers', 'version.period.timeSlots']);
        $meeting = $this->meetings->find($session, $originalDate);

        if ($meeting === null) {
            return collect();
        }

        $duration = (int) $meeting->start->diffInMinutes($meeting->end);
        if ($duration <= 0) {
            $duration = 100;
        }

        $targetDay = CarbonImmutable::instance($targetDate)->setTimezone(config('app.timezone'))->startOfDay();
        $period = $session->version->period;

        if (! in_array($targetDay->dayOfWeekIso, config('sicma.scheduler.days', [1, 2, 3, 4, 5]), true)
            || ! $this->schedules->hasClassesOn($period->loadMissing('calendarExceptions'), $targetDay)) {
            return collect();
        }

        $timeSlots = $period->timeSlots->sortBy('start_minute')->values();
        $defaultStarts = [450, 500, 555, 600, 605, 660, 710, 790, 840, 890, 940];

        $candidateMinutes = $timeSlots->isNotEmpty()
            ? $timeSlots->pluck('start_minute')->unique()->values()
            : collect($defaultStarts);

        if ($manualStartMinute !== null && ! $candidateMinutes->contains($manualStartMinute)) {
            $candidateMinutes->push($manualStartMinute);
        }

        $candidateMinutes = $candidateMinutes->sort()->values();
        $recommendations = collect();

        foreach ($candidateMinutes as $startMinute) {
            $candidateStart = $targetDay->addMinutes((int) $startMinute);
            $candidateEnd = $candidateStart->addMinutes($duration);

            try {
                $this->validator->validateMove($keti, $session, $originalDate, Wita::normalize($candidateStart), $lecturerIds, $replacing);
            } catch (DomainException) {
                continue;
            }

            $criteria = new SearchCriteria(
                start: $candidateStart,
                durationMinutes: $duration,
                status: 'available',
                sort: SearchCriteria::SORT_NAME,
            );

            $availableRooms = $this->availability->search($criteria)
                ->map(RoomCardPresenter::card(...))
                ->sortBy(fn (array $room): int => $room['id'] === $session->room_id ? 0 : 1)
                ->values()
                ->all();

            $slotLabel = $this->formatSlotLabel($timeSlots, $candidateStart, $candidateEnd);

            $recommendations->push([
                'slot' => $slotLabel,
                'start' => $candidateStart,
                'end' => $candidateEnd,
                'time_range' => Wita::range($candidateStart, $candidateEnd),
                'rooms' => $availableRooms,
                'rooms_count' => count($availableRooms),
            ]);
        }

        return $recommendations;
    }

    /**
     * Format label slot jam perkuliahan (mis. "Slot I–II (07:30–09:10 WITA)").
     *
     * @param  Collection<int, TimeSlot|array{label: string, start_minute: int, end_minute: int}>  $timeSlots
     */
    public function formatSlotLabel(Collection $timeSlots, CarbonImmutable $start, CarbonImmutable $end): string
    {
        $startMinute = $start->hour * 60 + $start->minute;
        $endMinute = $startMinute + (int) $start->diffInMinutes($end);

        $slots = $timeSlots->isNotEmpty() ? $timeSlots : collect([
            ['label' => 'I', 'start_minute' => 450, 'end_minute' => 500],
            ['label' => 'II', 'start_minute' => 500, 'end_minute' => 550],
            ['label' => 'III', 'start_minute' => 555, 'end_minute' => 605],
            ['label' => 'IV', 'start_minute' => 605, 'end_minute' => 655],
            ['label' => 'V', 'start_minute' => 660, 'end_minute' => 710],
            ['label' => 'VI', 'start_minute' => 710, 'end_minute' => 760],
            ['label' => 'VII', 'start_minute' => 790, 'end_minute' => 840],
            ['label' => 'VIII', 'start_minute' => 840, 'end_minute' => 890],
            ['label' => 'IX', 'start_minute' => 890, 'end_minute' => 940],
            ['label' => 'X', 'start_minute' => 940, 'end_minute' => 995],
            ['label' => 'XI', 'start_minute' => 995, 'end_minute' => 1050],
        ]);

        $overlapping = $slots->filter(function ($slot) use ($startMinute, $endMinute): bool {
            $slotStart = is_array($slot) ? $slot['start_minute'] : $slot->start_minute;
            $slotEnd = is_array($slot) ? $slot['end_minute'] : $slot->end_minute;

            return $slotStart < $endMinute && $slotEnd > $startMinute;
        })->values();

        if ($overlapping->isNotEmpty()) {
            $first = $overlapping->first();
            $last = $overlapping->last();
            $firstLabel = is_array($first) ? $first['label'] : $first->label;
            $lastLabel = is_array($last) ? $last['label'] : $last->label;

            $name = $firstLabel === $lastLabel ? "Slot {$firstLabel}" : "Slot {$firstLabel}–{$lastLabel}";

            return "{$name} (".Wita::range($start, $end).')';
        }

        return Wita::range($start, $end);
    }
}
