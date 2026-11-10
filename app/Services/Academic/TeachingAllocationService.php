<?php

namespace App\Services\Academic;

use App\Enums\LecturerProfileStatus;
use App\Enums\ScheduleSource;
use App\Enums\SessionPhase;
use App\Models\Academic\CourseOffering;
use App\Models\Academic\Period;
use App\Models\Lecturer\Lecturer;
use App\Models\Lecturer\LecturerLoadTarget;
use App\Models\Lecturer\TeachingAssignment;
use App\Models\Scheduling\ScheduleVersion;
use App\Services\Account\AuditLogger;
use App\Services\Scheduling\ScheduleDraftService;
use App\Services\Scheduling\SchedulerService;
use App\Services\Scheduling\ScheduleValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TeachingAllocationService
{
    public function __construct(
        private LecturerService $lecturerService,
        private TeachingLoadService $loads,
        private AuditLogger $audit,
        private SchedulerService $scheduler,
        private ScheduleDraftService $drafts,
        private ScheduleValidator $validator,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function preview(Period $period, bool $lock = false): array
    {
        $offerings = CourseOffering::query()->whereBelongsTo($period)->with(['course', 'classGroup'])
            ->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->get()->keyBy('id');
        $assignments = TeachingAssignment::query()->whereIn('course_offering_id', $offerings->modelKeys())
            ->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->get();
        $lecturers = Lecturer::query()->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->get()->keyBy('id');
        $targets = LecturerLoadTarget::query()->whereBelongsTo($period)->orderBy('id')
            ->when($lock, fn ($query) => $query->lockForUpdate())->get()->keyBy('lecturer_id');
        $eligible = $this->lecturerService->instructorIdsByOffering($offerings->values(), $lecturers->values(), $lock);
        $current = array_fill_keys($lecturers->modelKeys(), 0);
        $policy = [];
        foreach ($lecturers as $lecturer) {
            $target = $targets->get($lecturer->id);
            $policy[$lecturer->id] = [
                'target' => $target?->target_sks === null ? null : (int) round((float) $target->target_sks * 100),
                'max' => $target?->max_sks === null ? null : (int) round((float) $target->max_sks * 100),
                'receive' => $lecturer->is_active && $lecturer->profile_status === LecturerProfileStatus::Verified
                    && $target?->target_sks !== null && $target?->max_sks !== null
                    && (float) $target->target_sks >= 0
                    && (float) $target->max_sks >= (float) $target->target_sks,
            ];
        }

        $units = [];
        $owners = [];
        $warnings = [];
        foreach ($assignments->groupBy(fn (TeachingAssignment $assignment): string => $assignment->course_offering_id.':'.$assignment->lecturer_id) as $team) {
            $first = $team->first();
            $offering = $offerings[$first->course_offering_id];
            $sks = $this->loads->semesterSks($offering);
            if ($sks === null || $sks <= 0 || ! isset($policy[$first->lecturer_id])) {
                $warnings[] = 'Periksa mata kuliah atau profil dosen pada penawaran #'.$offering->id.'.';

                continue;
            }
            $current[$first->lecturer_id] += $sks * 100;
            $owners[$offering->id][$first->lecturer_id] = true;
            $units[] = [
                'offering_id' => $offering->id, 'from' => $first->lecturer_id, 'to' => $first->lecturer_id,
                'assignment_ids' => $team->modelKeys(), 'sks' => $sks * 100,
                'locked' => $team->contains('is_locked', true),
                'role' => $team->pluck('role')->map(fn ($role) => $role->label())->unique()->implode(' + '),
            ];
        }

        $before = $current;
        for ($pass = 0; $pass < count($units); $pass++) {
            $best = null;
            foreach ($units as $index => $unit) {
                $from = $unit['to'];
                if ($unit['locked']
                    || (($policy[$from]['target'] === null || $current[$from] <= $policy[$from]['target'])
                        && ($policy[$from]['max'] === null || $current[$from] <= $policy[$from]['max']))) {
                    continue;
                }
                foreach ($eligible[$unit['offering_id']] as $to) {
                    if (! ($policy[$to]['receive'] ?? false) || isset($owners[$unit['offering_id']][$to])
                        || $policy[$to]['max'] < $current[$to] + $unit['sks']) {
                        continue;
                    }
                    $fromTarget = $policy[$from]['target'];
                    $fromMax = $policy[$from]['max'];
                    $fromAfter = $current[$from] - $unit['sks'];
                    $toAfter = $current[$to] + $unit['sks'];
                    $overloadGain = $fromMax === null ? 0 : max(0, $current[$from] - $fromMax) - max(0, $fromAfter - $fromMax);
                    $targetGain = $fromTarget === null ? 0 : ($current[$from] - $fromTarget) ** 2 - ($fromAfter - $fromTarget) ** 2;
                    $targetGain += ($current[$to] - $policy[$to]['target']) ** 2 - ($toAfter - $policy[$to]['target']) ** 2;
                    if ($overloadGain < 0 || ($overloadGain === 0 && $targetGain <= 0)) {
                        continue;
                    }
                    if ($best === null || $overloadGain > $best['overload_gain']
                        || ($overloadGain === $best['overload_gain'] && $targetGain > $best['target_gain'])) {
                        $best = ['unit' => $index, 'from' => $from, 'to' => $to, 'overload_gain' => $overloadGain, 'target_gain' => $targetGain];
                    }
                }
            }
            if ($best === null) {
                break;
            }
            $unit = $units[$best['unit']];
            $current[$best['from']] -= $unit['sks'];
            $current[$best['to']] += $unit['sks'];
            unset($owners[$unit['offering_id']][$best['from']]);
            $owners[$unit['offering_id']][$best['to']] = true;
            $units[$best['unit']]['to'] = $best['to'];
        }

        $moves = [];
        foreach ($units as $unit) {
            if ($unit['from'] === $unit['to']) {
                continue;
            }
            $offering = $offerings[$unit['offering_id']];
            $moves[] = [...$unit,
                'label' => $offering->course->name.' · '.$offering->classGroup?->name,
                'from_name' => $lecturers[$unit['from']]->name, 'to_name' => $lecturers[$unit['to']]->name,
                'sks' => $unit['sks'] / 100,
            ];
        }
        $summary = [];
        foreach ($lecturers as $lecturer) {
            $id = $lecturer->id;
            $row = $policy[$id];
            if ($before[$id] === 0 && $current[$id] === 0) {
                continue;
            }
            $summary[] = ['name' => $lecturer->name, 'before' => $before[$id] / 100, 'after' => $current[$id] / 100,
                'target' => $row['target'] === null ? null : $row['target'] / 100,
                'max' => $row['max'] === null ? null : $row['max'] / 100,
            ];
            if ($row['max'] !== null && $current[$id] > $row['max']) {
                $warnings[] = $lecturer->name.' masih melebihi maksimum. Periksa penugasan terkunci serta dosen lain yang layak dan memiliki sisa kapasitas.';
            }
        }
        $missingPolicy = collect($eligible)->flatten()->unique()
            ->filter(fn (int $id): bool => isset($policy[$id]) && ! $policy[$id]['receive'])->count();
        if ($missingPolicy > 0) {
            $warnings[] = "{$missingPolicy} dosen belum memenuhi syarat sebagai penerima. Periksa status aktif, verifikasi, target, dan maksimum SKS.";
        }

        $base = $this->scheduler->baseVersion($period);
        if ($base !== null && $lock) {
            $base = ScheduleVersion::query()->whereKey($base->id)->lockForUpdate()->firstOrFail();
        }
        $sessions = $base?->sessions()->with('lecturers')->orderBy('id')
            ->when($lock, fn ($query) => $query->lockForUpdate())->get() ?? collect();
        $affected = $sessions->whereIn('course_offering_id', array_column($moves, 'offering_id'))->count();
        $fingerprint = hash('sha256', json_encode([
            $period->only(['id', 'start_date', 'end_date']),
            $offerings->map(fn ($offering) => [$offering->id, $offering->course_id, $offering->class_group_id, $offering->course?->sks]),
            $assignments->map->only(['id', 'course_offering_id', 'lecturer_id', 'role', 'phase', 'is_locked']),
            $lecturers->map->only(['id', 'name', 'is_active', 'profile_status']), $policy, $eligible,
            $base?->only(['id', 'status', 'lock_version']),
            $sessions->map(fn ($session) => [$session->getAttributes(), $session->lecturers->map(fn ($lecturer) => [$lecturer->id, $lecturer->pivot->role])]),
            $moves,
        ], JSON_THROW_ON_ERROR));

        return ['fingerprint' => $fingerprint, 'moves' => $moves, 'loads' => $summary, 'warnings' => $warnings,
            'base_id' => $base?->id, 'base_version_no' => $base?->version_no, 'affected_sessions' => $affected];
    }

    /**
     * @return array{count: int, draft_id: int|null}
     */
    public function apply(string $fingerprint): array
    {
        return DB::transaction(function () use ($fingerprint): array {
            $period = Period::query()->active()->lockForUpdate()->firstOrFail();
            $plan = $this->preview($period, lock: true);
            if ($plan['moves'] === [] || ! hash_equals($plan['fingerprint'], $fingerprint)) {
                throw ValidationException::withMessages(['fingerprint' => 'Data pembagian berubah. Hitung ulang dan tinjau usulan terbaru.']);
            }
            $assignments = TeachingAssignment::query()->whereKey(collect($plan['moves'])->pluck('assignment_ids')->flatten()->all())->get()->keyBy('id');
            foreach ($plan['moves'] as $move) {
                foreach ($move['assignment_ids'] as $id) {
                    $assignment = $assignments[$id];
                    $assignment->update(['lecturer_id' => $move['to']]);
                    $this->audit->log('teaching_assignment.reallocated', $assignment, ['lecturer_id' => $move['from']], ['lecturer_id' => $move['to']]);
                }
            }

            $draft = null;
            if ($plan['affected_sessions'] > 0) {
                $base = ScheduleVersion::query()->findOrFail($plan['base_id']);
                $draft = $this->drafts->create($period, ScheduleSource::Manual, $base);
                $offerings = CourseOffering::query()->whereKey(array_column($plan['moves'], 'offering_id'))->with('teachingAssignments')->get()->keyBy('id');
                foreach ($draft->sessions()->whereIn('course_offering_id', $offerings->modelKeys())->get() as $session) {
                    $team = $offerings[$session->course_offering_id]->teachingAssignments
                        ->filter(fn (TeachingAssignment $assignment): bool => $session->phase === SessionPhase::Full
                            || $assignment->phase === SessionPhase::Full || $session->phase === $assignment->phase);
                    $session->lecturers()->sync($team->mapWithKeys(fn (TeachingAssignment $assignment): array => [
                        $assignment->lecturer_id => ['role' => $assignment->role->value],
                    ])->all());
                }
                $this->validator->validate($draft);
            }
            $this->audit->log('teaching.balance_applied', $period, null, ['count' => count($plan['moves']), 'draft_id' => $draft?->id]);

            return ['count' => count($plan['moves']), 'draft_id' => $draft?->id];
        }, attempts: 3);
    }
}
