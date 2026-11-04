<?php

use App\Enums\OptimizationJobStatus;
use App\Enums\ScheduleSource;
use App\Enums\ScheduleVersionStatus;
use App\Enums\SessionMode;
use App\Enums\SolverStatus;
use App\Exceptions\DomainException;
use App\Models\ClassGroup;
use App\Models\LeadershipTerm;
use App\Models\Lecturer;
use App\Models\OptimizationJob;
use App\Models\Period;
use App\Models\Room;
use App\Models\ScheduleSession;
use App\Models\ScheduleVersion;
use App\Models\User;
use App\Services\SchedulerService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

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

test('hasil OPTIMAL selalu menjadi draf baru tanpa mengubah jadwal resmi', function () {
    $scheduler = app(SchedulerService::class);
    $job = $scheduler->start($this->period, ['type' => 'semester'], 15, $this->admin);

    expect(File::exists($scheduler->inputPath($job)))->toBeTrue();

    $job = $scheduler->finalize($job, [
        'status' => 'OPTIMAL',
        'message' => 'Susunan terbaik ditemukan.',
        'assignments' => [
            ['id' => $this->leaderSession->id, 'day' => 3, 'start_minute' => 450, 'end_minute' => 600, 'room_id' => $this->room->id],
            ['id' => $this->unscheduled->id, 'day' => 5, 'start_minute' => 450, 'end_minute' => 550, 'room_id' => $this->room->id],
        ],
    ]);

    $draft = ScheduleVersion::query()->findOrFail($job->result['draft_version_id']);
    $placed = $draft->sessions()->whereHas('participants', fn ($query) => $query->whereKey($this->ptikB->id))->where('day_of_week', 5)->sole();

    expect($job->status)->toBe(OptimizationJobStatus::Finished)
        ->and($job->solver_status)->toBe(SolverStatus::Optimal)
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
    $job = $scheduler->finalize($scheduler->start($this->period, ['type' => 'semester'], 15, $this->admin), ['status' => 'FEASIBLE', 'assignments' => []]);

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

test('polling membaca keluaran worker dan menggagalkan proses yang melewati batas', function () {
    $scheduler = app(SchedulerService::class);
    $job = $scheduler->start($this->period, ['type' => 'semester'], 15, $this->admin);
    $job->update(['status' => OptimizationJobStatus::Running, 'started_at' => now()]);

    expect($scheduler->poll($job)->status)->toBe(OptimizationJobStatus::Running);

    File::put($scheduler->outputPath($job), json_encode(['status' => 'INFEASIBLE', 'assignments' => []]));
    expect($scheduler->poll($job->fresh())->solver_status)->toBe(SolverStatus::Infeasible);

    $late = $scheduler->start($this->period, ['type' => 'kelas', 'class_ids' => [$this->tekomC->id]], 15, $this->admin);
    $late->update(['status' => OptimizationJobStatus::Running, 'started_at' => now()]);
    $this->travel(15 + config('sicma.scheduler.grace_seconds') + 5)->seconds();

    expect($scheduler->poll($late->fresh())->status)->toBe(OptimizationJobStatus::Failed);
});

test('mode sync menjalankan worker dan menyimpan hasilnya', function () {
    config(['sicma.scheduler.run_mode' => 'sync']);
    Process::fake(function () {
        $job = OptimizationJob::query()->latest('id')->first();
        File::put(app(SchedulerService::class)->outputPath($job), json_encode(['status' => 'OPTIMAL', 'assignments' => []]));

        return Process::result('');
    });

    $job = app(SchedulerService::class)->start($this->period, ['type' => 'semester'], 15, $this->admin);

    expect($job->solver_status)->toBe(SolverStatus::Optimal)
        ->and($job->result['draft_version_id'])->not->toBeNull();
    Process::assertRan(fn ($process) => str_contains(implode(' ', (array) $process->command), 'solve.py'));
});

test('halaman susun jadwal: pengaturan, periksa kesiapan, mulai, riwayat', function () {
    $this->withoutVite()->actingAs($this->admin)->get(route('admin.scheduler.create'))->assertOk()->assertSee('Susun jadwal otomatis')->assertSee('Seimbang • 60 detik');

    $this->from(route('admin.scheduler.create'))
        ->post(route('admin.scheduler.check'), ['scope' => 'semester', 'time_limit' => 60])
        ->assertSessionHas('readiness');

    $this->post(route('admin.scheduler.store'), ['scope' => 'semester', 'time_limit' => 60])
        ->assertRedirect(route('admin.scheduler.show', OptimizationJob::query()->sole()));

    $this->post(route('admin.scheduler.store'), ['scope' => 'semester', 'time_limit' => 61])->assertSessionHasErrors('time_limit');
    $this->get(route('admin.scheduler.history'))->assertOk()->assertSee('Riwayat penyusunan otomatis');
    $this->getJson(route('admin.scheduler.status', OptimizationJob::query()->sole()))->assertJson(['status' => 'queued']);
});

test('worker Python sungguhan menghasilkan draf tanpa pimpinan di hari Kamis', function () {
    $python = config('sicma.scheduler.python_bin');
    $check = Process::run([$python, '-c', 'import ortools']);

    if (! $check->successful()) {
        $this->markTestSkipped('Python dengan OR-Tools tidak tersedia di lingkungan ini.');
    }

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
