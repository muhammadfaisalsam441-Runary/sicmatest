<?php

namespace App\Services\Academic;

use App\Enums\SessionPhase;
use App\Enums\TeachingRole;
use App\Models\Academic\CourseOffering;
use App\Models\Academic\Period;
use App\Models\Lecturer\Lecturer;
use App\Models\Lecturer\TeachingAssignment;
use Illuminate\Support\Collection;

class TeachingLoadService
{
    public const int MEETINGS_PER_PHASE = 7;

    public const int MEETINGS_PER_SEMESTER = 2 * self::MEETINGS_PER_PHASE;

    /**
     * @return Collection<int, array{lecturer: Lecturer, target: float|null, max: float|null, assigned: float, pending: int, meeting_sks: int, pending_phases: int, remaining: float|null, status: string, tone: string}>
     */
    public function summary(Period $period): Collection
    {
        $assignments = TeachingAssignment::query()
            ->whereHas('courseOffering', fn ($query) => $query->whereBelongsTo($period))
            ->with(['courseOffering.course', 'courseOffering.teachingAssignments', 'lecturer.loadTargets' => fn ($query) => $query->whereBelongsTo($period)])
            ->get();

        return $assignments->groupBy('lecturer_id')->map(function (Collection $rows): array {
            $lecturer = $rows->first()->lecturer;
            $target = $lecturer->loadTargets->first();
            $assigned = 0.0;
            $pending = 0;
            $meetingSks = 0;
            $pendingPhases = 0;

            foreach ($rows->unique('course_offering_id') as $assignment) {
                $credit = $this->semesterSks($assignment->courseOffering);
                $phaseLoad = $this->meetingSks($assignment);

                if ($credit === null) {
                    $pending++;
                } else {
                    $assigned += $credit;
                }

                if ($phaseLoad === null) {
                    $pendingPhases++;
                } else {
                    $meetingSks += $phaseLoad;
                }
            }

            $targetSks = $target?->target_sks !== null ? (int) $target->target_sks : null;
            $maxSks = $target?->max_sks !== null ? (int) $target->max_sks : null;

            [$status, $tone] = match (true) {
                $maxSks !== null && $assigned > $maxSks => ['Melebihi maksimum', 'danger'],
                $targetSks === null => ['Beban: menunggu kebijakan jurusan', 'neutral'],
                abs($assigned - $targetSks) < 0.01 => ['Sesuai target', 'success'],
                $assigned > $targetSks => ['Di atas target; dalam maksimum', 'warning'],
                default => ['Di bawah target', 'primary'],
            };

            return [
                'lecturer' => $lecturer,
                'target' => $targetSks,
                'max' => $maxSks,
                'assigned' => $assigned,
                'pending' => $pending,
                'meeting_sks' => $meetingSks,
                'pending_phases' => $pendingPhases,
                'remaining' => $maxSks !== null ? $maxSks - $assigned : null,
                'status' => $status,
                'tone' => $tone,
            ];
        })->sortBy(fn (array $row): string => $row['lecturer']->name)->values();
    }

    /**
     * Fase untuk satu pengampu dengan nol, satu, atau dua mitra.
     *
     * @param  Collection<int, TeachingAssignment>  $team
     * @return array<int, SessionPhase>
     */
    public function meetingPhases(Collection $team): array
    {
        if ($team->count() > 3
            || $team->where('role', TeachingRole::Lead)->count() !== 1
            || $team->pluck('lecturer_id')->unique()->count() !== $team->count()
        ) {
            return [];
        }

        return $team->mapWithKeys(fn (TeachingAssignment $assignment): array => [
            $assignment->id => match (true) {
                $team->count() === 1 => SessionPhase::Full,
                $assignment->role === TeachingRole::Lead => SessionPhase::BeforeUts,
                default => SessionPhase::AfterUts,
            },
        ])->all();
    }

    public function meetingCount(SessionPhase $phase): int
    {
        return $phase === SessionPhase::Full ? self::MEETINGS_PER_SEMESTER : self::MEETINGS_PER_PHASE;
    }

    private function meetingSks(TeachingAssignment $assignment): ?int
    {
        $offering = $assignment->courseOffering;
        $team = $offering->teachingAssignments;
        $sks = $this->semesterSks($offering);
        $phases = $this->meetingPhases($team);

        if ($sks === null || $sks <= 0 || $phases === []
            || $team->contains(fn (TeachingAssignment $member): bool => $member->phase !== $phases[$member->id])
        ) {
            return null;
        }

        return $sks * $this->meetingCount($assignment->phase);
    }

    /**
     * Bobot SKS satu penawaran untuk tiap dosen, sesuai rekap semester.
     */
    public function semesterSks(?CourseOffering $offering): ?int
    {
        return $offering?->course?->sks;
    }
}
