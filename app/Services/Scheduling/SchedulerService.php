<?php

namespace App\Services\Scheduling;

use App\Enums\AvailabilityType;
use App\Enums\IssueSeverity;
use App\Enums\LecturerProfileStatus;
use App\Enums\OptimizationJobStatus;
use App\Enums\RoomBlockSource;
use App\Enums\ScheduleSource;
use App\Enums\ScheduleVersionStatus;
use App\Enums\SessionMode;
use App\Enums\SessionPhase;
use App\Enums\SolverStatus;
use App\Exceptions\DomainException;
use App\Jobs\RunScheduleOptimization;
use App\Models\Academic\Period;
use App\Models\Lecturer\LecturerAvailability;
use App\Models\Room\Room;
use App\Models\Room\RoomBlock;
use App\Models\Scheduling\OptimizationJob;
use App\Models\Scheduling\ScheduleSession;
use App\Models\Scheduling\ScheduleVersion;
use App\Models\User\User;
use App\Services\Account\AuditLogger;
use App\Services\Account\NotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Susun jadwal otomatis dengan PHP; hasil selalu menjadi draf baru.
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
        private ScheduleDraftService $drafts,
        private ScheduleValidator $validator,
        private AuditLogger $audit,
        private NotificationService $notifications,
        private PhpScheduleSolver $solver,
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
    public function readiness(Period $period, array $scope, ?ScheduleVersion $base = null): array
    {
        $errors = [];
        $warnings = [];
        $base ??= $this->baseVersion($period);

        if ($period->start_date === null || $period->end_date === null) {
            $errors[] = 'Lengkapi tanggal mulai dan akhir semester sebelum menyusun jadwal otomatis.';
        } elseif ($period->end_date->lessThan($period->start_date) || $period->end_date->lessThan(CarbonImmutable::today())) {
            $errors[] = 'Rentang semester tidak valid atau sudah berakhir. Periksa kalender semester.';
        }

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
            if ($inScope->merge($fixed)->contains(fn (ScheduleSession $session): bool => $session->phase !== SessionPhase::Full)
                && ($period->uts_start === null || $period->uts_end === null || $period->uts_end->lessThan($period->uts_start))) {
                $errors[] = 'Lengkapi rentang UTS yang valid untuk membedakan sesi sebelum dan setelah UTS.';
            }

            if ($inScope->isEmpty()) {
                $errors[] = match ($scope['type']) {
                    'belum-terjadwal' => 'Tidak ada sesi yang belum terjadwal.',
                    'terdampak' => 'Tidak ada sesi bermasalah pada '.($base->status === ScheduleVersionStatus::Official ? 'jadwal resmi' : 'draf v'.$base->version_no).'.',
                    default => 'Tidak ada sesi dalam cakupan ini.',
                };
            }

            $withoutLecturer = $inScope->filter(fn (ScheduleSession $session): bool => $session->lecturers->isEmpty())->count();
            if ($inScope->contains(fn (ScheduleSession $session): bool => $session->is_locked && ! $this->isTimed($session))) {
                $errors[] = 'Lengkapi waktu sesi terkunci atau buka kuncinya sebelum menyusun jadwal.';
            }
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
     * Antrean PHP menjadi mode utama; sync dan manual dipakai untuk pengujian.
     *
     * @param  array{type: string, class_ids?: list<int>}  $scope
     */
    public function start(Period $period, array $scope, int $timeLimit, User $actor, ?ScheduleVersion $base = null, ?OptimizationJob $retryOf = null): OptimizationJob
    {
        if (! in_array($timeLimit, config('sicma.scheduler.time_limits'), true)) {
            throw new DomainException('DATA_INCOMPLETE', 'Pilih waktu pencarian Cepat, Seimbang, atau Mendalam.');
        }

        return DB::transaction(function () use ($period, $scope, $timeLimit, $actor, $base, $retryOf): OptimizationJob {
            Period::query()->whereKey($period->id)->lockForUpdate()->firstOrFail();
            $readiness = $this->readiness($period, $scope, $base);

            if ($readiness['errors'] !== []) {
                throw new DomainException('DATA_INCOMPLETE', $readiness['errors'][0], ['errors' => $readiness['errors']]);
            }

            $base ??= $this->baseVersion($period);
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
                'scope' => [...$scope, 'base_version_id' => $base->id, 'base_version_no' => $base->version_no, 'in_scope' => $readiness['in_scope'], 'retry_of_job_id' => $retryOf?->id],
                'time_limit_seconds' => $timeLimit,
                'status' => OptimizationJobStatus::Queued,
                'input_hash' => hash('sha256', $json),
                'created_by' => $actor->id,
            ]);

            File::ensureDirectoryExists($this->directory($job));
            File::put($this->inputPath($job), $json);

            $this->audit->log('optimization.started', $job, null, ['scope' => $job->scope, 'time_limit_seconds' => $timeLimit]);

            match (config('sicma.scheduler.run_mode')) {
                'sync' => $this->runNow($job),
                'manual' => null,
                default => RunScheduleOptimization::dispatch($job)->afterCommit(),
            };

            return $job->fresh();
        });
    }

    /**
     * Jalankan worker dan tunggu hasilnya (dipakai job antrean dan mode sync).
     */
    public function runNow(OptimizationJob $job): OptimizationJob
    {
        $claimed = DB::transaction(function () use ($job): bool {
            $current = OptimizationJob::query()->whereKey($job->id)->lockForUpdate()->firstOrFail();
            if ($current->status !== OptimizationJobStatus::Queued) {
                return false;
            }
            $current->forceFill(['status' => OptimizationJobStatus::Running, 'started_at' => now()])->save();

            return true;
        });
        if (! $claimed) {
            return $job->fresh();
        }

        try {
            $json = File::get($this->inputPath($job));
            if (! hash_equals($job->input_hash, hash('sha256', $json))) {
                return $this->fail($job, 'Snapshot masukan berubah atau rusak. Mulai penyusunan baru.');
            }
            $input = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            $lastProgress = -INF;
            $output = $this->solver->solve($input,
                fn (): bool => OptimizationJob::query()->findOrFail($job->id)->status !== OptimizationJobStatus::Running,
                function (array $progress) use ($job, &$lastProgress): void {
                    $clock = hrtime(true) / 1_000_000_000;
                    if ($clock - $lastProgress < 3) {
                        return;
                    }
                    $lastProgress = $clock;
                    OptimizationJob::query()->whereKey($job->id)->where('status', OptimizationJobStatus::Running)
                        ->update(['result' => json_encode(['progress' => $progress], JSON_THROW_ON_ERROR)]);
                });

            OptimizationJob::query()->whereKey($job->id)->where('status', OptimizationJobStatus::Running)
                ->update(['result' => json_encode(['progress' => ['stage' => 'Memeriksa hasil', 'total' => count($input['sessions']), 'placed' => count($output['assignments'] ?? [])]], JSON_THROW_ON_ERROR)]);

            return $this->finalize($job, $output);
        } catch (Throwable $exception) {
            report($exception);

            return $this->fail($job, 'Generator PHP gagal memproses data. Periksa log aplikasi lalu coba lagi.');
        }
    }

    /**
     * Baca status antrean dan gagalkan proses yang melewati batas waktu.
     */
    public function poll(OptimizationJob $job): OptimizationJob
    {
        return DB::transaction(function () use ($job): OptimizationJob {
            $job = OptimizationJob::query()->whereKey($job->id)->lockForUpdate()->firstOrFail();
            if (! in_array($job->status, [OptimizationJobStatus::Queued, OptimizationJobStatus::Running], true)) {
                return $job;
            }

            $deadline = CarbonImmutable::instance($job->started_at ?? $job->created_at)
                ->addSeconds($job->status === OptimizationJobStatus::Queued ? (int) config('sicma.scheduler.queue_wait_seconds', 600)
                    : $job->time_limit_seconds + (int) config('sicma.scheduler.grace_seconds'));

            if (now()->greaterThan($deadline)) {
                return $this->fail($job, $job->status === OptimizationJobStatus::Queued
                    ? 'Antrean belum diproses. Pastikan service queue Laravel berjalan, lalu coba lagi.'
                    : 'Generator PHP melewati batas proses. Periksa service queue dan log aplikasi.');
            }

            return $job;
        });
    }

    /**
     * Simpan hasil lengkap atau parsial sebagai draf, lalu validasi sebelum memberi status layak.
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
                'unplaced_details' => [],
                'attempts' => $output['attempts'] ?? null,
            ];

            $input = json_decode(File::get($this->inputPath($job)), true, flags: JSON_THROW_ON_ERROR);
            $assignments = $output['assignments'] ?? [];
            $expected = collect($input['sessions'])->keyBy('id');
            $base = ScheduleVersion::query()->whereKey($job->scope['base_version_id'])->lockForUpdate()->firstOrFail();
            $valid = ($input['base_lock_version'] ?? $base->lock_version) === $base->lock_version
                && $base->period->start_date !== null && $base->period->end_date !== null;
            if ($valid) {
                $currentInput = $this->snapshot($base, $job->scope, $job->time_limit_seconds);
                $valid = $currentInput === $input && is_array($assignments) && $this->solver->accepts($currentInput, $assignments);
            }
            $seen = $valid ? array_fill_keys(array_column($assignments, 'id'), true) : [];
            if (! $valid) {
                $solverStatus = SolverStatus::ModelInvalid;
                $result['message'] = 'Data dasar berubah atau keluaran tidak sesuai cakupan/kunci. Jalankan penyusunan baru.';
            } elseif ($assignments !== [] && in_array($solverStatus, [SolverStatus::Optimal, SolverStatus::Feasible, SolverStatus::Unknown], true)) {
                $result['unplaced'] = array_values(array_diff($expected->keys()->all(), array_keys($seen)));
                $details = collect($output['unplaced_details'] ?? [])->keyBy('id');
                $labels = $base->sessions()->with('participants')->whereIn('id', $result['unplaced'])->get()->keyBy('id');
                $result['unplaced_details'] = array_map(fn (int $id): array => ['id' => $id,
                    'label' => $labels[$id]->title.' — '.$labels[$id]->participants->pluck('name')->implode(' + '),
                    'reason' => $details->get($id)['reason'] ?? 'Belum ditemukan penempatan yang memenuhi aturan.'], $result['unplaced']);
                $result = [...$result, ...$this->applyToNewDraft($job, $assignments, $result['unplaced'])];
                $draft = ScheduleVersion::query()->findOrFail($result['draft_version_id']);
                $this->validator->validate($draft);
                $result['validation_errors'] = $draft->issues()->whereNull('resolved_at')->where('severity', IssueSeverity::Error)->count();
                $result['validation_warnings'] = $draft->issues()->whereNull('resolved_at')->where('severity', IssueSeverity::Warning)->count();
                $result['publishable'] = $result['unplaced'] === [] && $result['validation_errors'] === 0;
                $solverStatus = $result['publishable'] ? SolverStatus::Feasible : SolverStatus::Unknown;
                if (! $result['publishable']) {
                    $result['message'] = count($result['unplaced']).' sesi belum ditempatkan; '.$result['validation_errors'].' masalah wajib perlu diperbaiki sebelum penetapan.';
                }
            } elseif (in_array($solverStatus, [SolverStatus::Optimal, SolverStatus::Feasible], true)) {
                $solverStatus = SolverStatus::Unknown;
                $result['message'] = 'Tidak ada penempatan yang dihasilkan.';
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

    public function canRetry(OptimizationJob $job): bool
    {
        return $job->status === OptimizationJobStatus::Finished && $job->solver_status === SolverStatus::Unknown
            && $job->time_limit_seconds === (int) config('sicma.scheduler.time_limits.seimbang')
            && (! empty($job->result['unplaced']) || empty($job->result['draft_version_id']));
    }

    public function retry(OptimizationJob $previous, User $actor): OptimizationJob
    {
        if (! $this->canRetry($previous)) {
            throw new DomainException('RETRY_NOT_ALLOWED', 'Pencarian 180 detik hanya tersedia untuk hasil 60 detik yang belum lengkap.');
        }

        return DB::transaction(function () use ($previous, $actor): OptimizationJob {
            $base = ScheduleVersion::query()->findOrFail($previous->scope['base_version_id']);
            $period = Period::query()->whereKey($base->period_id)->lockForUpdate()->firstOrFail();
            if (! $period->is_active || $base->status === ScheduleVersionStatus::Superseded) {
                throw new DomainException('DATA_CHANGED', 'Semester atau jadwal dasar berubah. Periksa kesiapan dan mulai penyusunan baru.');
            }
            if (! File::exists($this->inputPath($previous))) {
                throw new DomainException('DATA_CHANGED', 'Data dasar pencarian tidak tersedia. Periksa kesiapan dan mulai penyusunan baru.');
            }
            $json = File::get($this->inputPath($previous));
            if (! hash_equals($previous->input_hash, hash('sha256', $json))) {
                throw new DomainException('DATA_CHANGED', 'Data dasar pencarian berubah. Periksa kesiapan dan mulai penyusunan baru.');
            }
            $input = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            if ($this->snapshot($base, $previous->scope, $previous->time_limit_seconds) !== $input) {
                throw new DomainException('DATA_CHANGED', 'Data dasar berubah. Periksa kesiapan dan mulai penyusunan baru.');
            }

            return $this->start($period, $previous->scope, (int) config('sicma.scheduler.time_limits.mendalam'), $actor, $base, $previous);
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
        $period = $base->period()->with(['timeSlots', 'calendarExceptions'])->firstOrFail();
        [$inScope, $fixed] = $this->partition($base, $scope);

        $slots = $period->timeSlots->sortBy('start_minute')->values();
        $slotLength = $slots->isNotEmpty() ? $slots->first()->end_minute - $slots->first()->start_minute : 50;

        $from = CarbonImmutable::today()->max($period->start_date)->max($base->effective_from);
        $to = CarbonImmutable::parse($period->end_date->toDateString());
        $periodYear = $period->start_date?->year ?? CarbonImmutable::today()->year;
        $lecturers = $inScope->flatMap->lecturers->merge($fixed->flatMap->lecturers)->unique('id');

        return [
            'base_lock_version' => $base->lock_version,
            'time_limit' => $timeLimit,
            'capacity_blocks_publish' => (bool) config('sicma.scheduler.capacity_blocks_publish'),
            'uts_start' => $period->uts_start?->toDateString(),
            'uts_end' => $period->uts_end?->toDateString(),
            'room_blocks' => $this->roomBlocks($period, $from, $to),
            'days' => config('sicma.scheduler.days'),
            'friday_break' => config('sicma.scheduler.friday_break', ['start' => 720, 'end' => 790]),
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
            'availability' => LecturerAvailability::query()->whereBelongsTo($period)->get()
                ->map(fn ($availability): array => ['lecturer' => $availability->lecturer_id, 'day' => $availability->day_of_week,
                    'start' => $availability->start_minute, 'end' => $availability->end_minute, 'type' => $availability->type->value])->all(),
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
                'duration' => $this->isTimed($session) ? $session->end_minute - $session->start_minute
                    : ($session->source_raw['scheduling_duration_minutes'] ?? max(1, $session->courseOffering?->course?->sks ?? 2) * $slotLength),
                'mode' => $session->mode === SessionMode::Online ? 'online' : 'offline',
                'phase' => $session->phase->value,
                'lecturers' => $session->lecturers->pluck('id')->all(),
                'classes' => $session->participants->pluck('id')->all(),
                'students' => $session->participants->pluck('student_count')->contains(null) || $session->participants->isEmpty() ? null : $session->participants->sum('student_count'),
                'cohort_profile' => $this->cohortProfile($session, $periodYear),
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
        $sessions = $base->sessions()->with(['participants', 'lecturers.leadershipTerms', 'courseOffering.course'])->orderBy('id')->get();

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
     * @param  list<int>  $unplaced
     * @return array{draft_version_id: int, draft_version_no: int, placed: int, changed: int}
     */
    private function applyToNewDraft(OptimizationJob $job, array $assignments, array $unplaced): array
    {
        $base = ScheduleVersion::query()->findOrFail($job->scope['base_version_id']);
        $draft = $this->drafts->create($base->period, ScheduleSource::Generated);
        $map = $this->drafts->copySessions($base, $draft);
        $draft->forceFill(['based_on_version_id' => $base->id, 'effective_from' => $draft->effective_from->max($base->effective_from)->toDateString()])->save();

        $changed = 0;

        foreach ($unplaced as $id) {
            $session = ScheduleSession::query()->findOrFail($map[$id]);
            if (! $session->is_locked) {
                $source = $session->source_raw ?? [];
                if ($this->isTimed($session)) {
                    $source['scheduling_duration_minutes'] = $session->end_minute - $session->start_minute;
                }
                $session->forceFill(['mode' => $session->mode === SessionMode::Online ? SessionMode::Online : SessionMode::Unscheduled,
                    'day_of_week' => null, 'start_minute' => null, 'end_minute' => null, 'room_id' => null, 'source_raw' => $source])->save();
            }
        }

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

    public function fail(OptimizationJob $job, string $message): OptimizationJob
    {
        $changed = DB::transaction(function () use ($job, $message): bool {
            $current = OptimizationJob::query()->whereKey($job->id)->lockForUpdate()->firstOrFail();
            if (! in_array($current->status, [OptimizationJobStatus::Queued, OptimizationJobStatus::Running], true)) {
                return false;
            }
            $current->forceFill(['status' => OptimizationJobStatus::Failed, 'finished_at' => now(), 'result' => ['message' => $message]])->save();

            return true;
        });
        if (! $changed) {
            return $job->fresh();
        }
        $job = $job->fresh();
        $this->audit->log('optimization.failed', $job, null, ['message' => $message]);
        $this->notifications->notify($job->creator, 'optimization.failed', 'Penyusunan jadwal gagal dijalankan', $message, ['optimization_job_id' => $job->id]);

        return $job;
    }

    /**
     * Blok reservasi dan penutupan dipisah per tanggal; hari libur tidak menghasilkan sesi kuliah.
     *
     * @return list<array{room: int, date: string, day: int, start: int, end: int}>
     */
    private function roomBlocks(Period $period, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = [];
        $holidays = $period->calendarExceptions->map(fn ($exception): string => $exception->date->toDateString())->all();
        $blocks = RoomBlock::query()->active()->whereIn('source_type', [RoomBlockSource::Reservation, RoomBlockSource::Closure])
            ->overlapping($from->startOfDay(), $to->endOfDay())->get();
        foreach ($blocks as $block) {
            for ($date = CarbonImmutable::instance($block->start_at)->startOfDay()->max($from); $date->lessThanOrEqualTo($to) && $date->lessThan($block->end_at); $date = $date->addDay()) {
                if (in_array($date->toDateString(), $holidays, true)) {
                    continue;
                }
                $rows[] = ['room' => $block->room_id, 'date' => $date->toDateString(), 'day' => $date->dayOfWeekIso,
                    'start' => $block->start_at->lessThan($date) ? 0 : $block->start_at->hour * 60 + $block->start_at->minute,
                    'end' => $block->end_at->greaterThanOrEqualTo($date->addDay()) ? 1440 : $block->end_at->hour * 60 + $block->end_at->minute];
            }
        }

        return $rows;
    }

    private function cohortProfile(ScheduleSession $session, int $periodYear): ?string
    {
        if ($session->participants->isEmpty()) {
            return null;
        }
        $isUlangOrSenior = $session->participants->contains(fn ($class): bool => str_contains(strtoupper((string) $class->name), 'ULANG')
            || ($class->cohort_year !== null && $class->cohort_year <= $periodYear - 3));
        if ($isUlangOrSenior) {
            return 'ulang_senior';
        }
        $isMaba = $session->participants->contains(fn ($class): bool => $class->cohort_year !== null && $class->cohort_year >= $periodYear);

        return $isMaba ? 'maba' : 'reguler';
    }

    private function isTimed(ScheduleSession $session): bool
    {
        return $session->mode !== SessionMode::Unscheduled
            && $session->day_of_week !== null
            && $session->start_minute !== null
            && $session->end_minute !== null
            && $session->end_minute > $session->start_minute;
    }

    private function directory(OptimizationJob $job): string
    {
        return storage_path('app/private/scheduler/'.$job->id);
    }

    public function inputPath(OptimizationJob $job): string
    {
        return $this->directory($job).DIRECTORY_SEPARATOR.'input.json';
    }
}
