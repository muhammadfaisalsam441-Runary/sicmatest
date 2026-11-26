<?php

use App\Enums\IssueSeverity;
use App\Enums\OptimizationJobStatus;
use App\Enums\ScheduleSource;
use App\Enums\ScheduleVersionStatus;
use App\Enums\SessionMode;
use App\Enums\SolverStatus;
use App\Exceptions\DomainException;
use App\Jobs\RunScheduleOptimization;
use App\Models\Academic\ClassGroup;
use App\Models\Academic\Period;
use App\Models\Lecturer\LeadershipTerm;
use App\Models\Lecturer\Lecturer;
use App\Models\Lecturer\LecturerAvailability;
use App\Models\Room\Room;
use App\Models\Room\RoomBlock;
use App\Models\Scheduling\OptimizationJob;
use App\Models\Scheduling\ScheduleSession;
use App\Models\Scheduling\ScheduleVersion;
use App\Models\User\User;
use App\Services\Scheduling\SchedulerService;
use App\Services\Scheduling\ScheduleValidator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['sicma.scheduler.run_mode' => 'manual']);
    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00'));
    $this->period = Period::factory()->create();
    foreach ([['I', 450, 500], ['II', 500, 550], ['III', 550, 600], ['IV', 600, 650], ['V', 650, 700], ['VI', 700, 750]] as [$label, $start, $end]) {
        $this->period->timeSlots()->create(['label' => $label, 'start_minute' => $start, 'end_minute' => $end]);
    }
    $this->room = Room::factory()->create(['capacity' => 45]);
    $this->tekomC = ClassGroup::factory()->create(['name' => 'Tekom C', 'student_count' => 40]);
    $this->ptikB = ClassGroup::factory()->create(['name' => 'PTIK B', 'student_count' => 30]);
    $this->official = ScheduleVersion::factory()->official()->for($this->period)->create(['version_no' => 2, 'effective_from' => '2026-09-01']);
    $this->leader = Lecturer::factory()->create();
    LeadershipTerm::factory()->for($this->leader)->create();

    $this->leaderSession = ScheduleSession::factory()->for($this->official, 'version')->create(['room_id' => $this->room->id, 'day_of_week' => 1, 'start_minute' => 450, 'end_minute' => 600]);
    $this->leaderSession->participants()->sync([$this->tekomC->id]);
    $this->leaderSession->lecturers()->sync([$this->leader->id => ['role' => 'lead']]);

    $this->otherSession = ScheduleSession::factory()->for($this->official, 'version')->create(['room_id' => $this->room->id, 'day_of_week' => 2, 'start_minute' => 450, 'end_minute' => 600]);
    $this->otherSession->participants()->sync([$this->ptikB->id]);

    $this->unscheduled = ScheduleSession::factory()->unscheduled()->for($this->official, 'version')->create();
    $this->unscheduled->participants()->sync([$this->ptikB->id]);

    $this->admin = User::factory()->admin()->create();
});

afterEach(function () {
    File::deleteDirectory(storage_path('app/private/scheduler'));
});

test('snapshot memisahkan sesi dalam cakupan dan sesi tetap, serta menandai pimpinan', function () {
    $snapshot = app(SchedulerService::class)->snapshot($this->official, ['type' => 'kelas', 'class_ids' => [$this->tekomC->id]], 60);

    expect(collect($snapshot['sessions'])->pluck('id')->all())->toBe([$this->leaderSession->id])
        ->and($snapshot['sessions'][0])->toMatchArray(['duration' => 150, 'students' => 40, 'mode' => 'offline'])
        ->and($snapshot['fixed'])->toHaveCount(1)
        ->and($snapshot['fixed'][0])->toMatchArray(['day' => 2, 'room' => $this->room->id])
        ->and($snapshot['leaders'])->toBe([$this->leader->id])
        ->and($snapshot['days'])->toBe(config('sicma.scheduler.days'));
});

test('cakupan belum terjadwal hanya berisi sesi tanpa waktu dengan durasi dari SKS', function () {
    $snapshot = app(SchedulerService::class)->snapshot($this->official, ['type' => 'belum-terjadwal'], 15);

    expect(collect($snapshot['sessions'])->pluck('id')->all())->toBe([$this->unscheduled->id])
        ->and($snapshot['sessions'][0]['preferred'])->toBeNull()
        ->and($snapshot['sessions'][0]['duration'])->toBeGreaterThan(0)
        ->and($snapshot['fixed'])->toHaveCount(2);
});

test('periksa kesiapan menolak cakupan kelas tanpa kelas dan cakupan terdampak tanpa masalah', function () {
    $scheduler = app(SchedulerService::class);

    expect($scheduler->readiness($this->period, ['type' => 'kelas', 'class_ids' => []])['errors'])->toContain('Pilih minimal satu kelas untuk cakupan "Kelas tertentu".')
        ->and($scheduler->readiness($this->period, ['type' => 'terdampak'])['errors'][0])->toContain('Tidak ada sesi bermasalah')
        ->and($scheduler->readiness($this->period, ['type' => 'semester'])['errors'])->toBe([]);

    expect(fn () => $scheduler->start($this->period, ['type' => 'terdampak'], 60, $this->admin))->toThrow(DomainException::class);
});

test('hasil lengkap selalu menjadi draf layak tanpa mengubah jadwal resmi', function () {
    $scheduler = app(SchedulerService::class);
    $job = $scheduler->start($this->period, ['type' => 'semester'], 15, $this->admin);

    expect(File::exists($scheduler->inputPath($job)))->toBeTrue();

    $job = $scheduler->finalize($job, [
        'status' => 'OPTIMAL',
        'message' => 'Susunan terbaik ditemukan.',
        'assignments' => [
            ['id' => $this->leaderSession->id, 'day' => 3, 'start_minute' => 450, 'end_minute' => 600, 'room_id' => $this->room->id],
            ['id' => $this->otherSession->id, 'day' => 2, 'start_minute' => 450, 'end_minute' => 600, 'room_id' => $this->room->id],
            ['id' => $this->unscheduled->id, 'day' => 5, 'start_minute' => 450, 'end_minute' => 550, 'room_id' => $this->room->id],
        ],
    ]);

    $draft = ScheduleVersion::query()->findOrFail($job->result['draft_version_id']);
    $placed = $draft->sessions()->whereHas('participants', fn ($query) => $query->whereKey($this->ptikB->id))->where('day_of_week', 5)->sole();

    expect($job->status)->toBe(OptimizationJobStatus::Finished)
        ->and($job->solver_status)->toBe(SolverStatus::Feasible)
        ->and($draft->status)->toBe(ScheduleVersionStatus::Draft)
        ->and($draft->source)->toBe(ScheduleSource::Generated)
        ->and($draft->based_on_version_id)->toBe($this->official->id)
        ->and($job->result['changed'])->toBe(2)
        ->and($placed->mode)->toBe(SessionMode::Offline)
        ->and($this->official->fresh()->status)->toBe(ScheduleVersionStatus::Official)
        ->and($this->leaderSession->fresh()->day_of_week)->toBe(1)
        ->and($this->unscheduled->fresh()->mode)->toBe(SessionMode::Unscheduled);
});

test('FEASIBLE disimpan terpisah dan tidak disebut optimal', function () {
    $scheduler = app(SchedulerService::class);
    $job = $scheduler->runNow($scheduler->start($this->period, ['type' => 'semester'], 15, $this->admin));

    expect($job->solver_status)->toBe(SolverStatus::Feasible)
        ->and($job->solver_status->label())->not->toContain('Optimal');

    $this->withoutVite()->actingAs($this->admin)->get(route('admin.scheduler.show', $job))
        ->assertSee('belum dinyatakan optimal')
        ->assertSee('Draf hasil penyusunan');
});

test('INFEASIBLE dan batas waktu tidak membuat draf', function (string $status, string $text) {
    $scheduler = app(SchedulerService::class);
    $job = $scheduler->finalize($scheduler->start($this->period, ['type' => 'semester'], 15, $this->admin), ['status' => $status, 'assignments' => [], 'message' => 'x']);

    expect($job->result)->not->toHaveKey('draft_version_id')
        ->and(ScheduleVersion::query()->where('source', ScheduleSource::Generated)->exists())->toBeFalse();

    $this->withoutVite()->actingAs($this->admin)->get(route('admin.scheduler.show', $job))->assertSee($text);
})->with([
    'infeasible' => ['INFEASIBLE', 'Belum ada susunan yang memenuhi aturan'],
    'batas waktu' => ['UNKNOWN', 'Batas waktu pencarian tercapai'],
]);

test('proses yang dibatalkan mengabaikan hasil yang datang terlambat', function () {
    $scheduler = app(SchedulerService::class);
    $job = $scheduler->start($this->period, ['type' => 'semester'], 15, $this->admin);

    $scheduler->cancel($job, $this->admin);
    $job = $scheduler->finalize($job, ['status' => 'OPTIMAL', 'assignments' => []]);

    expect($job->status)->toBe(OptimizationJobStatus::Cancelled)
        ->and(ScheduleVersion::query()->where('source', ScheduleSource::Generated)->exists())->toBeFalse();
});

test('klik berulang dengan masukan yang sama tidak membuat pekerjaan ganda', function () {
    $scheduler = app(SchedulerService::class);
    $first = $scheduler->start($this->period, ['type' => 'semester'], 15, $this->admin);
    $second = $scheduler->start($this->period, ['type' => 'semester'], 15, $this->admin);

    expect($second->id)->toBe($first->id)
        ->and(OptimizationJob::query()->count())->toBe(1);
});

test('polling membedakan antrean yang menunggu dan proses yang melewati batas', function () {
    $scheduler = app(SchedulerService::class);
    $job = $scheduler->start($this->period, ['type' => 'semester'], 15, $this->admin);
    $job->update(['status' => OptimizationJobStatus::Running, 'started_at' => now()]);

    expect($scheduler->poll($job)->status)->toBe(OptimizationJobStatus::Running);

    $late = $scheduler->start($this->period, ['type' => 'kelas', 'class_ids' => [$this->tekomC->id]], 15, $this->admin);
    $late->update(['status' => OptimizationJobStatus::Running, 'started_at' => now()]);
    $this->travel(15 + config('sicma.scheduler.grace_seconds') + 5)->seconds();

    expect($scheduler->poll($late->fresh())->status)->toBe(OptimizationJobStatus::Failed);
});

test('mode sync menjalankan PHP tanpa proses eksternal dan menyimpan hasilnya', function () {
    config(['sicma.scheduler.run_mode' => 'sync']);
    Process::fake();

    $job = app(SchedulerService::class)->start($this->period, ['type' => 'semester'], 15, $this->admin);

    expect($job->solver_status)->toBe(SolverStatus::Feasible)
        ->and($job->result['draft_version_id'])->not->toBeNull();
    Process::assertNothingRan();
});

test('halaman susun jadwal: pengaturan, periksa kesiapan, mulai, riwayat', function () {
    $this->withoutVite()->actingAs($this->admin)->get(route('admin.scheduler.create'))->assertOk()->assertSee('Susun jadwal otomatis')->assertSee('Pencarian otomatis maksimal 60 detik')->assertDontSee('name="time_limit"', false);

    $this->from(route('admin.scheduler.create'))
        ->post(route('admin.scheduler.check'), ['scope' => 'semester', 'time_limit' => 60])
        ->assertSessionHas('readiness');

    $sampleClass = ClassGroup::query()->first();
    if ($sampleClass) {
        $this->from(route('admin.scheduler.create'))
            ->post(route('admin.scheduler.check'), ['scope' => 'kelas', 'class_ids' => [$sampleClass->id]])
            ->assertSessionHas('readiness');
    }

    $this->post(route('admin.scheduler.store'), ['scope' => 'semester', 'time_limit' => 60])
        ->assertRedirect(route('admin.scheduler.show', OptimizationJob::query()->sole()));

    $this->post(route('admin.scheduler.store'), ['scope' => 'semester', 'time_limit' => 61])->assertSessionHasErrors('time_limit');
    $this->get(route('admin.scheduler.history'))->assertOk()->assertSee('Riwayat penyusunan otomatis');
    $this->getJson(route('admin.scheduler.status', OptimizationJob::query()->sole()))->assertJson(['status' => 'queued']);
});

test('generator PHP sungguhan menghasilkan draf tanpa pimpinan di hari Kamis', function () {
    config(['sicma.scheduler.run_mode' => 'sync']);
    $this->leaderSession->update(['day_of_week' => 4]);

    $job = app(SchedulerService::class)->start($this->period, ['type' => 'semester'], 15, $this->admin);

    expect($job->solver_status)->toBeIn([SolverStatus::Optimal, SolverStatus::Feasible]);

    $draft = ScheduleVersion::query()->findOrFail($job->result['draft_version_id']);
    $leaderCopy = $draft->sessions()->whereHas('lecturers', fn ($query) => $query->whereKey($this->leader->id))->sole();

    expect($leaderCopy->day_of_week)->not->toBe(4)
        ->and($draft->sessions()->where('mode', SessionMode::Unscheduled)->exists())->toBeFalse()
        ->and($draft->issues()->blocking()->count())->toBe(0);
});

test('permintaan penyusunan baru memakai 60 detik walaupun browser mengirim mode lama', function () {
    $this->actingAs($this->admin)->post(route('admin.scheduler.store'), ['scope' => 'semester', 'time_limit' => 180])->assertRedirect();
    expect(OptimizationJob::query()->sole()->time_limit_seconds)->toBe(60);
});

test('pencarian ulang 180 detik menjaga dasar kunci cakupan dan draf parsial serta tidak menggandakan pekerjaan', function () {
    $scheduler = app(SchedulerService::class);
    $this->leaderSession->update(['is_locked' => true]);
    $previous = $scheduler->start($this->period, ['type' => 'semester'], 60, $this->admin);
    $previous = $scheduler->finalize($previous, ['status' => 'UNKNOWN', 'assignments' => [
        ['id' => $this->leaderSession->id, 'day' => 1, 'start_minute' => 450, 'end_minute' => 600, 'room_id' => $this->room->id],
    ]]);
    $oldDraft = ScheduleVersion::query()->findOrFail($previous->result['draft_version_id']);
    $oldSessions = $oldDraft->sessions()->get()->toArray();

    $this->actingAs($this->admin)->post(route('admin.scheduler.retry', $previous))->assertRedirect();
    $retry = OptimizationJob::query()->latest('id')->firstOrFail();
    $this->post(route('admin.scheduler.retry', $previous))->assertRedirect(route('admin.scheduler.show', $retry));

    expect($retry->id)->not->toBe($previous->id)
        ->and($retry->time_limit_seconds)->toBe(180)
        ->and($retry->scope['base_version_id'])->toBe($this->official->id)
        ->and($retry->scope['retry_of_job_id'])->toBe($previous->id)
        ->and(OptimizationJob::query()->count())->toBe(2)
        ->and($oldDraft->sessions()->get()->toArray())->toBe($oldSessions)
        ->and($scheduler->canRetry($retry))->toBeFalse();
    $input = json_decode(File::get($scheduler->inputPath($retry)), true);
    expect($input['sessions'][0]['locked'])->toBe(['day' => 1, 'start' => 450, 'room' => $this->room->id]);
});

test('pencarian ulang menolak data dasar yang berubah tanpa membuat pekerjaan baru', function () {
    $scheduler = app(SchedulerService::class);
    $previous = $scheduler->finalize($scheduler->start($this->period, ['type' => 'semester'], 60, $this->admin), ['status' => 'UNKNOWN', 'assignments' => []]);
    $this->room->update(['capacity' => 46]);

    $this->actingAs($this->admin)->from(route('admin.scheduler.show', $previous))
        ->post(route('admin.scheduler.retry', $previous))->assertSessionHas('error_code', 'DATA_CHANGED');

    expect(OptimizationJob::query()->count())->toBe(1);
});

test('pencarian ulang hanya tersedia untuk hasil 60 detik yang belum lengkap', function (string $status, ?string $solverStatus, int $limit) {
    $job = OptimizationJob::query()->create([
        'schedule_version_id' => $this->official->id, 'created_by' => $this->admin->id,
        'scope' => ['type' => 'semester', 'base_version_id' => $this->official->id],
        'input_hash' => str_repeat('a', 64), 'time_limit_seconds' => $limit, 'status' => $status, 'solver_status' => $solverStatus,
    ]);

    $this->actingAs($this->admin)->post(route('admin.scheduler.retry', $job))->assertSessionHas('error_code', 'RETRY_NOT_ALLOWED');
    expect(OptimizationJob::query()->count())->toBe(1);
})->with([
    ['running', null, 60], ['cancelled', null, 60], ['failed', null, 60],
    ['finished', 'MODEL_INVALID', 60], ['finished', 'INFEASIBLE', 60],
    ['finished', 'FEASIBLE', 60], ['finished', 'UNKNOWN', 180],
]);

test('status pekerjaan menyediakan kemajuan nyata dan waktu selesai tidak terus bertambah', function () {
    $job = app(SchedulerService::class)->start($this->period, ['type' => 'semester'], 60, $this->admin);
    $job->update(['status' => OptimizationJobStatus::Running, 'started_at' => now(), 'result' => ['progress' => ['stage' => 'Mencari susunan', 'total' => 3, 'placed' => 1]]]);
    $this->travel(4)->seconds();

    $this->actingAs($this->admin)->getJson(route('admin.scheduler.status', $job))
        ->assertOk()
        ->assertJsonPath('progress.placed', 1)
        ->assertJsonPath('progress.total', 3)
        ->assertJsonPath('elapsed', 4)
        ->assertJsonPath('draft_version_id', null)
        ->assertJsonPath('inspect_url', null);
    $job->update(['status' => OptimizationJobStatus::Finished, 'finished_at' => now(), 'solver_status' => SolverStatus::Unknown, 'result' => ['placed' => 1, 'unplaced' => [2, 3], 'draft_version_id' => 99, 'draft_version_no' => 7]]);
    $this->travel(20)->seconds();
    $this->getJson(route('admin.scheduler.status', $job))
        ->assertJsonPath('elapsed', 4)
        ->assertJsonPath('progress.stage', 'Selesai')
        ->assertJsonPath('draft_version_id', 99)
        ->assertJsonPath('draft_version_no', 7)
        ->assertJsonPath('inspect_url', route('admin.drafts.inspect', 99));
});

test('Keti dan tamu tidak dapat meminta pencarian ulang', function () {
    $job = app(SchedulerService::class)->start($this->period, ['type' => 'semester'], 60, $this->admin);
    $this->actingAs(User::factory()->approvedKeti()->create())->post(route('admin.scheduler.retry', $job))->assertForbidden();
    auth()->logout();
    $this->post(route('admin.scheduler.retry', $job))->assertRedirect(route('login'));
    expect(OptimizationJob::query()->count())->toBe(1);
});

test('antrean membawa pekerjaan PHP dan proses duplikat tidak membuat draf kedua', function () {
    config(['sicma.scheduler.run_mode' => 'queue']);
    Queue::fake([RunScheduleOptimization::class]);
    $scheduler = app(SchedulerService::class);
    $job = $scheduler->start($this->period, ['type' => 'semester'], 15, $this->admin);
    Queue::assertPushed(RunScheduleOptimization::class, fn ($queued): bool => $queued->optimizationJob->id === $job->id);
    (new RunScheduleOptimization($job))->handle($scheduler);
    $firstDraft = $job->fresh()->result['draft_version_id'];
    $scheduler->runNow($job);
    expect($job->fresh()->result['draft_version_id'])->toBe($firstDraft)
        ->and(ScheduleVersion::query()->where('source', ScheduleSource::Generated)->count())->toBe(1);
});

test('hasil parsial disimpan sebagai draf yang belum layak dengan sesi tersisa tanpa jadwal', function () {
    $scheduler = app(SchedulerService::class);
    $job = $scheduler->start($this->period, ['type' => 'semester'], 15, $this->admin);
    $job = $scheduler->finalize($job, ['status' => 'UNKNOWN', 'assignments' => [
        ['id' => $this->leaderSession->id, 'day' => 1, 'start_minute' => 450, 'end_minute' => 600, 'room_id' => $this->room->id],
    ]]);
    $draft = ScheduleVersion::query()->findOrFail($job->result['draft_version_id']);
    expect($job->solver_status)->toBe(SolverStatus::Unknown)
        ->and($job->result['publishable'])->toBeFalse()
        ->and($job->result['unplaced'])->toHaveCount(2)
        ->and($draft->sessions()->where('mode', SessionMode::Unscheduled)->count())->toBe(2);
    $this->withoutVite()->actingAs($this->admin)->get(route('admin.scheduler.show', $job))
        ->assertOk()->assertSee('Draf masih perlu diperbaiki')->assertDontSee('Seluruh aturan wajib terpenuhi');
});

test('keluaran tidak boleh memindahkan sesi di luar cakupan atau mengabaikan kunci', function (bool $outsideScope) {
    $this->leaderSession->update(['is_locked' => true]);
    $scheduler = app(SchedulerService::class);
    $job = $scheduler->start($this->period, ['type' => 'kelas', 'class_ids' => [$this->tekomC->id]], 15, $this->admin);
    $job = $scheduler->finalize($job, ['status' => 'FEASIBLE', 'assignments' => [
        ['id' => $outsideScope ? $this->otherSession->id : $this->leaderSession->id, 'day' => 3, 'start_minute' => 450, 'end_minute' => 600, 'room_id' => $this->room->id],
    ]]);
    expect($job->solver_status)->toBe(SolverStatus::ModelInvalid)
        ->and($job->result)->not->toHaveKey('draft_version_id');
})->with([true, false]);

test('pembatalan dan kegagalan terlambat tidak menimpa status akhir', function () {
    $scheduler = app(SchedulerService::class);
    $job = $scheduler->start($this->period, ['type' => 'semester'], 15, $this->admin);
    $scheduler->cancel($job, $this->admin);
    $scheduler->runNow($job);
    $scheduler->fail($job, 'Gagal terlambat');
    expect($job->fresh()->status)->toBe(OptimizationJobStatus::Cancelled)
        ->and(ScheduleVersion::query()->where('source', ScheduleSource::Generated)->count())->toBe(0);
});

test('sesi terkunci tanpa waktu harus dilengkapi sebelum generator dijalankan', function () {
    $this->unscheduled->update(['is_locked' => true]);
    expect(app(SchedulerService::class)->readiness($this->period, ['type' => 'semester'])['errors'])
        ->toContain('Lengkapi waktu sesi terkunci atau buka kuncinya sebelum menyusun jadwal.');
});

test('masukan dasar yang diubah saat antrean menunggu tidak menghasilkan draf kedaluwarsa', function () {
    $scheduler = app(SchedulerService::class);
    $job = $scheduler->start($this->period, ['type' => 'semester'], 15, $this->admin);
    $this->official->increment('lock_version');
    $job = $scheduler->runNow($job);
    expect($job->solver_status)->toBe(SolverStatus::ModelInvalid)
        ->and($job->result)->not->toHaveKey('draft_version_id');
});

test('generator menghindari reservasi dan penutupan ruang tanpa memindahkan sesi di luar cakupan', function () {
    foreach ([['reservation', '2026-11-09 07:30', '2026-11-09 10:00'], ['closure', '2026-11-16 10:00', '2026-11-16 12:30']] as [$type, $start, $end]) {
        RoomBlock::factory()->for($this->room)->create(['source_type' => $type, 'start_at' => $start, 'end_at' => $end]);
    }
    $scheduler = app(SchedulerService::class);
    $job = $scheduler->runNow($scheduler->start($this->period, ['type' => 'kelas', 'class_ids' => [$this->tekomC->id]], 15, $this->admin));
    $draft = ScheduleVersion::query()->findOrFail($job->result['draft_version_id']);
    $session = $draft->sessions()->whereHas('participants', fn ($query) => $query->whereKey($this->tekomC->id))->sole();
    $fixed = $draft->sessions()->where('title', $this->otherSession->title)->sole();
    expect($session->day_of_week)->not->toBe(1)
        ->and([$fixed->day_of_week, $fixed->start_minute, $fixed->end_minute])->toBe([2, 450, 600]);
});

test('waktu menunggu antrean terpisah dari waktu pencarian dan kegagalan job tercatat', function () {
    $scheduler = app(SchedulerService::class);
    $job = $scheduler->start($this->period, ['type' => 'semester'], 15, $this->admin);
    $this->travel(60)->seconds();
    expect($scheduler->poll($job)->status)->toBe(OptimizationJobStatus::Queued);
    (new RunScheduleOptimization($job))->failed(new RuntimeException('Queue timeout'));
    expect($job->fresh()->status)->toBe(OptimizationJobStatus::Failed)
        ->and($job->fresh()->result['message'])->toContain('generator PHP');
});

test('hasil dengan jam di luar slot ditolak walaupun status solver menyatakan layak', function () {
    $scheduler = app(SchedulerService::class);
    $job = $scheduler->start($this->period, ['type' => 'kelas', 'class_ids' => [$this->tekomC->id]], 15, $this->admin);
    $job = $scheduler->finalize($job, ['status' => 'FEASIBLE', 'assignments' => [
        ['id' => $this->leaderSession->id, 'day' => 1, 'start_minute' => 0, 'end_minute' => 150, 'room_id' => $this->room->id],
    ]]);
    expect($job->solver_status)->toBe(SolverStatus::ModelInvalid)
        ->and($job->result)->not->toHaveKey('draft_version_id');
});

test('perubahan ketersediaan saat menunggu membatalkan snapshot lama', function () {
    $scheduler = app(SchedulerService::class);
    $job = $scheduler->start($this->period, ['type' => 'semester'], 15, $this->admin);
    LecturerAvailability::create(['lecturer_id' => $this->leader->id, 'period_id' => $this->period->id,
        'day_of_week' => 1, 'start_minute' => 450, 'end_minute' => 600, 'type' => 'unavailable']);
    $job = $scheduler->runNow($job);
    expect($job->solver_status)->toBe(SolverStatus::ModelInvalid)
        ->and($job->result)->not->toHaveKey('draft_version_id');
});

test('snapshot yang rusak tidak dijalankan', function () {
    $scheduler = app(SchedulerService::class);
    $job = $scheduler->start($this->period, ['type' => 'semester'], 15, $this->admin);
    File::put($scheduler->inputPath($job), '{}');
    $job = $scheduler->runNow($job);
    expect($job->status)->toBe(OptimizationJobStatus::Failed)
        ->and($job->result['message'])->toContain('Snapshot masukan berubah atau rusak');
});

test('sesi tersisa pada draf parsial mempertahankan durasi aslinya untuk pencarian berikutnya', function () {
    $this->otherSession->update(['end_minute' => 610]);
    $scheduler = app(SchedulerService::class);
    $job = $scheduler->start($this->period, ['type' => 'semester'], 15, $this->admin);
    $job = $scheduler->finalize($job, ['status' => 'UNKNOWN', 'assignments' => [
        ['id' => $this->leaderSession->id, 'day' => 1, 'start_minute' => 450, 'end_minute' => 600, 'room_id' => $this->room->id],
    ]]);
    $draft = ScheduleVersion::query()->findOrFail($job->result['draft_version_id']);
    $copy = $draft->sessions()->where('title', $this->otherSession->title)->sole();
    $input = $scheduler->snapshot($draft, ['type' => 'belum-terjadwal'], 15);
    expect(collect($input['sessions'])->firstWhere('id', $copy->id)['duration'])->toBe(160)
        ->and($copy->mode)->toBe(SessionMode::Unscheduled);
});

test('kesiapan menolak pembagian fase tanpa kalender UTS dan semester yang telah berakhir', function () {
    $this->leaderSession->update(['phase' => 'before_uts']);
    $this->period->update(['uts_start' => null, 'uts_end' => null]);
    $scheduler = app(SchedulerService::class);
    expect($scheduler->readiness($this->period, ['type' => 'semester'])['errors'])
        ->toContain('Lengkapi rentang UTS yang valid untuk membedakan sesi sebelum dan setelah UTS.');
    $this->period->update(['end_date' => '2026-10-31']);
    expect($scheduler->readiness($this->period, ['type' => 'semester'])['errors'])
        ->toContain('Rentang semester tidak valid atau sudah berakhir. Periksa kalender semester.');
});

test('cakupan terdampak menyertakan bentrok ruang dan dosen yang diturunkan menjadi peringatan oleh allow_conflicts dan menghasilkan draf bersih', function () {
    config(['sicma.scheduler.run_mode' => 'sync']);

    $draftBase = ScheduleVersion::factory()->for($this->period)->create([
        'version_no' => 3,
        'status' => ScheduleVersionStatus::Draft,
        'allow_conflicts' => true,
        'effective_from' => '2026-09-01',
        'lock_version' => 0,
    ]);
    $sharedLecturer = Lecturer::factory()->create();
    $otherLecturer = Lecturer::factory()->create();
    $otherClass = ClassGroup::factory()->create(['student_count' => 25]);

    $first = ScheduleSession::factory()->for($draftBase, 'version')->create([
        'title' => 'Sistem Operasi',
        'room_id' => $this->room->id,
        'day_of_week' => 1,
        'start_minute' => 450,
        'end_minute' => 600,
    ]);
    $first->participants()->sync([$this->tekomC->id]);
    $first->lecturers()->sync([$sharedLecturer->id => ['role' => 'lead']]);

    $second = ScheduleSession::factory()->for($draftBase, 'version')->create([
        'title' => 'Basis Data',
        'room_id' => $this->room->id,
        'day_of_week' => 1,
        'start_minute' => 500,
        'end_minute' => 650,
    ]);
    $second->participants()->sync([$this->ptikB->id]);
    $second->lecturers()->sync([$sharedLecturer->id => ['role' => 'lead']]);

    $unaffected = ScheduleSession::factory()->for($draftBase, 'version')->create([
        'title' => 'Jaringan Komputer',
        'room_id' => $this->room->id,
        'day_of_week' => 3,
        'start_minute' => 450,
        'end_minute' => 600,
    ]);
    $unaffected->participants()->sync([$otherClass->id]);
    $unaffected->lecturers()->sync([$otherLecturer->id => ['role' => 'lead']]);

    app(ScheduleValidator::class)->validate($draftBase);
    expect($draftBase->issues()->where('severity', IssueSeverity::Error)->count())->toBe(0)
        ->and($draftBase->issues()->where('severity', IssueSeverity::Warning)->pluck('code')->all())
        ->toContain('ROOM_CONFLICT', 'LECTURER_CONFLICT');

    $scheduler = app(SchedulerService::class);
    $readiness = $scheduler->readiness($this->period, ['type' => 'terdampak']);

    expect($readiness['errors'])->toBe([])
        ->and($readiness['in_scope'])->toBe(2)
        ->and($readiness['fixed'])->toBe(1);

    $job = $scheduler->start($this->period, ['type' => 'terdampak'], 15, $this->admin);
    $newDraft = ScheduleVersion::query()->findOrFail($job->result['draft_version_id']);
    $kept = $newDraft->sessions()->where('title', 'Jaringan Komputer')->sole();

    expect($job->solver_status)->toBe(SolverStatus::Feasible)
        ->and($job->result['publishable'])->toBeTrue()
        ->and($newDraft->allow_conflicts)->toBeFalse()
        ->and($newDraft->issues()->blocking()->count())->toBe(0)
        ->and($newDraft->issues()->whereIn('code', ['ROOM_CONFLICT', 'LECTURER_CONFLICT', 'CLASS_CONFLICT'])->count())->toBe(0)
        ->and([$kept->day_of_week, $kept->start_minute, $kept->end_minute, $kept->room_id])->toBe([3, 450, 600, $this->room->id]);
});

test('cakupan terdampak mendeteksi bentrok ruang, dosen, dan kelas pada draf yang belum pernah divalidasi lalu menatanya ulang tanpa bentrok', function () {
    config(['sicma.scheduler.run_mode' => 'sync']);

    $unvalidatedDraft = ScheduleVersion::factory()->for($this->period)->create([
        'version_no' => 3,
        'status' => ScheduleVersionStatus::Draft,
        'effective_from' => '2026-09-01',
        'lock_version' => 0,
    ]);
    $secondRoom = Room::factory()->create(['capacity' => 45]);
    $lecturerA = Lecturer::factory()->create();
    $lecturerB = Lecturer::factory()->create();
    $sharedLecturer = Lecturer::factory()->create();
    $lecturerD = Lecturer::factory()->create();
    $lecturerE = Lecturer::factory()->create();
    $lecturerF = Lecturer::factory()->create();
    $classC = ClassGroup::factory()->create(['student_count' => 25]);
    $classD = ClassGroup::factory()->create(['student_count' => 25]);
    $sharedClass = ClassGroup::factory()->create(['student_count' => 25]);
    $unaffectedClass = ClassGroup::factory()->create(['student_count' => 25]);

    $createSession = function (string $title, int $roomId, int $day, int $start, int $end, int $classId, int $lecturerId) use ($unvalidatedDraft): void {
        $session = ScheduleSession::factory()->for($unvalidatedDraft, 'version')->create([
            'title' => $title,
            'room_id' => $roomId,
            'day_of_week' => $day,
            'start_minute' => $start,
            'end_minute' => $end,
        ]);
        $session->participants()->sync([$classId]);
        $session->lecturers()->sync([$lecturerId => ['role' => 'lead']]);
    };

    $createSession('Bentrok Ruang 1', $this->room->id, 1, 450, 600, $this->tekomC->id, $lecturerA->id);
    $createSession('Bentrok Ruang 2', $this->room->id, 1, 500, 650, $this->ptikB->id, $lecturerB->id);
    $createSession('Bentrok Dosen 1', $this->room->id, 2, 450, 600, $classC->id, $sharedLecturer->id);
    $createSession('Bentrok Dosen 2', $secondRoom->id, 2, 500, 650, $classD->id, $sharedLecturer->id);
    $createSession('Bentrok Kelas 1', $this->room->id, 3, 450, 600, $sharedClass->id, $lecturerD->id);
    $createSession('Bentrok Kelas 2', $secondRoom->id, 3, 500, 650, $sharedClass->id, $lecturerE->id);
    $createSession('Sesi Aman', $secondRoom->id, 4, 450, 600, $unaffectedClass->id, $lecturerF->id);

    expect($unvalidatedDraft->issues()->count())->toBe(0);

    $scheduler = app(SchedulerService::class);
    $readiness = $scheduler->readiness($this->period, ['type' => 'terdampak'], $unvalidatedDraft);

    expect($readiness['errors'])->toBe([])
        ->and($readiness['in_scope'])->toBe(6)
        ->and($readiness['fixed'])->toBe(1);

    $job = $scheduler->start($this->period, ['type' => 'terdampak'], 15, $this->admin, $unvalidatedDraft);
    $newDraft = ScheduleVersion::query()->findOrFail($job->result['draft_version_id']);
    $kept = $newDraft->sessions()->where('title', 'Sesi Aman')->sole();

    expect($job->solver_status)->toBe(SolverStatus::Feasible)
        ->and($job->result['publishable'])->toBeTrue()
        ->and($newDraft->issues()->blocking()->count())->toBe(0)
        ->and($newDraft->issues()->whereIn('code', ['ROOM_CONFLICT', 'LECTURER_CONFLICT', 'CLASS_CONFLICT'])->count())->toBe(0)
        ->and([$kept->day_of_week, $kept->start_minute, $kept->end_minute, $kept->room_id])->toBe([4, 450, 600, $secondRoom->id]);
});

test('periksa kesiapan dan mulai penyusunan menolak sesi terkunci yang saling bertabrakan atau bentrok dengan sesi di luar cakupan', function () {
    $scheduler = app(SchedulerService::class);

    $lockedPairDraft = ScheduleVersion::factory()->for($this->period)->create([
        'version_no' => 3,
        'status' => ScheduleVersionStatus::Draft,
        'effective_from' => '2026-09-01',
        'lock_version' => 0,
    ]);
    $lockedA = ScheduleSession::factory()->for($lockedPairDraft, 'version')->create([
        'room_id' => $this->room->id,
        'day_of_week' => 1,
        'start_minute' => 450,
        'end_minute' => 600,
        'is_locked' => true,
    ]);
    $lockedA->participants()->sync([$this->tekomC->id]);
    $lockedB = ScheduleSession::factory()->for($lockedPairDraft, 'version')->create([
        'room_id' => $this->room->id,
        'day_of_week' => 1,
        'start_minute' => 500,
        'end_minute' => 650,
        'is_locked' => true,
    ]);
    $lockedB->participants()->sync([$this->ptikB->id]);

    $pairError = '2 sesi terkunci saling bertabrakan atau bentrok dengan sesi di luar cakupan. Buka kunci sesi terkait atau perluas cakupan sebelum menyusun jadwal.';
    expect($scheduler->readiness($this->period, ['type' => 'semester'], $lockedPairDraft)['errors'])->toContain($pairError);
    expect(fn () => $scheduler->start($this->period, ['type' => 'semester'], 15, $this->admin, $lockedPairDraft))
        ->toThrow(DomainException::class, $pairError);

    $lockedVsFixedDraft = ScheduleVersion::factory()->for($this->period)->create([
        'version_no' => 4,
        'status' => ScheduleVersionStatus::Draft,
        'effective_from' => '2026-09-01',
        'lock_version' => 0,
    ]);
    $lockedInScope = ScheduleSession::factory()->for($lockedVsFixedDraft, 'version')->create([
        'room_id' => $this->room->id,
        'day_of_week' => 1,
        'start_minute' => 450,
        'end_minute' => 600,
        'is_locked' => true,
    ]);
    $lockedInScope->participants()->sync([$this->tekomC->id]);
    $fixedOverlap = ScheduleSession::factory()->for($lockedVsFixedDraft, 'version')->create([
        'room_id' => $this->room->id,
        'day_of_week' => 1,
        'start_minute' => 500,
        'end_minute' => 650,
        'is_locked' => false,
    ]);
    $fixedOverlap->participants()->sync([$this->ptikB->id]);

    $fixedError = '1 sesi terkunci saling bertabrakan atau bentrok dengan sesi di luar cakupan. Buka kunci sesi terkait atau perluas cakupan sebelum menyusun jadwal.';
    $classScope = ['type' => 'kelas', 'class_ids' => [$this->tekomC->id]];
    expect($scheduler->readiness($this->period, $classScope, $lockedVsFixedDraft)['errors'])->toContain($fixedError);
    expect(fn () => $scheduler->start($this->period, $classScope, 15, $this->admin, $lockedVsFixedDraft))
        ->toThrow(DomainException::class, $fixedError);
    expect(OptimizationJob::query()->count())->toBe(0);
});

test('periksa kesiapan memberi peringatan ketika sesi di luar cakupan sudah saling bentrok', function () {
    $conflictingFixed = ScheduleSession::factory()->for($this->official, 'version')->create([
        'room_id' => $this->room->id,
        'day_of_week' => 2,
        'start_minute' => 500,
        'end_minute' => 650,
    ]);
    $conflictingFixed->participants()->sync([$this->ptikB->id]);

    $readiness = app(SchedulerService::class)->readiness($this->period, ['type' => 'kelas', 'class_ids' => [$this->tekomC->id]]);

    expect($readiness['errors'])->toBe([])
        ->and($readiness['in_scope'])->toBe(1)
        ->and($readiness['fixed'])->toBe(3)
        ->and($readiness['warnings'])->toContain(
            'Terdapat 1 bentrok bawaan pada sesi di luar cakupan yang akan terbawa ke draf baru. Gunakan cakupan "Seluruh semester" atau "Bagian terdampak" agar bentrok tersebut ikut ditata ulang.'
        );
});

test('blok ruang berdurasi kurang dari satu menit atau nol menit tidak menggagalkan generator sebagai MODEL_INVALID', function () {
    RoomBlock::factory()->for($this->room)->create([
        'source_type' => 'reservation',
        'start_at' => '2026-11-09 11:36:00',
        'end_at' => '2026-11-09 11:36:41',
    ]);
    RoomBlock::factory()->for($this->room)->create([
        'source_type' => 'closure',
        'start_at' => '2026-11-09 12:00:00',
        'end_at' => '2026-11-09 12:00:00',
    ]);

    $scheduler = app(SchedulerService::class);
    $snapshot = $scheduler->snapshot($this->official, ['type' => 'semester'], 15);

    expect($snapshot['room_blocks'])->toHaveCount(1)
        ->and($snapshot['room_blocks'][0])->toMatchArray([
            'room' => $this->room->id,
            'date' => '2026-11-09',
            'start' => 696,
            'end' => 697,
        ]);

    $job = $scheduler->runNow($scheduler->start($this->period, ['type' => 'semester'], 15, $this->admin));

    expect($job->solver_status)->toBe(SolverStatus::Feasible)
        ->and($job->result['draft_version_id'] ?? null)->not->toBeNull();
});

test('penjaga tanpa perubahan tidak membuat draf duplikat dan menampilkan tautan pemeriksaan versi dasar', function () {
    $this->unscheduled->delete();
    $scheduler = app(SchedulerService::class);
    $beforeVersions = ScheduleVersion::query()->count();

    $job = $scheduler->start($this->period, ['type' => 'semester'], 60, $this->admin);
    $job = $scheduler->finalize($job, [
        'status' => 'FEASIBLE',
        'message' => 'Susunan ditemukan.',
        'assignments' => [
            ['id' => $this->leaderSession->id, 'day' => 1, 'start_minute' => 450, 'end_minute' => 600, 'room_id' => $this->room->id],
            ['id' => $this->otherSession->id, 'day' => 2, 'start_minute' => 450, 'end_minute' => 600, 'room_id' => $this->room->id],
        ],
    ]);

    expect($job->result['no_changes'] ?? false)->toBeTrue()
        ->and($job->result['changed'])->toBe(0)
        ->and($job->result)->not->toHaveKey('draft_version_id')
        ->and(ScheduleVersion::query()->count())->toBe($beforeVersions)
        ->and($scheduler->canRetry($job))->toBeFalse();

    $this->withoutVite()->actingAs($this->admin)
        ->get(route('admin.scheduler.show', $job))
        ->assertOk()
        ->assertSee('Tidak ada perubahan diperlukan')
        ->assertSee("Susunan v{$this->official->version_no} sudah optimal untuk cakupan ini")
        ->assertSee(route('admin.drafts.inspect', $this->official), false);

    $this->getJson(route('admin.scheduler.status', $job))
        ->assertOk()
        ->assertJson([
            'no_changes' => true,
            'base_version_id' => $this->official->id,
            'draft_version_id' => null,
        ]);

    // Bahkan ketika masih ada unplaced (status solver UNKNOWN) pada sesi yang memang belum terjadwal,
    // tanpa perubahan penempatan tidak dibuat draf baru dan canRetry() tetap false.
    $extraUnscheduled = ScheduleSession::factory()->unscheduled()->for($this->official, 'version')->create();
    $extraUnscheduled->participants()->sync([$this->ptikB->id]);
    $this->official->increment('lock_version');

    $partialNoChangeJob = $scheduler->start($this->period, ['type' => 'semester'], 60, $this->admin);
    $partialNoChangeJob = $scheduler->finalize($partialNoChangeJob, [
        'status' => 'UNKNOWN',
        'assignments' => [
            ['id' => $this->leaderSession->id, 'day' => 1, 'start_minute' => 450, 'end_minute' => 600, 'room_id' => $this->room->id],
            ['id' => $this->otherSession->id, 'day' => 2, 'start_minute' => 450, 'end_minute' => 600, 'room_id' => $this->room->id],
        ],
    ]);

    expect($partialNoChangeJob->result['no_changes'] ?? false)->toBeTrue()
        ->and($partialNoChangeJob->solver_status)->toBe(SolverStatus::Unknown)
        ->and(ScheduleVersion::query()->count())->toBe($beforeVersions)
        ->and($scheduler->canRetry($partialNoChangeJob))->toBeFalse();
});

test('mode susun ulang segar menghapus preferensi sesi tidak terkunci, menjaga sesi terkunci, menyimpan label draf, dan menghasilkan variasi antar seed', function () {
    $this->leaderSession->update(['is_locked' => true]);
    $scheduler = app(SchedulerService::class);

    $freshSnapshot = $scheduler->snapshot($this->official, ['type' => 'semester', 'strategy' => 'fresh', 'seed' => 42], 15);
    $sessionsById = collect($freshSnapshot['sessions'])->keyBy('id');

    expect($freshSnapshot['strategy'])->toBe('fresh')
        ->and($freshSnapshot['seed'])->toBe(42)
        ->and($sessionsById[$this->leaderSession->id]['locked'])->toBe(['day' => 1, 'start' => 450, 'room' => $this->room->id])
        ->and($sessionsById[$this->leaderSession->id]['preferred'])->toBe(['day' => 1, 'start' => 450, 'room' => $this->room->id])
        ->and($sessionsById[$this->otherSession->id]['locked'])->toBeNull()
        ->and($sessionsById[$this->otherSession->id]['preferred'])->toBeNull();

    $jobOne = $scheduler->runNow($scheduler->start($this->period, [
        'type' => 'semester',
        'strategy' => 'fresh',
        'seed' => 11,
        'label' => '  Opsi Pagi Padat  ',
    ], 15, $this->admin));

    $draftOne = ScheduleVersion::query()->findOrFail($jobOne->result['draft_version_id']);
    $lockedCopyOne = $draftOne->sessions()->whereHas('lecturers', fn ($query) => $query->whereKey($this->leader->id))->sole();

    expect($jobOne->solver_status)->toBe(SolverStatus::Feasible)
        ->and($jobOne->scope['strategy'])->toBe('fresh')
        ->and($jobOne->scope['seed'])->toBe(11)
        ->and($draftOne->label)->toBe('Opsi Pagi Padat')
        ->and([$lockedCopyOne->day_of_week, $lockedCopyOne->start_minute, $lockedCopyOne->end_minute, $lockedCopyOne->room_id])
        ->toBe([1, 450, 600, $this->room->id]);

    $jobTwo = $scheduler->runNow($scheduler->start($this->period, [
        'type' => 'semester',
        'strategy' => 'fresh',
        'label' => 'Alternatif Kedua',
    ], 15, $this->admin));

    $draftTwo = ScheduleVersion::query()->findOrFail($jobTwo->result['draft_version_id']);

    expect($jobTwo->scope['seed'])->toBeGreaterThan(0)
        ->and($jobTwo->scope['seed'])->not->toBe($jobOne->scope['seed'])
        ->and($draftTwo->label)->toBe('Alternatif Kedua');

    $placements = [];
    foreach ([1, 2, 3, 5, 7, 11] as $seed) {
        $candidateJob = $scheduler->runNow($scheduler->start($this->period, [
            'type' => 'semester',
            'strategy' => 'fresh',
            'seed' => $seed,
        ], 15, $this->admin));

        $candidateDraft = ScheduleVersion::query()->findOrFail($candidateJob->result['draft_version_id']);
        $movedOther = $candidateDraft->sessions()->where('title', $this->otherSession->title)->sole();
        $placements[] = "{$movedOther->day_of_week}:{$movedOther->start_minute}:{$movedOther->room_id}";
    }

    expect(collect($placements)->unique()->count())->toBeGreaterThan(1);
});
