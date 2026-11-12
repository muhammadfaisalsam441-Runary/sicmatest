<?php

namespace App\Services\Academic;

use App\Enums\SessionPhase;
use App\Enums\TeachingRole;
use App\Models\Academic\CourseOffering;
use App\Models\Academic\Period;
use App\Models\Lecturer\TeachingAssignment;
use App\Services\Account\AuditLogger;
use App\Services\Scheduling\ScheduleDraftService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TeachingPhasePlanner
{
    public function __construct(private AuditLogger $audit, private TeachingLoadService $loads, private ScheduleDraftService $drafts) {}

    /**
     * @return array{rows: list<array<string, mixed>>, unchanged: list<array<string, mixed>>, skipped: list<array{label: string, reason: string}>, already_set: int, fingerprint: string}
     */
    public function preview(Period $period, bool $lock = false): array
    {
        $offerings = CourseOffering::query()->whereBelongsTo($period)->with(['course', 'classGroup'])
            ->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->get();
        $assignments = TeachingAssignment::query()->whereIn('course_offering_id', $offerings->modelKeys())
            ->with('lecturer')->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->get()
            ->groupBy('course_offering_id');

        $rows = [];
        $unchanged = [];
        $skipped = [];
        foreach ($offerings as $offering) {
            $team = $assignments->get($offering->id, collect());
            $lead = $team->firstWhere('role', TeachingRole::Lead);
            $phases = $this->loads->meetingPhases($team);
            $label = ($offering->course?->name ?? 'Mata kuliah #'.$offering->course_id).' · '.$offering->classGroup?->name;

            if ($phases === []) {
                $skipped[] = ['label' => $label, 'reason' => 'Tetapkan satu pengampu dan maksimal dua mitra dengan dosen yang berbeda.'];

                continue;
            }
            if ($offering->course === null || $offering->course->sks <= 0 || $team->contains(fn (TeachingAssignment $assignment): bool => ! $assignment->lecturer?->is_active)) {
                $skipped[] = ['label' => $label, 'reason' => 'Periksa SKS mata kuliah dan status aktif tim pengajar.'];

                continue;
            }

            $changes = [];
            foreach ($team as $assignment) {
                $phase = $phases[$assignment->id];
                if ($assignment->phase !== $phase) {
                    $changes[] = [
                        'id' => $assignment->id,
                        'before' => $assignment->phase->value,
                        'after' => $phase->value,
                        'locked' => $assignment->is_locked,
                    ];
                }
            }

            if (array_any($changes, fn (array $change): bool => $change['locked'])) {
                $skipped[] = ['label' => $label, 'reason' => 'Fase yang perlu diubah berada pada penugasan terkunci.'];

                continue;
            }

            $row = [
                'label' => $label,
                'sks' => $offering->course->sks,
                'lead' => $lead->lecturer->name,
                'lead_meetings' => $this->loads->meetingCount($phases[$lead->id]),
                'partners' => $team->where('role', TeachingRole::Partner)->pluck('lecturer.name')->values()->all(),
                'assignment_ids' => $team->pluck('id')->all(),
                'changes' => $changes,
            ];

            if ($changes === []) {
                $unchanged[] = $row;
            } else {
                $rows[] = $row;
            }
        }

        $alreadySet = count($unchanged);
        $fingerprint = hash('sha256', json_encode([$period->id, $rows, $skipped, $alreadySet], JSON_THROW_ON_ERROR));

        return ['rows' => $rows, 'unchanged' => $unchanged, 'skipped' => $skipped, 'already_set' => $alreadySet, 'fingerprint' => $fingerprint];
    }

    public function apply(string $fingerprint): int
    {
        return DB::transaction(function () use ($fingerprint): int {
            $period = Period::query()->active()->lockForUpdate()->firstOrFail();
            $plan = $this->preview($period, lock: true);
            if ($plan['rows'] === [] || ! hash_equals($plan['fingerprint'], $fingerprint)) {
                throw ValidationException::withMessages(['fingerprint' => 'Usulan sudah berubah. Muat ulang pratinjau sebelum menyimpan.']);
            }

            $changes = collect($plan['rows'])->pluck('changes')->collapse();
            $assignments = TeachingAssignment::query()->whereKey($changes->pluck('id')->all())->get()->keyBy('id');
            foreach ($changes as $change) {
                $assignment = $assignments[$change['id']];
                $assignment->update(['phase' => SessionPhase::from($change['after'])]);
                $this->audit->log('teaching_assignment.phase_planned', $assignment, ['phase' => $change['before']], ['phase' => $change['after']]);
            }

            $offeringIds = $assignments->pluck('course_offering_id')->unique()->all();
            $this->drafts->syncOfferings($period, $offeringIds);

            return count($plan['rows']);
        }, attempts: 3);
    }
}
