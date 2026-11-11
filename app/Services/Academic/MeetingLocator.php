<?php

namespace App\Services\Academic;

use App\Enums\SessionMode;
use App\Enums\SessionPhase;
use App\Enums\TeachingRole;
use App\Models\Academic\SessionReschedule;
use App\Models\Lecturer\TeachingAssignment;
use App\Models\Scheduling\ScheduleSession;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Pertemuan kuliah resmi per tanggal: menemukan kemunculan sesi, status pindahnya, dan dosen yang bergiliran.
 */
class MeetingLocator
{
    public function __construct(private ClassScheduleService $schedules) {}

    /**
     * Kemunculan sesi luring berruang pada tanggal tersebut di jadwal resmi yang berlaku, atau null.
     */
    public function find(ScheduleSession $session, CarbonInterface $date): ?Meeting
    {
        $date = CarbonImmutable::instance($date)->startOfDay();
        $version = $this->schedules->officialVersionOn($date);

        if ($version === null
            || $session->schedule_version_id !== $version->id
            || ! $session->blocksRoom()
            || $session->day_of_week !== $date->dayOfWeekIso
            || ! in_array($date->dayOfWeekIso, config('sicma.scheduler.days'), true)
            || ! $this->schedules->hasClassesOn($version->period, $date)
            || ! $this->schedules->phaseCovers($session, $date, $version->period)) {
            return null;
        }

        $start = $date->addMinutes($session->start_minute);

        return new Meeting($session, $start, $date->addMinutes($session->end_minute), $this->activeRescheduleFor($session, $start));
    }

    /**
     * Semua pertemuan resmi pada tanggal tersebut (luring maupun online), terurut jam mulai.
     *
     * @return Collection<int, Meeting>
     */
    public function meetingsOn(CarbonInterface $date): Collection
    {
        $date = CarbonImmutable::instance($date)->startOfDay();
        $version = $this->schedules->officialVersionOn($date);

        if ($version === null
            || ! in_array($date->dayOfWeekIso, config('sicma.scheduler.days'), true)
            || ! $this->schedules->hasClassesOn($version->period, $date)) {
            return collect();
        }

        $reschedules = SessionReschedule::query()->active()
            ->whereBetween('original_start_at', [$date, $date->endOfDay()])
            ->get();

        return $version->sessions()
            ->where('day_of_week', $date->dayOfWeekIso)
            ->where('mode', '!=', SessionMode::Unscheduled)
            ->whereNotNull('start_minute')
            ->whereNotNull('end_minute')
            ->with(['participants', 'lecturers.leadershipTerms', 'courseOffering.teachingAssignments', 'room', 'version.period'])
            ->orderBy('start_minute')
            ->get()
            ->filter(fn (ScheduleSession $session): bool => $this->schedules->phaseCovers($session, $date, $version->period))
            ->map(function (ScheduleSession $session) use ($date, $reschedules): Meeting {
                $start = $date->addMinutes($session->start_minute);

                return new Meeting(
                    $session,
                    $start,
                    $date->addMinutes($session->end_minute),
                    $reschedules->first(fn (SessionReschedule $reschedule): bool => $reschedule->matches($session, $start)),
                );
            })
            ->values();
    }

    public function activeRescheduleFor(ScheduleSession $session, CarbonInterface $start): ?SessionReschedule
    {
        return SessionReschedule::query()->active()
            ->where('original_start_at', CarbonImmutable::instance($start)->startOfMinute())
            ->get()
            ->first(fn (SessionReschedule $reschedule): bool => $reschedule->matches($session, $start));
    }

    /**
     * Dosen yang bergiliran mengajar pada tanggal itu menurut fase penugasan, atau null bila tidak bisa ditentukan.
     *
     * @return Collection<int, int>|null
     */
    public function rotationLecturerIds(ScheduleSession $session, CarbonInterface $date): ?Collection
    {
        $period = $session->version?->period;
        $offering = $session->courseOffering;

        if ($offering === null || $period?->uts_start === null || $period->uts_end === null) {
            return null;
        }

        $day = CarbonImmutable::instance($date)->toDateString();
        $sessionLecturerIds = $session->lecturers->modelKeys();

        $ids = $offering->teachingAssignments
            ->filter(fn (TeachingAssignment $assignment): bool => match ($assignment->phase) {
                SessionPhase::Full => true,
                SessionPhase::BeforeUts => $day < $period->uts_start->toDateString(),
                SessionPhase::AfterUts => $day > $period->uts_end->toDateString(),
            })
            ->pluck('lecturer_id')
            ->filter(fn (int $id): bool => in_array($id, $sessionLecturerIds, true))
            ->unique()
            ->values();

        return $ids->isEmpty() ? null : $ids;
    }

    /**
     * Dosen yang dicentang otomatis di formulir Pindahkan: yang bergiliran, selain itu pengampu, selain itu semua.
     *
     * @return Collection<int, int>
     */
    public function defaultLecturerIds(ScheduleSession $session, CarbonInterface $date): Collection
    {
        if ($rotation = $this->rotationLecturerIds($session, $date)) {
            return $rotation;
        }

        $leads = $session->lecturers->filter(fn ($lecturer): bool => $lecturer->pivot->role === TeachingRole::Lead->value);

        return collect(($leads->isNotEmpty() ? $leads : $session->lecturers)->modelKeys());
    }

    /**
     * Dosen yang dianggap mengajar pertemuan itu saat memeriksa bentrok: yang bergiliran, selain itu semua dosen sesi.
     *
     * @return Collection<int, int>
     */
    public function dutyLecturerIds(ScheduleSession $session, CarbonInterface $date): Collection
    {
        return $this->rotationLecturerIds($session, $date) ?? collect($session->lecturers->modelKeys());
    }
}
