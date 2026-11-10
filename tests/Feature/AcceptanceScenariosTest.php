<?php

/*
|--------------------------------------------------------------------------
| Skenario penerimaan (SICMA_Workflow_AI_Agent.md Fase 11)
|--------------------------------------------------------------------------
|
| Peta skenario → tes yang membuktikannya:
|  1 class_group_id palsu / reservasi kelas lain ditolak  → Keti/KetiPortalTest: "kelas pemesan diambil dari akun…", "Keti kelas lain tidak bisa membuka…"
|  2 Keti pending / email belum terverifikasi ditolak     → Services/ReservationServiceTest: "akun yang belum boleh memesan ditolak", Auth/AuthorizationTest
|  3 Draf diubah lalu ditetapkan                           → file ini
|  4 Pimpinan Kamis (manual & hasil susun)                 → Services/ScheduleValidatorTest, Services/SchedulePublisherTest, file ini (hasil susun)
|  5 Kosong sekarang tapi ada agenda sebelum durasi habis  → Services/AvailabilityServiceTest, Services/ReservationServiceTest
|  6 Dua reservasi bertabrakan → tepat satu sukses         → Services/ReservationServiceTest: "dua kelas memesan slot yang sama…"
|  7 Idempotency key sama → satu reservasi                 → Services/ReservationServiceTest, Keti/KetiPortalTest
|  8 Mulai dua kali lalu selesai                           → Services/ReservationServiceTest: "mulai gunakan dua kali lalu selesai…"
|  9 Batal saat Dipesan → slot terbuka, audit tersimpan    → Services/ReservationServiceTest: "pembatalan membuka slot lagi…"
| 10 Kuliah resmi tanpa check-in                           → Services/ReservationServiceTest, Services/AvailabilityServiceTest
| 11 Reservasi masuk setelah pratinjau penetapan           → Services/SchedulePublisherTest: "reservasi yang masuk setelah pratinjau…"
| 12 Hari libur, A.end = B.start                           → ScheduleBlockBuilderTest, Services/AvailabilityServiceTest, Services/ReservationServiceTest
| 13 Sesi gabungan & online                                → ScheduleBlockBuilderTest
| 14 Status konsisten publik, Keti, dashboard admin        → file ini
| 15 Impor file yang sama 2×                               → Services/ScheduleImportServiceTest
| 16 Kode verifikasi salah > 5×                            → Auth/EmailVerificationTest: "kode dikunci setelah 5 kali salah…"
| 17 Hasil susun otomatis masuk draf                       → Services/SchedulerServiceTest
|
*/

use App\Enums\ScheduleSource;
use App\Enums\ScheduleVersionStatus;
use App\Enums\SolverStatus;
use App\Exceptions\DomainException;
use App\Models\Academic\ClassGroup;
use App\Models\Academic\Period;
use App\Models\Lecturer\LeadershipTerm;
use App\Models\Lecturer\Lecturer;
use App\Models\Room\Room;
use App\Models\Scheduling\ScheduleSession;
use App\Models\Scheduling\ScheduleVersion;
use App\Models\User\User;
use App\Services\Scheduling\ScheduleBlockBuilder;
use App\Services\Scheduling\ScheduleDraftService;
use App\Services\Scheduling\SchedulePublisher;
use App\Services\Scheduling\SchedulerService;
use App\Services\Scheduling\ScheduleValidator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00')); // Rabu
    config(['sicma.scheduler.run_mode' => 'manual']);

    $this->period = Period::factory()->create();
    $this->period->timeSlots()->create(['label' => 'I', 'start_minute' => 450, 'end_minute' => 600]);
    $this->classGroup = ClassGroup::factory()->create(['name' => 'Tekom C']);
    $this->keti = User::factory()->approvedKeti($this->classGroup)->create();
    $this->admin = User::factory()->superAdmin()->create();
    $this->ae107 = Room::factory()->create(['name' => 'AE 107']);
    $this->ae106 = Room::factory()->create(['name' => 'AE 106']);

    $this->official = ScheduleVersion::factory()->official()->for($this->period)->create(['version_no' => 2, 'effective_from' => '2026-09-01']);
    $this->session = ScheduleSession::factory()->for($this->official, 'version')->create([
        'title' => 'Pemrograman Web', 'room_id' => $this->ae107->id, 'day_of_week' => 3, 'start_minute' => 600, 'end_minute' => 750,
    ]);
    $this->session->participants()->sync([$this->classGroup->id]);
    app(ScheduleBlockBuilder::class)->rebuild($this->official);
});

afterEach(function () {
    File::deleteDirectory(storage_path('app/private/scheduler'));
});

test('skenario 3: draf yang diubah tidak tampil sebelum ditetapkan, lalu tampil setelahnya', function () {
    $draft = app(ScheduleDraftService::class)->create($this->period, ScheduleSource::Manual, $this->official);
    $draft->sessions()->sole()->update(['room_id' => $this->ae106->id]);

    $this->actingAs($this->keti)->get(route('keti.my-class', ['tanggal' => '2026-11-11']))->assertSee('AE 107')->assertDontSee('AE 106');
    auth()->logout();
    $this->get(route('public.rooms', ['q' => 'AE 106']))->assertSee('Tersedia');

    app(SchedulePublisher::class)->publish($draft, $this->admin, CarbonImmutable::parse('2026-11-09'), $draft->lock_version);

    $this->actingAs($this->keti)->get(route('keti.my-class', ['tanggal' => '2026-11-11']))->assertSee('AE 106')->assertDontSee('AE 107');
    $this->actingAs($this->keti)->get(route('keti.my-class', ['tanggal' => '2026-11-04']))->assertSee('AE 107');
});

test('skenario 4: hasil susun otomatis yang menempatkan pimpinan di hari Kamis tidak bisa ditetapkan', function () {
    $leader = Lecturer::factory()->create();
    LeadershipTerm::factory()->for($leader)->create();
    $this->session->lecturers()->sync([$leader->id => ['role' => 'lead']]);
    $this->period->timeSlots()->create(['label' => 'II', 'start_minute' => 600, 'end_minute' => 750]);

    $scheduler = app(SchedulerService::class);
    $job = $scheduler->finalize($scheduler->start($this->period, ['type' => 'semester'], 15, $this->admin), [
        'status' => 'FEASIBLE',
        'assignments' => [['id' => $this->session->id, 'day' => 4, 'start_minute' => 600, 'end_minute' => 750, 'room_id' => $this->ae107->id]],
    ]);
    expect($job->solver_status)->toBe(SolverStatus::ModelInvalid)
        ->and($job->result)->not->toHaveKey('draft_version_id');
    $draft = app(ScheduleDraftService::class)->create($this->period, ScheduleSource::Manual, $this->official);
    $draft->sessions()->sole()->update(['day_of_week' => 4]);
    app(ScheduleValidator::class)->validate($draft);

    expect($draft->status)->toBe(ScheduleVersionStatus::Draft)
        ->and($draft->issues()->blocking()->where('code', 'LEADERSHIP_THURSDAY')->exists())->toBeTrue()
        ->and(fn () => app(SchedulePublisher::class)->publish($draft, $this->admin, now(), $draft->lock_version))->toThrow(DomainException::class)
        ->and($this->official->fresh()->status)->toBe(ScheduleVersionStatus::Official);
});

test('skenario 14: status ruang sama di portal publik, Portal Keti, dan dashboard admin', function () {
    // Pukul 10.00 Rabu: AE 107 dipakai kuliah resmi, AE 106 kosong.
    $this->get(route('public.rooms', ['q' => 'AE 107']))->assertSee('Digunakan')->assertDontSee('>Tersedia<', false);
    $this->get(route('public.rooms', ['q' => 'AE 106']))->assertSee('Tersedia');

    $this->actingAs($this->keti)->get(route('keti.rooms.show', $this->ae107))->assertSee('Digunakan');
    $this->actingAs($this->keti)->get(route('keti.rooms.show', $this->ae106))->assertSee('Tersedia');

    $this->actingAs($this->admin)->get(route('admin.dashboard'))
        ->assertSeeInOrder(['AE 106', 'Tersedia', 'AE 107', 'Pemrograman Web', 'Digunakan']);
});
