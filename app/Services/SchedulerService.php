<?php

namespace App\Services;

use App\Enums\AvailabilityType;
use App\Enums\IssueSeverity;
use App\Enums\LecturerProfileStatus;
use App\Enums\OptimizationJobStatus;
use App\Enums\ScheduleSource;
use App\Enums\ScheduleVersionStatus;
use App\Enums\SessionMode;
use App\Enums\SolverStatus;
use App\Exceptions\DomainException;
use App\Jobs\RunScheduleOptimization;
use App\Models\LecturerAvailability;
use App\Models\OptimizationJob;
use App\Models\Period;
use App\Models\Room;
use App\Models\ScheduleSession;
use App\Models\ScheduleVersion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Susun jadwal otomatis: snapshot data → worker Python (OR-Tools CP-SAT) → hasil SELALU menjadi draf baru (B21).
 *
 * Jadwal resmi tetap berlaku sampai admin menekan Tetapkan. Status solver disimpan terpisah dari status proses;
 * FEASIBLE tidak pernah disebut optimal.
 */
class SchedulerService
{
    /**
     * @var array<string, string>
     */
    public const SCOPES = [
        'semester' => 'Seluruh semester',
        'kelas' => 'Kelas tertentu',
        'belum-terjadwal' => 'Belum terjadwal',
        'terdampak' => 'Bagian terdampak',
    ];

    public function __construct(
        private DraftService $drafts,
        private ScheduleValidator $validator,
        private AuditLogger $audit,
        private NotificationService $notifications,
    ) {}

    /**
     * Versi yang menjadi dasar penyusunan: draf terbaru bila ada, selain itu jadwal resmi.
     */
    public function baseVersion(Period $period): ?ScheduleVersion
    {
        return $period->scheduleVersions()
            ->whereIn('status', [ScheduleVersionStatus::Draft, ScheduleVersionStatus::Ready])
            ->latest('version_no')
            ->first()
            ?? $period->scheduleVersions()->official()->latest('version_no')->first();
    }

    /**
     * Periksa kesiapan data & pengaturan sebelum menyusun.
     *
     * @param  array{type: string, class_ids?: list<int>}  $scope
     * @return array{errors: list<string>, warnings: list<string>, in_scope: int, fixed: int}
     */
    public function readiness(Period $period, array $scope): array
    {
        $errors = [];
        $warnings = [];
        $base = $this->baseVersion($period);

        if ($base === null) {
            $errors[] = 'Belum ada jadwal resmi atau draf sebagai dasar. Impor jadwal perkuliahan lebih dulu.';
        }

        if ($period->timeSlots()->doesntExist()) {
            $errors[] = 'Slot jam kuliah belum diatur (Data Akademik › Aturan penjadwalan).';
        }

        if (Room::query()->where('is_active', true)->doesntExist()) {
            $errors[] = 'Belum ada ruang aktif.';
        }

        if (! array_key_exists($scope['type'], self::SCOPES)) {
            $errors[] = 'Cakupan penyusunan tidak dikenal.';
        }

        if ($scope['type'] === 'kelas' && empty($scope['class_ids'])) {
            $errors[] = 'Pilih minimal satu kelas untuk cakupan "Kelas tertentu".';
        }

        $inScope = collect();
        $fixed = collect();

        if ($base !== null && $errors === []) {
            [$inScope, $fixed] = $this->partition($base, $scope);

            if ($inScope->isEmpty()) {
                $errors[] = match ($scope['type']) {
                    'belum-terjadwal' => 'Tidak ada sesi yang belum terjadwal.',
                    'terdampak' => 'Tidak ada sesi bermasalah pada '.($base->status === ScheduleVersionStatus::Official ? 'jadwal resmi' : 'draf v'.$base->version_no).'.',
                    default => 'Tidak ada sesi dalam cakupan ini.',
                };
            }

            $withoutLecturer = $inScope->filter(fn (ScheduleSession $session): bool => $session->lecturers->isEmpty())->count();
            if ($withoutLecturer > 0) {
                $warnings[] = "{$withoutLecturer} sesi belum punya pengajar; bentrok dosen tidak dapat diperiksa untuk sesi tersebut.";
            }

            $unknownStudents = $inScope->filter(fn (ScheduleSession $session): bool => $session->participants->pluck('student_count')->contains(null))->count();
            if ($unknownStudents > 0) {
                $warnings[] = "{$unknownStudents} sesi punya kelas dengan jumlah mahasiswa belum diisi; kapasitas ruang tidak diperiksa untuk sesi tersebut.";
            }

            $unverified = $inScope->flatMap->lecturers->where('profile_status', LecturerProfileStatus::Unverified)->unique('id')->count();
            if ($unverified > 0) {
                $warnings[] = "{$unverified} dosen masih Perlu verifikasi; ketersediaannya belum diketahui.";
            }
        }

        return ['errors' => $errors, 'warnings' => $warnings, 'in_scope' => $inScope->count(), 'fixed' => $fixed->count()];
    }

    /**
     * Mulai penyusunan. Mode dari config('sicma.scheduler.run_mode'): detached (default), queue, sync, atau manual (tes).
     *
     * @param  array{type: string, class_ids?: list<int>}  $scope
     */
    public function start(Period $period, array $scope, int $timeLimit, User $actor): OptimizationJob
    {
        if (! in_array($timeLimit, config('sicma.scheduler.time_limits'), true)) {
            throw new DomainException('DATA_INCOMPLETE', 'Pilih waktu pencarian Cepat, Seimbang, atau Mendalam.');
        }

        $readiness = $this->readiness($period, $scope);

        if ($readiness['errors'] !== []) {
            throw new DomainException('DATA_INCOMPLETE', $readiness['errors'][0], ['errors' => $readiness['errors']]);
        }

        $base = $this->baseVersion($period);
        $snapshot = $this->snapshot($base, $scope, $timeLimit);
        $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE);

        // Klik berulang tidak membuat pekerjaan ganda: masukan yang sama dan masih berjalan dipakai ulang.
        $running = OptimizationJob::query()
            ->where('input_hash', hash('sha256', $json))
            ->whereIn('status', [OptimizationJobStatus::Queued, OptimizationJobStatus::Running])
            ->first();

        if ($running !== null) {
            return $running;
        }

        $job = OptimizationJob::create([
            'schedule_version_id' => $base->id,
            'scope' => [...$scope, 'base_version_id' => $base->id, 'base_version_no' => $base->version_no, 'in_scope' => $readiness['in_scope']],
            'time_limit_seconds' => $timeLimit,
            'status' => OptimizationJobStatus::Queued,
            'input_hash' => hash('sha256', $json),
            'created_by' => $actor->id,
        ]);

        File::ensureDirectoryExists($this->directory($job));
        File::put($this->inputPath($job), $json);

        $this->audit->log('optimization.started', $job, null, ['scope' => $job->scope, 'time_limit_seconds' => $timeLimit]);

        match (config('sicma.scheduler.run_mode')) {
            'queue' => RunScheduleOptimization::dispatch($job),
            'sync' => $this->runNow($job),
            'manual' => null,
            default => $this->launchDetached($job),
        };

        return $job->fresh();
    }

    /**
     * Jalankan worker dan tunggu hasilnya (dipakai job antrean dan mode sync).
     */
    public function runNow(OptimizationJob $job): OptimizationJob
    {
        $job->forceFill(['status' => OptimizationJobStatus::Running, 'started_at' => now()])->save();

        $result = Process::timeout($job->time_limit_seconds + (int) config('sicma.scheduler.grace_seconds'))
            ->run([config('sicma.scheduler.python_bin'), config('sicma.scheduler.worker_path'), $this->inputPath($job), $this->outputPath($job)]);

        if (! File::exists($this->outputPath($job))) {
            return $this->fail($job, 'Worker penyusun jadwal tidak menghasilkan keluaran. '.trim($result->errorOutput() ?: $result->output()));
        }

        return $this->finalize($job, json_decode(File::get($this->outputPath($job)), true) ?? []);
    }

    /**
     * Periksa proses yang berjalan terpisah: simpan hasilnya bila sudah ada, gagalkan bila melewati batas waktu.
     */
    public function poll(OptimizationJob $job): OptimizationJob
    {
        if (! in_array($job->status, [OptimizationJobStatus::Queued, OptimizationJobStatus::Running], true)) {
            return $job;
        }

        if (File::exists($this->outputPath($job))) {
            return $this->finalize($job, json_decode(File::get($this->outputPath($job)), true) ?? []);
        }

        $deadline = CarbonImmutable::instance($job->started_at ?? $job->created_at)
            ->addSeconds($job->time_limit_seconds + (int) config('sicma.scheduler.grace_seconds'));

        if (now()->greaterThan($deadline)) {
            return $this->fail($job, 'Worker tidak merespons sampai batas waktu. Pastikan Python dan OR-Tools terpasang (lihat README).');
        }

        return $job;
    }

    /**
     * Simpan hasil worker. Hanya OPTIMAL/FEASIBLE yang membuat draf baru; jadwal resmi tidak pernah diubah (B21).
     *
     * @param  array<string, mixed>  $output
     */
    public function finalize(OptimizationJob $job, array $output): OptimizationJob
    {
        $finalized = DB::transaction(function () use ($job, $output): ?OptimizationJob {
            $job = OptimizationJob::query()->whereKey($job->id)->lockForUpdate()->firstOrFail();

            if (! in_array($job->status, [OptimizationJobStatus::Queued, OptimizationJobStatus::Running], true)) {
                return null;
            }

            $solverStatus = SolverStatus::tryFrom((string) ($output['status'] ?? '')) ?? SolverStatus::ModelInvalid;
            $result = [
                'message' => $output['message'] ?? null,
                'objective' => $output['objective'] ?? null,
                'wall_time' => $output['wall_time'] ?? null,
                'unplaced' => $output['unplaced'] ?? [],
            ];

            if (in_array($solverStatus, [SolverStatus::Optimal, SolverStatus::Feasible], true)) {
                $result = [...$result, ...$this->applyToNewDraft($job, $output['assignments'] ?? [])];
            }

            $job->forceFill([
                'status' => OptimizationJobStatus::Finished,
                'solver_status' => $solverStatus,
                'result' => $result,
                'finished_at' => now(),
            ])->save();

            $this->audit->log('optimization.finished', $job, null, ['solver_status' => $solverStatus->value, 'draft_version_id' => $result['draft_version_id'] ?? null]);

            return $job;
        });

        if ($finalized === null) {
            return $job->fresh();
        }

        if (isset($finalized->result['draft_version_id'])) {
            $this->validator->validate(ScheduleVersion::query()->findOrFail($finalized->result['draft_version_id']));
        }

        $this->notifications->notify(
            $finalized->creator,
            'optimization.finished',
            isset($finalized->result['draft_version_id']) ? 'Penyusunan jadwal selesai' : 'Penyusunan jadwal belum berhasil',
            $finalized->solver_status->label().'. '.($finalized->result['message'] ?? ''),
            ['optimization_job_id' => $finalized->id, 'schedule_version_id' => $finalized->result['draft_version_id'] ?? null],
        );

        return $finalized->fresh();
    }

    public function cancel(OptimizationJob $job, User $actor): OptimizationJob
    {
        return DB::transaction(function () use ($job): OptimizationJob {
            $job = OptimizationJob::query()->whereKey($job->id)->lockForUpdate()->firstOrFail();

            if (in_array($job->status, [OptimizationJobStatus::Queued, OptimizationJobStatus::Running], true)) {
                $job->forceFill(['status' => OptimizationJobStatus::Cancelled, 'finished_at' => now(), 'result' => ['message' => 'Dibatalkan admin. Tidak ada draf yang dibuat.']])->save();
                $this->audit->log('optimization.cancelled', $job);
            }

            return $job;
        });
    }

    /**
     * Data masukan worker.
     *
     * @param  array{type: string, class_ids?: list<int>}  $scope
     * @return array<string, mixed>
     */
    public function snapshot(ScheduleVersion $base, array $scope, int $timeLimit): array
    {
        $period = $base->period()->with('timeSlots')->firstOrFail();
        [$inScope, $fixed] = $this->partition($base, $scope);

        $slots = $period->timeSlots->sortBy('start_minute')->values();
        $slotLength = $slots->isNotEmpty() ? $slots->first()->end_minute - $slots->first()->start_minute : 50;

        $from = CarbonImmutable::parse($base->effective_from->toDateString());
        $to = CarbonImmutable::parse($period->end_date->toDateString());
        $lecturers = $inScope->flatMap->lecturers->merge($fixed->flatMap->lecturers)->unique('id');

        return [
            'time_limit' => $timeLimit,
            'days' => config('sicma.scheduler.days'),
            'slots' => $slots->map(fn ($slot): array => ['label' => $slot->label, 'start' => $slot->start_minute, 'end' => $slot->end_minute])->all(),
            'rooms' => Room::query()->where('is_active', true)->get()->map(fn (Room $room): array => ['id' => $room->id, 'capacity' => $room->capacity])->all(),
            'leaders' => $lecturers->filter(fn ($lecturer): bool => $lecturer->leadershipTerms->contains(fn ($term): bool => $term->valid_from->toDateString() <= $to->toDateString()
                && ($term->valid_to === null || $term->valid_to->toDateString() >= $from->toDateString())))->pluck('id')->values()->all(),
            'unavailable' => LecturerAvailability::query()
                ->whereBelongsTo($period)
                ->where('type', AvailabilityType::Unavailable)
                ->get()
                ->map(fn ($availability): array => ['lecturer' => $availability->lecturer_id, 'day' => $availability->day_of_week, 'start' => $availability->start_minute, 'end' => $availability->end_minute])
                ->all(),
            'fixed' => $fixed->filter(fn (ScheduleSession $session): bool => $this->isTimed($session))->map(fn (ScheduleSession $session): array => [
                'day' => $session->day_of_week,
                'start' => $session->start_minute,
                'end' => $session->end_minute,
                'room' => $session->mode === SessionMode::Offline ? $session->room_id : null,
                'lecturers' => $session->lecturers->pluck('id')->all(),
                'classes' => $session->participants->pluck('id')->all(),
                'phase' => $session->phase->value,
            ])->values()->all(),
            'sessions' => $inScope->map(fn (ScheduleSession $session): array => [
                'id' => $session->id,
                'duration' => $this->isTimed($session) ? $session->end_minute - $session->start_minute : max(1, $session->courseOffering?->course?->sks ?? 2) * $slotLength,
                'mode' => $session->mode === SessionMode::Online ? 'online' : 'offline',
                'phase' => $session->phase->value,
                'lecturers' => $session->lecturers->pluck('id')->all(),
                'classes' => $session->participants->pluck('id')->all(),
                'students' => $session->participants->pluck('student_count')->contains(null) || $session->participants->isEmpty() ? null : $session->participants->sum('student_count'),
                'locked' => $session->is_locked && $this->isTimed($session) ? ['day' => $session->day_of_week, 'start' => $session->start_minute, 'room' => $session->room_id] : null,
                'preferred' => $this->isTimed($session) ? ['day' => $session->day_of_week, 'start' => $session->start_minute, 'room' => $session->room_id] : null,
            ])->values()->all(),
        ];
    }

    /**
     * Pisahkan sesi dalam cakupan dan sesi tetap (diperhitungkan tetapi tidak dipindahkan).
     *
     * @param  array{type: string, class_ids?: list<int>}  $scope
     * @return array{0: Collection<int, ScheduleSession>, 1: Collection<int, ScheduleSession>}
     */
    private function partition(ScheduleVersion $base, array $scope): array
    {
        $sessions = $base->sessions()->with(['participants', 'lecturers.leadershipTerms', 'courseOffering.course'])->get();

        $problemIds = $scope['type'] === 'terdampak'
            ? $base->issues()->whereNull('resolved_at')->where('severity', IssueSeverity::Error)->pluck('session_ids')->flatten()->unique()
            : collect();

        $inScope = $sessions->filter(fn (ScheduleSession $session): bool => match ($scope['type']) {
            'kelas' => $session->participants->pluck('id')->intersect($scope['class_ids'] ?? [])->isNotEmpty(),
            'belum-terjadwal' => ! $this->isTimed($session),
            'terdampak' => $problemIds->contains($session->id),
            default => true,
        });

        return [$inScope->values(), $sessions->diffKeys($inScope)->values()];
    }

    /**
     * @param  list<array{id: int, day: int, start_minute: int, end_minute: int, room_id: int|null}>  $assignments
     * @return array{draft_version_id: int, draft_version_no: int, placed: int, changed: int}
     */
    private function applyToNewDraft(OptimizationJob $job, array $assignments): array
    {
        $base = ScheduleVersion::query()->findOrFail($job->scope['base_version_id']);
        $draft = $this->drafts->create($base->period, ScheduleSource::Generated);
        $map = $this->drafts->copySessions($base, $draft);
        $draft->forceFill(['based_on_version_id' => $base->id])->save();

        $changed = 0;

        foreach ($assignments as $assignment) {
            $session = ScheduleSession::query()->find($map[$assignment['id']] ?? null);

            if ($session === null) {
                continue;
            }

            $mode = $session->mode === SessionMode::Online ? SessionMode::Online : SessionMode::Offline;
            $changed += (int) ($session->day_of_week !== $assignment['day'] || $session->start_minute !== $assignment['start_minute'] || $session->room_id !== $assignment['room_id']);

            $session->forceFill([
                'mode' => $mode,
                'day_of_week' => $assignment['day'],
                'start_minute' => $assignment['start_minute'],
                'end_minute' => $assignment['end_minute'],
                'room_id' => $mode === SessionMode::Offline ? $assignment['room_id'] : null,
            ])->save();
        }

        return ['draft_version_id' => $draft->id, 'draft_version_no' => $draft->version_no, 'placed' => count($assignments), 'changed' => $changed];
    }

    private function fail(OptimizationJob $job, string $message): OptimizationJob
    {
        $job->forceFill(['status' => OptimizationJobStatus::Failed, 'finished_at' => now(), 'result' => ['message' => $message]])->save();
        $this->audit->log('optimization.failed', $job, null, ['message' => $message]);
        $this->notifications->notify($job->creator, 'optimization.failed', 'Penyusunan jadwal gagal dijalankan', $message, ['optimization_job_id' => $job->id]);

        return $job;
    }

    /**
     * Jalankan worker sebagai proses terpisah agar tidak bergantung pada queue worker atau batas waktu request.
     */
    private function launchDetached(OptimizationJob $job): void
    {
        $job->forceFill(['status' => OptimizationJobStatus::Running, 'started_at' => now()])->save();

        $arguments = [config('sicma.scheduler.python_bin'), config('sicma.scheduler.worker_path'), $this->inputPath($job), $this->outputPath($job)];

        if (PHP_OS_FAMILY === 'Windows') {
            $command = 'start "" /B '.implode(' ', array_map(fn (string $argument): string => '"'.$argument.'"', $arguments)).' > NUL 2>&1';
            pclose(popen($command, 'r'));

            return;
        }

        exec('nohup '.implode(' ', array_map('escapeshellarg', $arguments)).' > /dev/null 2>&1 &');
    }

    private function isTimed(ScheduleSession $session): bool
    {
        return $session->mode !== SessionMode::Unscheduled
            && $session->day_of_week !== null
            && $session->start_minute !== null
            && $session->end_minute !== null;
    }

    private function directory(OptimizationJob $job): string
    {
        return storage_path('app/private/scheduler/'.$job->id);
    }

    public function inputPath(OptimizationJob $job): string
    {
        return $this->directory($job).DIRECTORY_SEPARATOR.'input.json';
    }

    public function outputPath(OptimizationJob $job): string
    {
        return $this->directory($job).DIRECTORY_SEPARATOR.'output.json';
    }
}
