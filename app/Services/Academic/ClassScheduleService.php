<?php

namespace App\Services\Academic;

use App\Enums\SessionMode;
use App\Enums\SessionPhase;
use App\Models\Academic\ClassGroup;
use App\Models\Academic\Period;
use App\Models\Scheduling\ScheduleSession;
use App\Models\Scheduling\ScheduleVersion;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Jadwal resmi per kelas pada satu tanggal (My Class). Hanya membaca versi official (B09).
 */
class ClassScheduleService
{
    /**
     * Versi jadwal resmi yang berlaku pada tanggal tersebut.
     */
    public function officialVersionOn(CarbonInterface $date): ?ScheduleVersion
    {
        $day = $date->toDateString();

        return ScheduleVersion::query()
            ->published()
            ->whereHas('period', fn (Builder $query) => $query
                ->whereDate('start_date', '<=', $day)
                ->whereDate('end_date', '>=', $day))
            ->whereDate('effective_from', '<=', $day)
            ->where(fn (Builder $query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $day))
            ->with('period.calendarExceptions')
            ->latest('effective_from')
            ->first();
    }

    /**
     * Sesi kelas pada tanggal tersebut, terurut jam mulai. Sesi online tetap tampil (tanpa ruang).
     *
     * @return Collection<int, ScheduleSession>
     */
    public function sessionsFor(ClassGroup $classGroup, CarbonInterface $date): Collection
    {
        $date = CarbonImmutable::instance($date)->startOfDay();
        if (! in_array($date->dayOfWeekIso, config('sicma.scheduler.days'), true)) {
            return collect();
        }
        $version = $this->officialVersionOn($date);

        if ($version === null || ! $this->hasClassesOn($version->period, $date)) {
            return collect();
        }

        return $version->sessions()
            ->whereHas('participants', fn (Builder $query) => $query->whereKey($classGroup->id))
            ->where('day_of_week', $date->dayOfWeekIso)
            ->where('mode', '!=', SessionMode::Unscheduled)
            ->with(['room.building', 'lecturers', 'participants'])
            ->orderBy('start_minute')
            ->get()
            ->filter(fn (ScheduleSession $session): bool => $this->phaseCovers($session, $date, $version->period))
            ->values();
    }

    private function hasClassesOn(Period $period, CarbonImmutable $date): bool
    {
        return $period->start_date !== null && $period->end_date !== null
            && $date->betweenIncluded($period->start_date, $period->end_date)
            && ! $period->calendarExceptions->contains(fn ($exception): bool => $exception->date->isSameDay($date));
    }

    private function phaseCovers(ScheduleSession $session, CarbonImmutable $date, Period $period): bool
    {
        return match ($session->phase) {
            SessionPhase::Full => true,
            SessionPhase::BeforeUts => $period->uts_start === null || $date->lessThan($period->uts_start),
            SessionPhase::AfterUts => $period->uts_end === null || $date->greaterThan($period->uts_end),
        };
    }
}
