<?php

namespace App\Services;

use App\Enums\TeachingRole;
use App\Models\Lecturer;
use App\Models\Period;
use App\Models\TeachingAssignment;
use Illuminate\Support\Collection;

/**
 * Rekap beban SKS dosen vs target. Dua pengajar tidak otomatis masing-masing menerima SKS penuh:
 * SKS diakui memakai credited_sks; bila kosong dan dosen mengajar sendiri, memakai SKS mata kuliah.
 */
class TeachingLoadService
{
    /**
     * @return Collection<int, array{lecturer: Lecturer, target: float|null, max: float|null, assigned: float, pending: int, remaining: float|null, status: string, tone: string}>
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

            foreach ($rows as $assignment) {
                $credit = $this->credit($assignment);

                if ($credit === null) {
                    $pending++;
                } else {
                    $assigned += $credit;
                }
            }

            $targetSks = $target?->target_sks !== null ? (float) $target->target_sks : null;
            $maxSks = $target?->max_sks !== null ? (float) $target->max_sks : null;

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
                'remaining' => $maxSks !== null ? $maxSks - $assigned : null,
                'status' => $status,
                'tone' => $tone,
            ];
        })->sortBy(fn (array $row): string => $row['lecturer']->name)->values();
    }

    /**
     * SKS yang diakui untuk satu penugasan, atau null bila perlu ditetapkan jurusan.
     */
    public function credit(TeachingAssignment $assignment): ?float
    {
        if ($assignment->credited_sks !== null) {
            return (float) $assignment->credited_sks;
        }

        $team = $assignment->courseOffering->teachingAssignments;

        return $team->count() === 1 && $assignment->role === TeachingRole::Lead
            ? (float) $assignment->courseOffering->course->sks
            : null;
    }
}
