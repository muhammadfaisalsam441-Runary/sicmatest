<?php

use App\Enums\LecturerHomebase;
use App\Enums\LecturerProfileStatus;
use App\Enums\RescheduleStatus;
use App\Enums\ReservationStatus;
use App\Enums\RoomBlockSource;
use App\Enums\ScheduleSource;
use App\Enums\ScheduleVersionStatus;
use App\Enums\SessionMode;
use App\Enums\SessionPhase;
use App\Enums\TeachingRole;
use App\Models\Academic\ClassGroup;
use App\Models\Academic\Course;
use App\Models\Academic\CourseOffering;
use App\Models\Academic\Period;
use App\Models\Academic\SessionReschedule;
use App\Models\Lecturer\LeadershipTerm;
use App\Models\Lecturer\Lecturer;
use App\Models\Lecturer\LecturerLoadTarget;
use App\Models\Lecturer\TeachingAssignment;
use App\Models\Reservation\Reservation;
use App\Models\Room\Room;
use App\Models\Room\RoomBlock;
use App\Models\Scheduling\Import;
use App\Models\Scheduling\ScheduleSession;
use App\Models\Scheduling\ScheduleVersion;
use App\Models\User\AuditLog;
use App\Models\User\User;
use App\Services\Academic\ClassScheduleService;
use App\Services\Academic\LecturerService;
use App\Services\Academic\TeachingLoadService;
use App\Services\Academic\TeachingPhasePlanner;
use App\Services\Scheduling\ScheduleBlockBuilder;
use App\Services\Scheduling\ScheduleDraftService;
use App\Services\Scheduling\SchedulePublisher;
use App\Services\Scheduling\SchedulerService;
use App\Services\Scheduling\ScheduleValidator;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00'));
    $this->period = Period::factory()->create();
    $this->admin = User::factory()->admin()->create();
});

test('halaman penyusunan jadwal tampil untuk admin', function (string $routeName, array $parameters, string $text) {
    $this->actingAs($this->admin)->get(route($routeName, $parameters))->assertOk()->assertSee($text);
})->with([
    ['admin.schedules.index', [], 'Jadwal perkuliahan'],
    ['admin.academic.index', [], 'Periode akademik'],
    ['admin.academic.index', ['tab' => 'kelas'], 'Belum diisi'],
    ['admin.academic.index', ['tab' => 'mata-kuliah'], 'Kode MK'],
    ['admin.academic.index', ['tab' => 'penawaran'], 'Belum ada penawaran perkuliahan.'],
    ['admin.academic.index', ['tab' => 'aturan'], 'Hari & jam kuliah (slot)'],
    ['admin.lecturers.index', [], 'Data dosen dari jadwal'],
    ['admin.teaching.index', [], 'Pembagian mengajar dan beban SKS'],
]);

test('draf bisa ditinjau per kelas, dosen, dan ruang', function (string $view) {
    $draft = ScheduleVersion::factory()->for($this->period)->create(['status' => ScheduleVersionStatus::Draft]);
    $session = ScheduleSession::factory()->for($draft, 'version')->create(['title' => 'Pemrograman Web', 'room_id' => Room::factory()->create(['name' => 'AE 107'])->id]);
    $session->participants()->sync([ClassGroup::factory()->create(['name' => 'Tekom C'])->id]);
    $session->lecturers()->sync([Lecturer::factory()->create(['name' => 'Rina Kartika'])->id => ['role' => 'lead']]);

    $this->actingAs($this->admin)
        ->get(route('admin.drafts.show', [$draft, 'tinjau' => $view]))
        ->assertOk()
        ->assertSee(['Pemrograman Web', match ($view) {
            'kelas' => 'Tekom C', 'dosen' => 'Rina Kartika', 'ruang' => 'AE 107'
        }]);
})->with(['kelas', 'dosen', 'ruang']);

test('pemeriksaan dan penetapan terpisah dari editor dan GET tidak memeriksa otomatis', function () {
    $draft = ScheduleVersion::factory()->for($this->period)->create();
    ScheduleSession::factory()->for($draft, 'version')->create(['title' => 'Pemrograman Web']);
    $before = AuditLog::query()->count();

    $this->actingAs($this->admin)->get(route('admin.drafts.inspections'))
        ->assertOk()->assertSee('Buka Pemeriksaan');
    $this->get(route('admin.drafts.inspect', $draft))->assertOk()
        ->assertSee('Belum diperiksa')->assertDontSee('Lanjut ke Penetapan');
    $this->get(route('admin.drafts.show', $draft))->assertOk()
        ->assertSee('Buka Pemeriksaan')->assertDontSee('publish-draft');
    $this->get(route('admin.schedules.index', ['tab' => 'penetapan', 'draf' => $draft->id]))
        ->assertOk()->assertSee('Belum diperiksa')->assertDontSee('name="effective_from"', false);

    expect(AuditLog::query()->count())->toBe($before);
});

test('pemeriksaan mencatat revisi dan perubahan kunci menandai hasil lama kedaluwarsa', function () {
    $draft = ScheduleVersion::factory()->for($this->period)->create()->refresh();
    $session = ScheduleSession::factory()->for($draft, 'version')->create(['room_id' => Room::factory()->create()->id]);
    $session->participants()->attach(ClassGroup::factory()->create(['student_count' => 20])->id);
    $session->lecturers()->attach(Lecturer::factory()->create()->id, ['role' => 'lead']);

    $this->actingAs($this->admin)->post(route('admin.drafts.validate', $draft))
        ->assertRedirect(route('admin.drafts.inspect', $draft));
    $this->get(route('admin.drafts.inspect', $draft))->assertOk()->assertSee('Lanjut ke Penetapan');
    $this->get(route('admin.schedules.index', ['tab' => 'penetapan', 'draf' => $draft->id]))
        ->assertOk()->assertSee('Akun ini tidak memiliki izin menetapkan jadwal.')->assertDontSee('name="effective_from"', false);
    $this->actingAs(User::factory()->superAdmin()->create());
    $this->get(route('admin.schedules.index', ['tab' => 'penetapan', 'draf' => $draft->id]))
        ->assertOk()->assertSee('Tinjau Penetapan')->assertSee('name="effective_from"', false);

    $this->post(route('admin.drafts.sessions.lock', [$draft, $session]));
    $this->get(route('admin.drafts.inspect', $draft))->assertOk()->assertSee('Perlu diperiksa ulang')->assertDontSee('Lanjut ke Penetapan');
    expect(AuditLog::query()->where('action', 'schedule.checked')->count())->toBe(1);
});

test('masalah wajib menghalangi lanjut dan daftar temuan dapat difilter', function () {
    $draft = ScheduleVersion::factory()->for($this->period)->create();
    ScheduleSession::factory()->unscheduled()->for($draft, 'version')->create(['title' => 'Belum Ditempatkan']);
    $this->actingAs($this->admin)->post(route('admin.drafts.validate', $draft));
    $draft->issues()->create(['code' => 'PROFILE_TEST', 'severity' => 'warning', 'session_ids' => [], 'message' => 'Profil perlu dilengkapi']);

    $this->get(route('admin.drafts.inspect', [$draft, 'tingkat' => 'error', 'jenis' => 'SESSION_WITHOUT_TIME']))
        ->assertOk()->assertSee('Perlu diperbaiki')->assertSee('belum punya hari dan jam')
        ->assertDontSee('Profil perlu dilengkapi')->assertDontSee('Lanjut ke Penetapan');
});

test('peringatan tanpa masalah wajib tetap tersedia pada pemeriksaan dan penetapan', function () {
    $draft = ScheduleVersion::factory()->for($this->period)->create();
    $session = ScheduleSession::factory()->for($draft, 'version')->create(['room_id' => Room::factory()->create()->id]);
    $session->participants()->attach(ClassGroup::factory()->create(['student_count' => 20])->id);
    $session->lecturers()->attach(Lecturer::factory()->unverified()->create()->id, ['role' => 'lead']);
    app(ScheduleValidator::class)->validate($draft);

    $this->actingAs($this->admin)->get(route('admin.drafts.inspect', $draft))
        ->assertOk()->assertSee('Siap dengan peringatan')->assertSee('Lanjut ke Penetapan');
    $this->actingAs(User::factory()->superAdmin()->create());
    $this->get(route('admin.schedules.index', ['tab' => 'penetapan', 'draf' => $draft->id]))
        ->assertOk()->assertSee('Peringatan perlu ditinjau')->assertSee('Tinjau Penetapan');
});

test('jadwal resmi membedakan versi yang berlaku hari ini dan versi mendatang', function () {
    $old = ScheduleVersion::factory()->for($this->period)->create(['status' => ScheduleVersionStatus::Superseded, 'version_no' => 1, 'effective_from' => '2026-09-01', 'effective_to' => '2026-11-08']);
    $next = ScheduleVersion::factory()->official()->for($this->period)->create(['version_no' => 2, 'effective_from' => '2026-11-09']);
    ScheduleSession::factory()->for($old, 'version')->create(['title' => 'Sesi Berlaku Sekarang']);
    ScheduleSession::factory()->for($next, 'version')->create(['title' => 'Sesi Mulai Mendatang']);

    $this->actingAs($this->admin)->get(route('admin.schedules.index'))->assertOk()
        ->assertViewHas('active', fn ($version) => $version->id === $old->id)
        ->assertViewHas('official', fn ($version) => $version->id === $old->id)
        ->assertSee('Sudah ditetapkan; belum berlaku hari ini.')->assertSee('Sesi Berlaku Sekarang')->assertDontSee('Sesi Mulai Mendatang');
    $this->get(route('admin.schedules.index', ['versi' => $next->id]))->assertOk()->assertSee('Sesi Mulai Mendatang');
});

test('jadwal resmi dan ekspor Excel mengikuti filter ruang serta versi yang dipilih', function () {
    $official = ScheduleVersion::factory()->official()->for($this->period)->create();
    $room = Room::factory()->create();
    ScheduleSession::factory()->for($official, 'version')->create(['room_id' => $room->id, 'title' => 'Sesi Terpilih']);
    ScheduleSession::factory()->for($official, 'version')->create(['room_id' => Room::factory()->create()->id, 'title' => 'Sesi Lain']);

    $this->actingAs($this->admin)->get(route('admin.schedules.index', ['ruang' => $room->id]))
        ->assertOk()->assertSee('Sesi Terpilih')->assertDontSee('Sesi Lain');
    $download = $this->get(route('admin.drafts.excel', [$official, 'ruang' => $room->id]))->assertOk();
    $file = tmpfile();
    try {
        fwrite($file, $download->streamedContent());
        $sheet = IOFactory::createReader('Xlsx')->load(stream_get_meta_data($file)['uri'])->getActiveSheet();
        expect($sheet->getCell('D5')->getValue())->toBe('Sesi Terpilih')->and($sheet->getHighestDataRow())->toBe(5);
    } finally {
        fclose($file);
    }
});

test('jadwal resmi dapat difilter berdasarkan angkatan', function () {
    $official = ScheduleVersion::factory()->official()->for($this->period)->create();
    $class2026 = ClassGroup::factory()->create(['cohort_year' => 2026, 'name' => 'PTIK A 2026']);
    $class2024 = ClassGroup::factory()->create(['cohort_year' => 2024, 'name' => 'PTIK A 2024']);

    $session2026 = ScheduleSession::factory()->for($official, 'version')->create(['title' => 'Algoritma 2026']);
    $session2026->participants()->attach($class2026);

    $session2024 = ScheduleSession::factory()->for($official, 'version')->create(['title' => 'Basis Data 2024']);
    $session2024->participants()->attach($class2024);

    $this->actingAs($this->admin)
        ->get(route('admin.schedules.index', ['angkatan' => 2026]))
        ->assertOk()
        ->assertSee('Algoritma 2026')
        ->assertDontSee('Basis Data 2024')
        ->assertSee('PTIK A 2026')
        ->assertDontSee('PTIK A 2024');

    $this->get(route('admin.schedules.index', ['angkatan' => 2024]))
        ->assertOk()
        ->assertSee('Basis Data 2024')
        ->assertDontSee('Algoritma 2026');
});

test('jadwal resmi dapat difilter berdasarkan prodi', function () {
    $official = ScheduleVersion::factory()->official()->for($this->period)->create();
    $classPtik = ClassGroup::factory()->create(['study_program' => 'Pendidikan Teknik Informatika dan Komputer', 'name' => 'PTIK A 2026']);
    $classTekom = ClassGroup::factory()->create(['study_program' => 'Teknik Komputer', 'name' => 'TEKOM A 2026']);

    $sessionPtik = ScheduleSession::factory()->for($official, 'version')->create(['title' => 'Pemrograman Web PTIK']);
    $sessionPtik->participants()->attach($classPtik);

    $sessionTekom = ScheduleSession::factory()->for($official, 'version')->create(['title' => 'Mikrokontroler TEKOM']);
    $sessionTekom->participants()->attach($classTekom);

    $this->actingAs($this->admin)
        ->get(route('admin.schedules.index', ['prodi' => 'Teknik Komputer']))
        ->assertOk()
        ->assertSee('Mikrokontroler TEKOM')
        ->assertDontSee('Pemrograman Web PTIK')
        ->assertSee('TEKOM A 2026')
        ->assertDontSee('PTIK A 2026');

    $this->get(route('admin.schedules.index', ['prodi' => 'Pendidikan Teknik Informatika dan Komputer']))
        ->assertOk()
        ->assertSee('Pemrograman Web PTIK')
        ->assertDontSee('Mikrokontroler TEKOM');
});

test('filter jadwal resmi langsung menerapkan perubahan tanpa tombol Terapkan', function () {
    ScheduleVersion::factory()->official()->for($this->period)->create();

    $this->actingAs($this->admin)
        ->get(route('admin.schedules.index'))
        ->assertOk()
        ->assertDontSee('>Terapkan<', false)
        ->assertDontSee('>Reset<', false)
        ->assertSee('@input.debounce.400ms="$el.form.requestSubmit()"', false)
        ->assertSee('@change="$el.form.requestSubmit()"', false);

    $this->get(route('admin.schedules.index', ['q' => 'Algoritma']))
        ->assertOk()
        ->assertSee('Reset');
});

test('jadwal resmi default diurutkan per blok angkatan terbaru ke terlama lalu abjad kelas dengan dua warna soft bergantian per kelas', function () {
    $official = ScheduleVersion::factory()->official()->for($this->period)->create();
    $class2026B = ClassGroup::factory()->create(['cohort_year' => 2026, 'name' => 'PTIK B 2026']);
    $class2026A = ClassGroup::factory()->create(['cohort_year' => 2026, 'name' => 'PTIK A 2026']);
    $class2025 = ClassGroup::factory()->create(['cohort_year' => 2025, 'name' => 'PTIK A 2025']);
    $class2024 = ClassGroup::factory()->create(['cohort_year' => 2024, 'name' => 'PTIK A 2024']);

    $s2024 = ScheduleSession::factory()->for($official, 'version')->create(['title' => 'MK 2024 Pagi', 'day_of_week' => 1, 'start_minute' => 450, 'end_minute' => 550]);
    $s2024->participants()->attach($class2024);

    $s2026B = ScheduleSession::factory()->for($official, 'version')->create(['title' => 'MK 2026 B Pagi', 'day_of_week' => 1, 'start_minute' => 450, 'end_minute' => 550]);
    $s2026B->participants()->attach($class2026B);

    $s2026ALate = ScheduleSession::factory()->for($official, 'version')->create(['title' => 'MK 2026 A Siang', 'day_of_week' => 2, 'start_minute' => 600, 'end_minute' => 700]);
    $s2026ALate->participants()->attach($class2026A);

    $s2025 = ScheduleSession::factory()->for($official, 'version')->create(['title' => 'MK 2025', 'day_of_week' => 1, 'start_minute' => 480, 'end_minute' => 580]);
    $s2025->participants()->attach($class2025);

    $s2026AEarly = ScheduleSession::factory()->for($official, 'version')->create(['title' => 'MK 2026 A Pagi', 'day_of_week' => 1, 'start_minute' => 500, 'end_minute' => 600]);
    $s2026AEarly->participants()->attach($class2026A);

    $this->actingAs($this->admin)
        ->get(route('admin.schedules.index'))
        ->assertOk()
        ->assertSeeInOrder(['MK 2026 A Pagi', 'MK 2026 A Siang', 'MK 2026 B Pagi', 'MK 2025', 'MK 2024 Pagi'])
        ->assertViewHas('cohortBlockAlternate', fn (array $map): bool => $map[$s2026AEarly->id] === false
            && $map[$s2026ALate->id] === false
            && $map[$s2026B->id] === true
            && $map[$s2025->id] === false
            && $map[$s2024->id] === true)
        ->assertSee('bg-primary-soft/45', false)
        ->assertSee('bg-surface hover:bg-page/70', false);
});

test('Keti dan tamu tidak dapat mengakses pemeriksaan atau penetapan jadwal', function () {
    $draft = ScheduleVersion::factory()->for($this->period)->create();
    $this->actingAs(User::factory()->approvedKeti()->create())
        ->get(route('admin.drafts.inspections'))->assertForbidden();
    $this->get(route('admin.drafts.inspect', $draft))->assertForbidden();
    $this->get(route('admin.schedules.index', ['tab' => 'penetapan', 'draf' => $draft->id]))->assertForbidden();
    auth()->logout();
    $this->get(route('admin.drafts.inspections'))->assertRedirect(route('login'));
});

test('admin mengunduh Excel untuk versi dan pengelompokan jadwal yang dibuka', function () {
    $draft = ScheduleVersion::factory()->for($this->period)->create(['version_no' => 6]);
    $session = ScheduleSession::factory()->for($draft, 'version')->create([
        'title' => '=1+1',
        'room_id' => Room::factory()->create(['name' => 'AE 107'])->id,
    ]);
    $session->participants()->attach(ClassGroup::factory()->create(['name' => 'TEKOM A'])->id);
    ScheduleSession::factory()->for($draft, 'version')->unscheduled()->create(['title' => 'Perancangan VLSI']);

    $download = $this->actingAs($this->admin)->get(route('admin.drafts.excel', [$draft, 'tinjau' => 'ruang']))
        ->assertOk()->assertDownload('sicma-ganjil-2026-2027-v6-per-ruang.xlsx');

    $file = tmpfile();
    try {
        fwrite($file, $download->streamedContent());
        $book = IOFactory::createReader('Xlsx')->load(stream_get_meta_data($file)['uri']);
        $sheet = $book->getActiveSheet();
        expect($sheet->getCell('A4')->getValue())->toBe('Kelompok Ruang')
            ->and($sheet->getCell('A5')->getValue())->toBe('AE 107')
            ->and($sheet->getCell('D5')->getValue())->toBe('=1+1')
            ->and($sheet->getCell('D5')->getDataType())->toBe(DataType::TYPE_STRING)
            ->and($sheet->getCell('A6')->getValue())->toBe('Belum terjadwal')
            ->and($sheet->getCell('D6')->getValue())->toBe('Perancangan VLSI')
            ->and($sheet->getAutoFilter()->getRange())->toBe('A4:H6');
    } finally {
        fclose($file);
    }
});

test('admin mengunduh file PDF dari draf yang dibuka', function () {
    $draft = ScheduleVersion::factory()->for($this->period)->create(['version_no' => 6]);
    ScheduleSession::factory()->for($draft, 'version')->create([
        'title' => 'Perancangan VLSI',
        'room_id' => Room::factory()->create(['name' => 'AE 107'])->id,
    ]);
    $download = $this->actingAs($this->admin)->get(route('admin.drafts.pdf', [$draft, 'tinjau' => 'ruang']))
        ->assertOk()->assertHeader('Content-Type', 'application/pdf')
        ->assertDownload('sicma-ganjil-2026-2027-v6-per-ruang.pdf');

    expect($download->streamedContent())->toStartWith('%PDF-');
});

test('Keti tidak bisa mengunduh versi jadwal lewat rute admin', function (string $routeName) {
    $draft = ScheduleVersion::factory()->for($this->period)->create();

    $this->actingAs(User::factory()->approvedKeti()->create())
        ->get(route($routeName, $draft))->assertForbidden();
})->with(['admin.drafts.excel', 'admin.drafts.pdf']);

test('mengubah penempatan sesi draf memeriksa ulang dan tidak mengubah jadwal resmi', function () {
    $official = ScheduleVersion::factory()->official()->for($this->period)->create(['version_no' => 1]);
    $officialSession = ScheduleSession::factory()->for($official, 'version')->create(['day_of_week' => 1]);
    $this->actingAs($this->admin)->post(route('admin.drafts.store'));
    $draft = ScheduleVersion::query()->where('status', ScheduleVersionStatus::Draft)->sole();
    $session = $draft->sessions()->sole();
    $before = $draft->lock_version;

    $this->put(route('admin.drafts.sessions.update', [$draft, $session]), [
        'mode' => 'offline', 'day_of_week' => 2, 'start_time' => '13:00', 'end_time' => '14:40', 'room_id' => $session->room_id,
    ])->assertRedirect();

    expect($session->fresh()->only(['day_of_week', 'start_minute', 'end_minute']))->toBe(['day_of_week' => 2, 'start_minute' => 780, 'end_minute' => 880])
        ->and($officialSession->fresh()->day_of_week)->toBe(1)
        ->and($draft->fresh()->lock_version)->toBe($before + 1)
        ->and(AuditLog::query()->where('action', 'schedule.session_updated')->exists())->toBeTrue();
});

test('sesi terkunci tidak bisa diubah dan sesi belum terjadwal menghapus jam', function () {
    $draft = ScheduleVersion::factory()->for($this->period)->create(['status' => ScheduleVersionStatus::Draft]);
    $session = ScheduleSession::factory()->for($draft, 'version')->create();

    $this->actingAs($this->admin)->put(route('admin.drafts.sessions.update', [$draft, $session]), ['mode' => 'unscheduled']);

    expect($session->fresh())->mode->toBe(SessionMode::Unscheduled)->start_minute->toBeNull()->room_id->toBeNull();

    $this->post(route('admin.drafts.sessions.lock', [$draft, $session]));
    $this->from(route('admin.drafts.show', $draft))
        ->put(route('admin.drafts.sessions.update', [$draft, $session]), ['mode' => 'online', 'day_of_week' => 1, 'start_time' => '07:30', 'end_time' => '10:00'])
        ->assertSessionHas('error_code', 'SESSION_LOCKED');
});

test('sesi dari versi lain tidak bisa diubah lewat URL draf', function () {
    $draft = ScheduleVersion::factory()->for($this->period)->create(['status' => ScheduleVersionStatus::Draft]);
    $foreign = ScheduleSession::factory()->create();

    $this->actingAs($this->admin)->put(route('admin.drafts.sessions.update', [$draft, $foreign]), ['mode' => 'unscheduled'])->assertNotFound();
});

test('jadwal resmi tidak bisa diubah langsung', function () {
    $official = ScheduleVersion::factory()->official()->for($this->period)->create();
    $session = ScheduleSession::factory()->for($official, 'version')->create();

    $this->actingAs($this->admin)
        ->from(route('admin.schedules.index'))
        ->put(route('admin.drafts.sessions.update', [$official, $session]), ['mode' => 'unscheduled'])
        ->assertSessionHas('error_code', 'INVALID_STATUS_TRANSITION');
});

test('form admin yang dikirim tanpa navigasi mengembalikan error JSON dan menjaga data lama', function () {
    $classGroup = ClassGroup::factory()->create(['name' => 'Tekom C']);

    $this->actingAs($this->admin)
        ->putJson(route('admin.academic.classes.update', $classGroup), [
            'name' => '', 'code' => $classGroup->code, 'study_program' => 'TEKOM', 'cohort_year' => 2025,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('name');

    expect($classGroup->fresh()->name)->toBe('Tekom C');
});

test('menyimpan form admin lewat fetch tetap mengikuti tujuan tab dari server', function () {
    $classGroup = ClassGroup::factory()->create();

    $this->actingAs($this->admin)
        ->putJson(route('admin.academic.classes.update', $classGroup), [
            'name' => 'Tekom C diperbarui', 'code' => $classGroup->code, 'study_program' => 'TEKOM', 'cohort_year' => 2025,
        ])
        ->assertRedirect(route('admin.academic.index', ['tab' => 'kelas']));

    expect($classGroup->fresh()->name)->toBe('Tekom C diperbarui');
});

test('admin dapat melakukan aksi massal ubah jumlah mahasiswa, angkatan, prodi, dan status kelas', function () {
    $c1 = ClassGroup::factory()->create(['name' => 'PTIK A 2026', 'code' => 'PTIK-A-2026', 'study_program' => 'PTIK', 'cohort_year' => 2026, 'student_count' => null]);
    $c2 = ClassGroup::factory()->create(['name' => 'PTIK B 2026', 'code' => 'PTIK-B-2026', 'study_program' => 'PTIK', 'cohort_year' => 2026, 'student_count' => null]);
    $c3 = ClassGroup::factory()->create(['name' => 'TEKOM A 2026', 'code' => 'TEKOM-A-2026', 'study_program' => 'TEKOM', 'cohort_year' => 2026, 'student_count' => null]);

    // 1. Set Jumlah Mahasiswa massal (termasuk jika browser mengirim field lain dari form tersembunyi)
    $this->actingAs($this->admin)->post(route('admin.academic.classes.batch'), [
        'action' => 'set_student_count',
        'class_ids' => [$c1->id, $c2->id],
        'student_count' => 40,
        'cohort_year' => 40,
        'study_program' => 40,
    ])->assertRedirect(route('admin.academic.index', ['tab' => 'kelas']))
        ->assertSessionHasNoErrors();

    expect($c1->fresh()->student_count)->toBe(40)
        ->and($c2->fresh()->student_count)->toBe(40)
        ->and($c3->fresh()->student_count)->toBeNull();

    // 2. Set Angkatan massal menyinkronkan tahun di nama & kode tanpa menduplikasi tahun
    $this->post(route('admin.academic.classes.batch'), [
        'action' => 'set_cohort_year',
        'class_ids' => [$c1->id, $c2->id],
        'cohort_year' => 2027,
        'student_count' => 2027,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($c1->fresh())
        ->cohort_year->toBe(2027)
        ->name->toBe('PTIK A 2027')
        ->code->toBe('PTIK-A-2027')
        ->and($c2->fresh())
        ->cohort_year->toBe(2027)
        ->name->toBe('PTIK B 2027')
        ->code->toBe('PTIK-B-2027');

    // 3. Set Program Studi massal
    $this->post(route('admin.academic.classes.batch'), [
        'action' => 'set_study_program',
        'class_ids' => [$c1->id, $c2->id],
        'study_program' => 'Pendidikan Teknik Informatika',
        'student_count' => 'Pendidikan Teknik Informatika',
        'cohort_year' => 'Pendidikan Teknik Informatika',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($c1->fresh()->study_program)->toBe('Pendidikan Teknik Informatika')
        ->and($c2->fresh()->study_program)->toBe('Pendidikan Teknik Informatika');

    // 4. Arsipkan & Aktifkan massal
    $this->post(route('admin.academic.classes.batch'), [
        'action' => 'archive',
        'class_ids' => [$c1->id, $c2->id],
    ])->assertRedirect();

    expect($c1->fresh()->is_active)->toBeFalse()
        ->and($c2->fresh()->is_active)->toBeFalse();

    $this->post(route('admin.academic.classes.batch'), [
        'action' => 'activate',
        'class_ids' => [$c1->id],
    ])->assertRedirect();

    expect($c1->fresh()->is_active)->toBeTrue()
        ->and($c2->fresh()->is_active)->toBeFalse();
});

test('admin dapat memperbarui banyak kelas sekaligus melalui mode edit massal di tabel', function () {
    $c1 = ClassGroup::factory()->create(['name' => 'PTIK A 2026', 'code' => 'PTIK-A-2026', 'study_program' => 'PTIK', 'cohort_year' => 2026, 'student_count' => null]);
    $c2 = ClassGroup::factory()->create(['name' => 'PTIK B', 'code' => 'PTIK-B-2026', 'study_program' => 'PTIK', 'cohort_year' => 2026, 'student_count' => null]);

    $this->actingAs($this->admin)->put(route('admin.academic.classes.bulk-update'), [
        'classes' => [
            [
                'id' => $c1->id,
                'name' => 'PTIK A 2026',
                'study_program' => 'PTIK',
                'cohort_year' => 2026,
                'student_count' => 38,
                'is_active' => 1,
            ],
            [
                'id' => $c2->id,
                'name' => 'PTIK B Reguler',
                'study_program' => 'PTIK',
                'cohort_year' => 2026,
                'student_count' => 42,
                'is_active' => 1,
            ],
        ],
    ])->assertRedirect(route('admin.academic.index', ['tab' => 'kelas']))
        ->assertSessionHasNoErrors();

    expect($c1->fresh())
        ->name->toBe('PTIK A 2026')
        ->code->toBe('PTIK-A-2026')
        ->student_count->toBe(38);

    expect($c2->fresh())
        ->name->toBe('PTIK B Reguler')
        ->code->toBe('PTIK-B-REGULER-2026')
        ->student_count->toBe(42);
});

test('admin mengelola kelas, mata kuliah, penawaran, dan hari libur', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.academic.classes.store'), ['name' => 'PTIK C', 'study_program' => 'PTIK', 'cohort_year' => 2026, 'student_count' => ''])
        ->assertRedirect(route('admin.academic.index', ['tab' => 'kelas']));
    $classGroup = ClassGroup::query()->where('name', 'PTIK C')->sole();

    $this->post(route('admin.academic.courses.store'), ['code' => 'TKM9999', 'name' => 'Komunikasi Data', 'sks' => 2]);
    $course = Course::query()->where('code', 'TKM9999')->sole();

    $this->post(route('admin.academic.offerings.store'), ['period_id' => $this->period->id, 'course_id' => $course->id, 'class_group_id' => $classGroup->id]);
    $this->post(route('admin.academic.offerings.store'), ['period_id' => $this->period->id, 'course_id' => $course->id, 'class_group_id' => $classGroup->id])
        ->assertSessionHasErrors(['class_group_id' => 'Mata kuliah ini sudah ditawarkan untuk kelas tersebut.']);

    $this->post(route('admin.academic.exceptions.store', $this->period), ['date' => '2026-12-28', 'until' => '2026-12-30', 'type' => 'holiday', 'note' => 'Libur akhir tahun']);

    expect($classGroup->student_count)->toBeNull()
        ->and($classGroup->code)->toBe('PTIK-C-2026')
        ->and(CourseOffering::query()->count())->toBe(1)
        ->and($this->period->calendarExceptions()->count())->toBe(3);

    $this->delete(route('admin.academic.courses.destroy', $course))->assertSessionHas('error');
    expect($course->fresh())->not->toBeNull();
});

test('admin mengubah slot jam dan kalender semester', function () {
    $slot = $this->period->timeSlots()->create(['label' => 'X', 'start_minute' => 940, 'end_minute' => 990]);

    $this->actingAs($this->admin)
        ->put(route('admin.academic.slots.update', $this->period), ['slots' => [$slot->id => ['start' => '16:35', 'end' => '15:45']]])
        ->assertSessionHasErrors("slots.{$slot->id}.end");

    $this->actingAs($this->admin)->put(route('admin.academic.slots.update', $this->period), ['slots' => [$slot->id => ['start' => '15:45', 'end' => '16:35']]]);
    $this->put(route('admin.academic.periods.update', $this->period), ['name' => 'Ganjil 2026/2027', 'start_date' => '2026-09-01', 'end_date' => '2027-01-15', 'uts_start' => '2026-10-26', 'uts_end' => '2026-10-24'])
        ->assertSessionHasErrors('uts_end');

    expect($slot->fresh()->only(['start_minute', 'end_minute']))->toBe(['start_minute' => 945, 'end_minute' => 995]);
});

test('admin menambah dosen pimpinan dengan target beban kosong', function () {
    $this->actingAs($this->admin)->post(route('admin.lecturers.store'), [
        'name' => 'Dr. Hendra Saputra',
        'profile_status' => 'verified',
        'homebase' => 'jtik',
        'leadership_position' => 'Ketua Jurusan',
        'leadership_from' => '2025-01-01',
        'target_sks' => 16,
        'max_sks' => 12,
    ])->assertSessionHasErrors('max_sks');

    $this->actingAs($this->admin)->post(route('admin.lecturers.store'), [
        'name' => 'Dr. Hendra Saputra',
        'profile_status' => 'verified',
        'homebase' => 'jtik',
        'leadership_position' => 'Ketua Jurusan',
        'leadership_from' => '2025-01-01',
    ])->assertRedirect(route('admin.lecturers.index'));

    $lecturer = Lecturer::query()->where('name', 'Dr. Hendra Saputra')->sole();

    expect($lecturer->isLeaderOn(now()))->toBeTrue()
        ->and($lecturer->loadTargets()->value('target_sks'))->toBeNull();

    $this->get(route('admin.lecturers.index', ['filter' => 'pimpinan']))->assertSee('Dr. Hendra Saputra')->assertSee('tidak dijadwalkan Kamis');

    $this->put(route('admin.lecturers.update', $lecturer), [
        'name' => 'Dr. Hendra Saputra',
        'profile_status' => 'verified',
        'homebase' => 'jtik',
        'leadership_position' => '',
    ])->assertRedirect(route('admin.lecturers.index', ['filter' => 'pimpinan']));

    expect($lecturer->fresh()->isLeaderOn(now()))->toBeFalse();
});

test('impor dosen dari CSV memperbarui nama yang ada dan menandai yang tanpa NIDN', function () {
    Lecturer::factory()->unverified()->create(['name' => 'Bpk. Arman']);
    $csv = "Nama,NIDN,Homebase\nBpk. Arman,0912345678,JTIK\nIbu Baru,,Luar JTIK\n";

    $this->actingAs($this->admin)->post(route('admin.lecturers.import'), ['file' => UploadedFile::fake()->createWithContent('dosen.csv', $csv)])->assertSessionHas('toast');

    expect(Lecturer::query()->where('nidn', '0912345678')->value('profile_status'))->toBe(LecturerProfileStatus::Verified)
        ->and(Lecturer::query()->where('name', 'Ibu Baru')->value('profile_status'))->toBe(LecturerProfileStatus::Unverified);
});

test('admin dapat melakukan aksi massal verifikasi, set homebase, dan target sks dosen', function () {
    $dosen1 = Lecturer::factory()->unverified()->create(['homebase' => null]);
    $dosen2 = Lecturer::factory()->unverified()->create(['homebase' => null]);

    $this->actingAs($this->admin)->post(route('admin.lecturers.batch'), [
        'action' => 'verify',
        'lecturer_ids' => [$dosen1->id, $dosen2->id],
    ])->assertRedirect();

    expect($dosen1->fresh()->profile_status)->toBe(LecturerProfileStatus::Verified)
        ->and($dosen2->fresh()->profile_status)->toBe(LecturerProfileStatus::Verified);

    $this->post(route('admin.lecturers.batch'), [
        'action' => 'homebase_jtik',
        'lecturer_ids' => [$dosen1->id, $dosen2->id],
    ])->assertRedirect();

    expect($dosen1->fresh()->homebase)->toBe(LecturerHomebase::Jtik)
        ->and($dosen2->fresh()->homebase)->toBe(LecturerHomebase::Jtik);

    $this->post(route('admin.lecturers.batch'), [
        'action' => 'set_target_sks',
        'lecturer_ids' => [$dosen1->id, $dosen2->id],
        'target_sks' => 12,
        'max_sks' => 16,
    ])->assertRedirect();

    expect((float) $dosen1->loadTargets()->where('period_id', $this->period->id)->value('target_sks'))->toBe(12.0)
        ->and((float) $dosen2->loadTargets()->where('period_id', $this->period->id)->value('max_sks'))->toBe(16.0);
});

test('admin dapat mengedit beberapa pilihan mata kuliah dosen tanpa mengubah penugasan kelas', function () {
    $lecturer = Lecturer::factory()->create();
    $first = CourseOffering::factory()->for($this->period)->create();
    $second = CourseOffering::factory()->for($this->period)->create();
    $existing = CourseOffering::factory()->for($this->period)->create();
    $assignment = TeachingAssignment::factory()->for($existing, 'courseOffering')->for($lecturer)->create();
    $parallel = CourseOffering::factory()->for($this->period)->for($existing->course)->create();
    TeachingAssignment::factory()->for($parallel, 'courseOffering')->for($lecturer)->create();
    $profile = ['name' => $lecturer->name, 'nidn' => $lecturer->nidn, 'homebase' => 'jtik', 'profile_status' => 'verified'];
    $update = route('admin.lecturers.update', $lecturer);

    $dataDosen = route('admin.lecturers.index');
    $initial = $this->actingAs($this->admin)->get($dataDosen)->assertOk()->assertSee('Mata kuliah yang bisa diampu');
    expect($initial->viewData('courseSelections')->get($lecturer->id))->toBe(['source' => [$existing->course_id], 'manual' => [], 'count' => 1]);

    $this->put($update, [...$profile, 'course_selection_present' => '1', 'course_ids' => [$first->course_id, $second->course_id, $existing->course_id]])
        ->assertSessionHasNoErrors();
    $this->put($update, $profile)->assertSessionHasNoErrors();

    $updated = $this->get($dataDosen)->assertOk()->assertSee('3 MK')->viewData('courseSelections')->get($lecturer->id);
    expect($updated['count'])->toBe(3)
        ->and($updated['manual'])->toEqualCanonicalizing([$first->course_id, $second->course_id, $existing->course_id]);

    $options = $this->get(route('admin.teaching.index'))->assertOk()->viewData('lecturerOptionsByOffering');
    foreach ([$first, $second] as $offering) {
        expect(array_column($options->get($offering->id), 'id'))->toBe([$lecturer->id]);
    }

    $this->put($update, [...$profile, 'course_selection_present' => '1'])->assertSessionHasNoErrors();
    expect($this->get($dataDosen)->assertOk()->viewData('courseSelections')->get($lecturer->id)['count'])->toBe(1);
    $options = $this->get(route('admin.teaching.index'))->assertOk()->viewData('lecturerOptionsByOffering');

    expect($options->get($first->id))->toBe([])
        ->and($options->get($second->id))->toBe([])
        ->and($assignment->fresh())->not->toBeNull();
});

test('dropdown penugasan menampilkan pengajar aktif dari mata kuliah dan semester yang sama', function () {
    $offering = CourseOffering::factory()->for($this->period)->create();
    $parallel = CourseOffering::factory()->for($this->period)->for($offering->course)->create();
    $otherCourse = CourseOffering::factory()->for($this->period)->create();
    $past = Period::factory()->create(['is_active' => false]);
    $pastOffering = CourseOffering::factory()->for($past)->for($offering->course)->create();
    $sourceLecturer = Lecturer::factory()->unverified()->create();
    $inactive = Lecturer::factory()->create(['is_active' => false]);

    TeachingAssignment::factory()->for($parallel, 'courseOffering')->for($sourceLecturer)->create();
    TeachingAssignment::factory()->for($parallel, 'courseOffering')->for($inactive)->create();
    TeachingAssignment::factory()->for($otherCourse, 'courseOffering')->create();
    TeachingAssignment::factory()->for($pastOffering, 'courseOffering')->create();

    $response = $this->actingAs($this->admin)->get(route('admin.teaching.index'))->assertOk();
    $options = collect($response->viewData('lecturerOptionsByOffering')->get($offering->id));

    expect($options->pluck('id')->all())->toBe([$sourceLecturer->id])
        ->and($options->sole()['verified'])->toBeFalse();
});

test('dropdown menyertakan dosen dari daftar impor semester yang sama walau penugasannya memakai kode lain', function () {
    $course = Course::factory()->create(['name' => 'Pemrograman Web']);
    $offering = CourseOffering::factory()->for($this->period)->for($course)->create();
    $parallelCourse = Course::factory()->create(['name' => 'Pemrograman Web']);
    $parallel = CourseOffering::factory()->for($this->period)->for($parallelCourse)->create();
    $assigned = TeachingAssignment::factory()->for($offering, 'courseOffering')->create()->lecturer;
    $past = Period::factory()->create(['is_active' => false]);
    foreach ([[$this->period, 'Ganjil.xlsx'], [$past, 'Genap.xlsx']] as [$period, $file]) {
        Import::create([
            'period_id' => $period->id,
            'original_name' => $file,
            'file_path' => 'imports/'.$file,
            'file_hash' => hash('sha256', $file),
            'status' => 'committed',
            'created_by' => $this->admin->id,
        ]);
    }
    $roster = fn (string $file, string $courses): array => ['rosters' => [$file => ['raw' => ['MATA KULIAH YANG DIAMPU (SESUAI JADWAL)' => $courses]]]];
    $rosterLecturer = Lecturer::factory()->unverified()->create(['source_data' => $roster('Ganjil.xlsx', "- Basis Data\r\n• PEMROGRAMAN   WEB")]);
    TeachingAssignment::factory()->for($parallel, 'courseOffering')->for($rosterLecturer)->create();
    Lecturer::factory()->create(['source_data' => $roster('Genap.xlsx', '- Pemrograman Web')]);
    Lecturer::factory()->create(['source_data' => $roster('Ganjil.xlsx', '- Pemrograman Web Lanjut')]);

    $response = $this->actingAs($this->admin)->get(route('admin.teaching.index'))->assertOk();
    $options = collect($response->viewData('lecturerOptionsByOffering')->get($offering->id));

    expect($options->pluck('id')->all())->toEqualCanonicalizing([$assigned->id, $rosterLecturer->id]);

    $rosterSelection = $this->get(route('admin.lecturers.index'))->assertOk()->viewData('courseSelections')->get($rosterLecturer->id);
    expect($rosterSelection['source'])->toEqualCanonicalizing([$offering->course_id, $parallel->course_id]);
});

test('pembagian 14 pertemuan menyimpan fase dan mengakui SKS penuh untuk setiap pertemuan', function (int $sks, int $expectedLoad) {
    $offering = CourseOffering::factory()->for($this->period)->create();
    $offering->course->update(['sks' => $sks]);
    $lead = TeachingAssignment::factory()->for($offering, 'courseOffering')->create(['credited_sks' => 1.5]);
    $partner = TeachingAssignment::factory()->for($offering, 'courseOffering')->create(['role' => 'partner', 'credited_sks' => 1.5]);
    $preview = route('admin.teaching.phases.preview');

    $plan = $this->actingAs($this->admin)->get($preview)->assertOk()
        ->assertSee('UTS pada pertemuan ke-7')->assertSee('UAS pada pertemuan ke-14')
        ->assertSee("{$sks} SKS × 7 = {$expectedLoad} SKS")->viewData('plan');
    expect($plan['rows'])->toHaveCount(1)
        ->and($plan['rows'][0]['sks'])->toBe($sks)
        ->and($lead->fresh()->phase->value)->toBe('full');

    $this->from($preview)->post(route('admin.teaching.phases.apply'), ['fingerprint' => $plan['fingerprint']])
        ->assertRedirect(route('admin.teaching.index'))->assertSessionHasNoErrors();

    expect($lead->fresh()->phase->value)->toBe('before_uts')
        ->and($partner->fresh()->phase->value)->toBe('after_uts')
        ->and($offering->course->fresh()->sks)->toBe($sks)
        ->and($lead->fresh()->credited_sks)->toBe('1.50')
        ->and($partner->fresh()->credited_sks)->toBe('1.50');

    $response = $this->get(route('admin.teaching.index'))->assertOk()
        ->assertSee(['SKS Semester', 'Bagi Beban Otomatis', "{$sks} SKS • UTS", "{$sks} SKS • UAS"])
        ->assertDontSee('1.5 SKS');
    $loads = $response->viewData('loads');
    expect($loads->pluck('meeting_sks')->all())->toBe([$expectedLoad, $expectedLoad])
        ->and($loads->pluck('assigned')->all())->toBe([(float) $sks, (float) $sks])
        ->and($loads->pluck('pending')->all())->toBe([0, 0])
        ->and($loads->pluck('pending_phases')->all())->toBe([0, 0]);

    $rechecked = $this->get($preview)->assertOk()
        ->assertSee(['Hitung Ulang', 'Sudah sesuai', "{$sks} SKS × 7 = {$expectedLoad} SKS"])
        ->assertDontSee('Simpan Pembagian Pertemuan')
        ->viewData('plan');
    expect($rechecked['already_set'])->toBe(1)
        ->and($rechecked['rows'])->toBeEmpty();
})->with([[2, 14], [3, 21], [4, 28]]);

test('pembagian pertemuan melewati tim tanpa pengampu dan menolak usulan yang berubah setelah ditinjau', function () {
    $offering = CourseOffering::factory()->for($this->period)->create();
    $lead = TeachingAssignment::factory()->for($offering, 'courseOffering')->create();
    $partner = TeachingAssignment::factory()->for($offering, 'courseOffering')->create(['role' => 'partner']);
    $incomplete = CourseOffering::factory()->for($this->period)->create();
    TeachingAssignment::factory()->for($incomplete, 'courseOffering')->create(['role' => 'partner']);
    $preview = route('admin.teaching.phases.preview');

    $plan = $this->actingAs($this->admin)->get($preview)->assertOk()->viewData('plan');
    expect($plan['rows'])->toHaveCount(1)
        ->and(collect($plan['skipped'])->pluck('reason')->all())->toBe(['Tetapkan satu pengampu dan maksimal dua mitra dengan dosen yang berbeda.']);

    $lead->update(['is_locked' => true]);
    $this->from($preview)->post(route('admin.teaching.phases.apply'), ['fingerprint' => $plan['fingerprint']])
        ->assertRedirect($preview)->assertSessionHasErrors('fingerprint');

    expect($lead->fresh()->phase->value)->toBe('full')
        ->and($partner->fresh()->phase->value)->toBe('full');
    expect(collect($this->get($preview)->assertOk()->viewData('plan')['skipped'])->pluck('reason'))
        ->toContain('Fase yang perlu diubah berada pada penugasan terkunci.');
});

test('pembagian ulang menangani pengampu tunggal dan tim dengan dua mitra', function () {
    $soloOffering = CourseOffering::factory()->for($this->period)->create();
    $soloOffering->course->update(['sks' => 3]);
    $solo = TeachingAssignment::factory()->for($soloOffering, 'courseOffering')->create(['phase' => 'before_uts']);
    $teamOffering = CourseOffering::factory()->for($this->period)->create();
    $teamOffering->course->update(['sks' => 2]);
    $lead = TeachingAssignment::factory()->for($teamOffering, 'courseOffering')->create();
    $partners = TeachingAssignment::factory()->count(2)->for($teamOffering, 'courseOffering')->create(['role' => 'partner']);
    $preview = route('admin.teaching.phases.preview');

    $plan = $this->actingAs($this->admin)->get($preview)->assertOk()
        ->assertSee(['3 SKS × 14 = 42 SKS', '2 SKS × 7 = 14 SKS'])
        ->viewData('plan');
    expect($plan['rows'])->toHaveCount(2)->and($plan['skipped'])->toBeEmpty();

    $this->from($preview)->post(route('admin.teaching.phases.apply'), ['fingerprint' => $plan['fingerprint']])
        ->assertRedirect(route('admin.teaching.index'))->assertSessionHasNoErrors();

    expect($solo->fresh()->phase->value)->toBe('full')
        ->and($lead->fresh()->phase->value)->toBe('before_uts')
        ->and($partners->fresh()->pluck('phase.value')->all())->toBe(['after_uts', 'after_uts']);

    $loads = app(TeachingLoadService::class)->summary($this->period)->keyBy('lecturer.id');
    expect($loads[$solo->lecturer_id]['assigned'])->toBe(3.0)
        ->and($loads[$solo->lecturer_id]['meeting_sks'])->toBe(42)
        ->and($loads[$lead->lecturer_id]['meeting_sks'])->toBe(14);
    foreach ($partners as $partner) {
        expect($loads[$partner->lecturer_id]['assigned'])->toBe(2.0)
            ->and($loads[$partner->lecturer_id]['meeting_sks'])->toBe(14);
    }
});

test('penugasan tunggal dan massal mengambil SKS dari mata kuliah pada setiap kelas', function () {
    $offering = CourseOffering::factory()->for($this->period)->create();
    $offering->course->update(['sks' => 3]);
    $parallel = CourseOffering::factory()->for($this->period)->for($offering->course)->create();
    $lecturer = Lecturer::factory()->create();
    $data = ['lecturer_id' => $lecturer->id, 'role' => 'lead', 'phase' => 'full', 'credited_sks' => '1.5'];

    $this->actingAs($this->admin)->post(route('admin.teaching.store'), [...$data, 'course_offering_id' => $offering->id])
        ->assertSessionHasNoErrors();
    $this->post(route('admin.teaching.batch'), [...$data, 'offering_ids' => [$parallel->id]])
        ->assertSessionHasNoErrors();

    $summary = app(TeachingLoadService::class)->summary($this->period)->sole();
    expect($summary['assigned'])->toBe(6.0)
        ->and($lecturer->teachingAssignments()->pluck('credited_sks')->all())->toBe([null, null]);
});

test('rekap semester memberi bobot mata kuliah penuh kepada setiap dosen sekali per kelas', function () {
    $offering = CourseOffering::factory()->for($this->period)->create();
    $offering->course->update(['sks' => 3]);
    $lead = Lecturer::factory()->create(['name' => 'Rina Kartika']);
    $partner = Lecturer::factory()->create(['name' => 'Sinta Amelia']);

    $this->actingAs($this->admin)->post(route('admin.teaching.store'), ['course_offering_id' => $offering->id, 'lecturer_id' => $lead->id, 'role' => 'lead', 'phase' => 'full']);
    $loads = app(TeachingLoadService::class);
    expect($loads->summary($this->period)->first()['assigned'])->toBe(3.0);

    $this->post(route('admin.teaching.store'), ['course_offering_id' => $offering->id, 'lecturer_id' => $partner->id, 'role' => 'partner', 'phase' => 'full']);
    TeachingAssignment::factory()->for($offering, 'courseOffering')->for($partner)->create([
        'role' => 'partner',
        'phase' => 'after_uts',
    ]);
    $summary = $loads->summary($this->period);

    expect($summary->pluck('assigned')->all())->toBe([3.0, 3.0])
        ->and($summary->pluck('pending')->all())->toBe([0, 0]);

    $assignment = TeachingAssignment::query()->where('lecturer_id', $lead->id)->sole();
    $this->post(route('admin.teaching.lock', $assignment));
    $this->delete(route('admin.teaching.destroy', $assignment))->assertSessionHas('error');
    expect($assignment->fresh())->not->toBeNull();
});

test('rekap beban pertemuan mengikuti penyimpanan dan penghapusan penugasan manual', function () {
    $offering = CourseOffering::factory()->for($this->period)->create();
    $offering->course->update(['sks' => 3]);
    $lead = Lecturer::factory()->create();
    $partner = Lecturer::factory()->create();
    $store = route('admin.teaching.store');
    $index = route('admin.teaching.index');

    $this->actingAs($this->admin)->post($store, [
        'course_offering_id' => $offering->id,
        'lecturer_id' => $lead->id,
        'role' => 'lead',
        'phase' => 'before_uts',
    ])->assertSessionHasNoErrors();

    $load = $this->get($index)->assertOk()->viewData('loads')->sole();
    expect($load['meeting_sks'])->toBe(0)
        ->and($load['assigned'])->toBe(3.0)
        ->and($load['pending_phases'])->toBe(1);

    $this->post($store, [
        'course_offering_id' => $offering->id,
        'lecturer_id' => $partner->id,
        'role' => 'partner',
        'phase' => 'after_uts',
    ])->assertSessionHasNoErrors();

    $loads = $this->get($index)->assertOk()->assertSee('SKS × Pertemuan')->viewData('loads');
    expect($loads->pluck('meeting_sks')->all())->toBe([21, 21])
        ->and($loads->pluck('assigned')->all())->toBe([3.0, 3.0])
        ->and($loads->pluck('pending_phases')->all())->toBe([0, 0]);

    $assignment = $offering->teachingAssignments()->whereBelongsTo($partner, 'lecturer')->sole();
    $this->delete(route('admin.teaching.destroy', $assignment))->assertSessionHasNoErrors();

    $load = $this->get($index)->assertOk()->viewData('loads')->sole();
    expect($load['meeting_sks'])->toBe(0)
        ->and($load['assigned'])->toBe(3.0)
        ->and($load['pending_phases'])->toBe(1);
});

test('admin dapat mengubah penugasan mengajar yang sudah ada dan mengganti pengampu secara massal tanpa duplikasi', function () {
    $offering = CourseOffering::factory()->for($this->period)->create();
    $dosenLama = Lecturer::factory()->create(['name' => 'Dr. Dosen Lama']);
    $dosenBaru = Lecturer::factory()->create(['name' => 'Dr. Dosen Baru']);
    $dosenMitra = Lecturer::factory()->create(['name' => 'Ir. Dosen Mitra']);

    $leadAssignment = TeachingAssignment::factory()->for($offering, 'courseOffering')->for($dosenLama)->create([
        'role' => 'lead',
        'phase' => 'full',
    ]);

    // Ubah dosen pengampu dan fase melalui route update.
    $this->actingAs($this->admin)->put(route('admin.teaching.update', $leadAssignment), [
        'lecturer_id' => $dosenBaru->id,
        'role' => 'lead',
        'phase' => 'before_uts',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($leadAssignment->fresh())
        ->lecturer_id->toBe($dosenBaru->id)
        ->phase->value->toBe('before_uts');

    // Penugasan massal peran lead menggantikan pengampu yang tidak terkunci tanpa membuat baris lead ganda
    $this->post(route('admin.teaching.batch'), [
        'offering_ids' => [$offering->id],
        'lecturer_id' => $dosenLama->id,
        'role' => 'lead',
        'phase' => 'full',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($offering->teachingAssignments()->where('role', 'lead')->count())->toBe(1)
        ->and($leadAssignment->fresh()->lecturer_id)->toBe($dosenLama->id);

    // Jika dikunci, tidak bisa diubah
    $leadAssignment->update(['is_locked' => true]);
    $this->put(route('admin.teaching.update', $leadAssignment), [
        'lecturer_id' => $dosenBaru->id,
        'role' => 'lead',
        'phase' => 'full',
    ])->assertSessionHas('error');

    expect($leadAssignment->fresh()->lecturer_id)->toBe($dosenLama->id);

    // Pencarian berdasarkan nama dosen dan filter tunggal / tim_mitra
    $this->get(route('admin.teaching.index', ['q' => 'Dosen Lama', 'filter' => 'tunggal']))
        ->assertOk()
        ->assertSee($offering->course->name);

    TeachingAssignment::factory()->for($offering, 'courseOffering')->for($dosenMitra)->create([
        'role' => 'partner',
        'phase' => 'after_uts',
    ]);

    $this->get(route('admin.teaching.index', ['filter' => 'tim_mitra']))
        ->assertOk()
        ->assertSee($offering->course->name);

    // Filter berdasarkan semester
    $this->get(route('admin.teaching.index', ['semester' => '1']))
        ->assertOk();
});

test('admin mengelola penawaran kuliah dengan filter dan batch delete', function () {
    $course1 = Course::factory()->create(['name' => 'Pemrograman Web Lanjut', 'sks' => 3]);
    $course2 = Course::factory()->create(['name' => 'Kecerdasan Buatan', 'sks' => 3]);
    $classGroup = ClassGroup::factory()->create(['name' => 'PTIK A 2024', 'study_program' => 'PTIK', 'cohort_year' => 2024]);

    $offering1 = CourseOffering::factory()->for($this->period)->for($course1)->for($classGroup)->create();
    $offering2 = CourseOffering::factory()->for($this->period)->for($course2)->for($classGroup)->create();

    // Halaman penawaran dapat diakses dan menampilkan data
    $this->actingAs($this->admin)
        ->get(route('admin.academic.index', ['tab' => 'penawaran']))
        ->assertOk()
        ->assertSee('Pemrograman Web Lanjut')
        ->assertSee('Kecerdasan Buatan')
        ->assertSee('PTIK A 2024');

    // Batch delete penawaran yang belum punya sesi
    $this->post(route('admin.academic.offerings.batch-delete'), [
        'offering_ids' => [$offering1->id, $offering2->id],
    ])->assertRedirect(route('admin.academic.index', ['tab' => 'penawaran']));

    expect(CourseOffering::query()->whereIn('id', [$offering1->id, $offering2->id])->count())->toBe(0);
});

test('perubahan penugasan mengajar di TeachingAssignmentController disinkronkan ke draf aktif', function () {
    $course = Course::factory()->create(['name' => 'Jaringan Komputer', 'sks' => 3]);
    $classGroup = ClassGroup::factory()->create(['name' => 'PTIK B 2024', 'study_program' => 'PTIK']);
    $offering = CourseOffering::factory()->for($this->period)->for($course)->for($classGroup)->create();

    $lecturerA = Lecturer::factory()->create(['name' => 'Dosen A']);
    $lecturerB = Lecturer::factory()->create(['name' => 'Dosen B']);

    // Buat draf jadwal aktif
    $draft = ScheduleVersion::factory()->for($this->period)->create();
    $session = ScheduleSession::factory()->for($draft, 'version')->create([
        'course_offering_id' => $offering->id,
        'phase' => SessionPhase::Full,
    ]);

    // 1. Store assignment (Lead)
    $this->actingAs($this->admin)->post(route('admin.teaching.store'), [
        'course_offering_id' => $offering->id,
        'lecturer_id' => $lecturerA->id,
        'role' => 'lead',
        'phase' => 'before_uts',
    ])->assertRedirect();

    $session->refresh();
    expect($session->phase)->toBe(SessionPhase::BeforeUts)
        ->and($session->lecturers->pluck('id')->all())->toBe([$lecturerA->id]);

    // 2. Batch store assignment (tambah Partner)
    $this->post(route('admin.teaching.batch'), [
        'offering_ids' => [$offering->id],
        'lecturer_id' => $lecturerB->id,
        'role' => 'partner',
        'phase' => 'before_uts',
    ])->assertRedirect();

    $session->refresh();
    expect($session->lecturers->pluck('id')->sort()->values()->all())
        ->toBe(collect([$lecturerA->id, $lecturerB->id])->sort()->values()->all());

    // 3. Update assignment
    $leadAssignment = TeachingAssignment::query()->where('course_offering_id', $offering->id)->where('role', TeachingRole::Lead)->first();
    $this->put(route('admin.teaching.update', $leadAssignment), [
        'lecturer_id' => $lecturerA->id,
        'role' => 'lead',
        'phase' => 'full',
    ])->assertRedirect();

    $session->refresh();
    // Mixed phase (full & before_uts) -> session becomes Full and both are attached
    expect($session->phase)->toBe(SessionPhase::Full)
        ->and($session->lecturers->pluck('id')->count())->toBe(2);

    // 4. Destroy assignment
    $this->delete(route('admin.teaching.destroy', $leadAssignment))->assertRedirect();

    $session->refresh();
    expect($session->lecturers->pluck('id')->all())->toBe([$lecturerB->id]);
});

test('penerapan fase di TeachingPhasePlanner apply disinkronkan ke draf aktif', function () {
    $course = Course::factory()->create(['sks' => 2]);
    $classGroup = ClassGroup::factory()->create(['study_program' => 'TEKOM']);
    $offering = CourseOffering::factory()->for($this->period)->for($course)->for($classGroup)->create();

    $lead = Lecturer::factory()->create();
    $partner = Lecturer::factory()->create();

    TeachingAssignment::factory()->for($offering, 'courseOffering')->for($lead)->create(['role' => 'lead', 'phase' => 'full']);
    TeachingAssignment::factory()->for($offering, 'courseOffering')->for($partner)->create(['role' => 'partner', 'phase' => 'full']);

    // Draf aktif
    $draft = ScheduleVersion::factory()->for($this->period)->create();
    $session = ScheduleSession::factory()->for($draft, 'version')->create([
        'course_offering_id' => $offering->id,
        'phase' => SessionPhase::Full,
    ]);

    $planner = app(TeachingPhasePlanner::class);
    $plan = $planner->preview($this->period);

    $this->actingAs($this->admin)->post(route('admin.teaching.phases.apply'), [
        'fingerprint' => $plan['fingerprint'],
    ])->assertRedirect();

    $session->refresh();
    expect($session->lecturers->pluck('id')->count())->toBe(2);
});

test('data dosen mendukung sortir, ekspor csv, dan edit massal inline', function () {
    $alpha = Lecturer::factory()->create([
        'name' => 'Dr. Alpha',
        'homebase' => LecturerHomebase::Jtik,
        'profile_status' => LecturerProfileStatus::Verified,
    ]);
    $beta = Lecturer::factory()->create([
        'name' => 'Dr. Beta',
        'homebase' => LecturerHomebase::External,
        'profile_status' => LecturerProfileStatus::Unverified,
    ]);

    LecturerLoadTarget::query()->create(['lecturer_id' => $alpha->id, 'period_id' => $this->period->id, 'target_sks' => 6, 'max_sks' => 12]);
    LecturerLoadTarget::query()->create(['lecturer_id' => $beta->id, 'period_id' => $this->period->id, 'target_sks' => 12, 'max_sks' => 16]);

    $this->actingAs($this->admin)
        ->get(route('admin.lecturers.index', ['sort' => 'target_sks', 'dir' => 'desc']))
        ->assertOk()
        ->assertSeeInOrder(['Dr. Beta', 'Dr. Alpha']);

    $export = $this->actingAs($this->admin)
        ->get(route('admin.lecturers.export', ['q' => 'Alpha']))
        ->assertOk();

    $csv = $export->streamedContent();
    expect($csv)->toContain('Dr. Alpha')
        ->not->toContain('Dr. Beta');

    $this->actingAs($this->admin)
        ->put(route('admin.lecturers.bulk-update'), [
            'lecturers' => [
                [
                    'id' => $alpha->id,
                    'name' => 'Prof. Alpha',
                    'nip' => '198001012005011001',
                    'nidn' => '0001018001',
                    'homebase' => LecturerHomebase::Jtik->value,
                    'profile_status' => LecturerProfileStatus::Verified->value,
                    'target_sks' => 10,
                    'max_sks' => 16,
                ],
            ],
        ])
        ->assertRedirect();

    $alpha->refresh();
    $target = LecturerLoadTarget::query()->where('lecturer_id', $alpha->id)->where('period_id', $this->period->id)->sole();
    expect($alpha->name)->toBe('Prof. Alpha')
        ->and($alpha->nip)->toBe('198001012005011001')
        ->and($target->target_sks)->toBe(10)
        ->and($target->max_sks)->toBe(16)
        ->and(AuditLog::query()->where('action', 'lecturer.bulk_updated')->exists())->toBeTrue();

    $this->actingAs($this->admin)
        ->put(route('admin.lecturers.bulk-update'), [
            'lecturers' => [
                [
                    'id' => $alpha->id,
                    'name' => 'Prof. Alpha',
                    'profile_status' => LecturerProfileStatus::Verified->value,
                    'target_sks' => 16,
                    'max_sks' => 12,
                ],
            ],
        ])
        ->assertSessionHasErrors('lecturers.0.max_sks');
});

test('pembagian mengajar mendukung sortir kolom dan ekspor csv sesuai filter', function () {
    $algo = Course::factory()->create(['code' => 'MK-01', 'name' => 'Algoritma Lanjut', 'sks' => 4]);
    $db = Course::factory()->create(['code' => 'MK-02', 'name' => 'Basis Data Dasar', 'sks' => 2]);
    $classA = ClassGroup::factory()->create(['name' => 'PTIK A', 'study_program' => 'PTIK']);

    CourseOffering::factory()->for($this->period)->for($algo)->for($classA)->create();
    CourseOffering::factory()->for($this->period)->for($db)->for($classA)->create();

    $this->actingAs($this->admin)
        ->get(route('admin.teaching.index', ['sort' => 'sks', 'dir' => 'desc']))
        ->assertOk()
        ->assertSeeInOrder(['Algoritma Lanjut', 'Basis Data Dasar']);

    $export = $this->actingAs($this->admin)
        ->get(route('admin.teaching.export', ['q' => 'Algoritma']))
        ->assertOk();

    $csv = $export->streamedContent();
    expect($csv)->toContain('Algoritma Lanjut')
        ->not->toContain('Basis Data Dasar');
});

test('pembagian mengajar default diurutkan per blok angkatan terbaru lalu abjad kelas dengan dua warna soft bergantian per kelas', function () {
    $mkA = Course::factory()->create(['code' => 'MK-101', 'name' => 'Algoritma', 'sks' => 3]);
    $mkB = Course::factory()->create(['code' => 'MK-102', 'name' => 'Basis Data', 'sks' => 3]);
    $class2026B = ClassGroup::factory()->create(['cohort_year' => 2026, 'name' => 'PTIK B 2026']);
    $class2026A = ClassGroup::factory()->create(['cohort_year' => 2026, 'name' => 'PTIK A 2026']);
    $class2025 = ClassGroup::factory()->create(['cohort_year' => 2025, 'name' => 'PTIK A 2025']);

    $off2025 = CourseOffering::factory()->for($this->period)->for($mkA)->for($class2025)->create();
    $off2026B = CourseOffering::factory()->for($this->period)->for($mkA)->for($class2026B)->create();
    $off2026AB = CourseOffering::factory()->for($this->period)->for($mkB)->for($class2026A)->create();
    $off2026AA = CourseOffering::factory()->for($this->period)->for($mkA)->for($class2026A)->create();

    $this->actingAs($this->admin)
        ->get(route('admin.teaching.index'))
        ->assertOk()
        ->assertSeeInOrder(['PTIK A 2026', 'Algoritma', 'PTIK A 2026', 'Basis Data', 'PTIK B 2026', 'PTIK A 2025'])
        ->assertViewHas('classBlockAlternate', fn (array $map): bool => $map[$off2026AA->id] === false
            && $map[$off2026AB->id] === false
            && $map[$off2026B->id] === true
            && $map[$off2025->id] === false)
        ->assertSee('bg-primary-soft/45', false)
        ->assertSee('bg-surface hover:bg-page/70', false);
});

test('tabel admin menggunakan wadah scroll dengan header lengket dan tombol perluas layar penuh', function () {
    $official = ScheduleVersion::factory()->official()->for($this->period)->create();
    ScheduleSession::factory()->for($official, 'version')->create();
    $draft = ScheduleVersion::factory()->for($this->period)->create(['status' => ScheduleVersionStatus::Draft]);
    ScheduleSession::factory()->for($draft, 'version')->create();

    foreach ([
        route('admin.dashboard'),
        route('admin.schedules.index'),
        route('admin.teaching.index'),
        route('admin.lecturers.index'),
        route('admin.rooms.index'),
        route('admin.academic.index', ['tab' => 'kelas']),
        route('admin.academic.index', ['tab' => 'mata-kuliah']),
        route('admin.academic.index', ['tab' => 'penawaran']),
        route('admin.drafts.show', $draft),
        route('admin.drafts.inspect', $draft),
    ] as $url) {
        $this->actingAs($this->admin)
            ->get($url)
            ->assertOk()
            ->assertSee('x-data="tableScrollBox"', false)
            ->assertSee('onTableScroll($event)', false)
            ->assertSee('sticky top-0 z-10', false)
            ->assertSee('Perluas')
            ->assertDontSee('Scroll di dalam tabel untuk melihat semua baris');
    }
});

test('halaman jadwal resmi menggunakan tab ringkas, laci filter kolapsibel, dan chip filter aktif', function () {
    $room = Room::factory()->create(['name' => 'AE 107']);
    $official = ScheduleVersion::factory()->official()->for($this->period)->create();
    ScheduleSession::factory()->for($official, 'version')->create(['room_id' => $room->id]);

    $this->actingAs($this->admin)
        ->get(route('admin.schedules.index'))
        ->assertOk()
        ->assertSee('px-3.5 py-1.5 text-xs', false)
        ->assertSee('x-data="filterDrawer(\'schedules-filters\', false)"', false)
        ->assertSee('data-table-search', false)
        ->assertSee('aria-label="Filter hari kuliah"', false)
        ->assertSee('x-data="tableDensityToggle()"', false)
        ->assertSee('1 sesi • Jadwal perkuliahan resmi v')
        ->assertDontSee('Scroll di dalam tabel untuk melihat semua baris');

    $this->actingAs($this->admin)
        ->get(route('admin.schedules.index', ['ruang' => $room->id]))
        ->assertOk()
        ->assertSee('x-data="filterDrawer(\'schedules-filters\', true)"', false)
        ->assertSee('Filter (1)')
        ->assertSee('Aktif:')
        ->assertSee('Ruang: AE 107');
});

test('halaman pemeriksaan draf menampilkan strip statistik ringkas dan pengalih bagian temuan-tinjauan di DOM', function () {
    $draft = ScheduleVersion::factory()->for($this->period)->create(['status' => ScheduleVersionStatus::Draft]);
    ScheduleSession::factory()->for($draft, 'version')->create(['title' => 'Pemrograman Web']);

    $this->actingAs($this->admin)
        ->get(route('admin.drafts.inspect', $draft))
        ->assertOk()
        ->assertSee('px-3.5 py-2', false)
        ->assertSee('Total sesi')
        ->assertSee('Terjadwal')
        ->assertSee('Belum lengkap')
        ->assertSee('Masalah wajib')
        ->assertSee('Peringatan')
        ->assertSee('Terkunci')
        ->assertSee('role="tablist"', false)
        ->assertSee('section = \'temuan\'', false)
        ->assertSee('section = \'tinjauan\'', false)
        ->assertSee('x-show="section === \'temuan\'"', false)
        ->assertSee('x-show="section === \'tinjauan\'"', false)
        ->assertSee('Temuan Pemeriksaan')
        ->assertSee('Tinjauan Jadwal')
        ->assertDontSee('Scroll di dalam tabel untuk melihat semua baris');
});

test('tab penawaran akademik menggunakan filter cepat berjumlah dan laci filter lanjutan', function () {
    $course = Course::factory()->create(['name' => 'Basis Data']);
    $classGroup = ClassGroup::factory()->create(['name' => 'PTIK B']);
    CourseOffering::factory()->for($this->period)->for($course)->for($classGroup)->create();

    $this->actingAs($this->admin)
        ->get(route('admin.academic.index', ['tab' => 'penawaran']))
        ->assertOk()
        ->assertSee('px-3.5 py-1.5 text-xs', false)
        ->assertSee('x-data="filterDrawer(\'offerings-filters\', false)"', false)
        ->assertSee('Filter lanjutan')
        ->assertSee('id="offering-search"', false)
        ->assertSee('data-table-search', false)
        ->assertSee('Belum Ada Dosen')
        ->assertSee('Sudah Ada Dosen')
        ->assertSee('Sudah Terjadwal')
        ->assertSee('Belum Terjadwal')
        ->assertDontSee('Scroll di dalam tabel untuk melihat semua baris');
});

test('halaman operasional reservasi, log pemakaian, dan akun keti mempertahankan judul, ringkasan, dan fitur tabel ringkas', function () {
    $room = Room::factory()->create(['name' => 'Lab AI 1']);
    $classGroup = ClassGroup::factory()->create(['name' => 'Tekom A']);
    $keti = User::factory()->approvedKeti($classGroup)->create(['name' => 'Budi Santoso']);

    Reservation::factory()->for($room)->for($classGroup)->for($keti, 'creator')->create([
        'purpose' => 'Praktikum AI',
        'status' => ReservationStatus::InUse,
        'start_at' => '2026-11-04 09:00',
        'end_at' => '2026-11-04 11:00',
        'actual_start_at' => '2026-11-04 09:05',
    ]);
    User::factory()->pendingKeti($classGroup)->create();

    $this->actingAs($this->admin)
        ->get(route('admin.reservations.index'))
        ->assertOk()
        ->assertSee('Riwayat reservasi')
        ->assertSee('rounded-full px-3 py-1 text-xs', false)
        ->assertSee('x-data="tableScrollBox"', false)
        ->assertSee('x-data="tableDensityToggle()"', false)
        ->assertSee('Perluas')
        ->assertDontSee('Scroll di dalam tabel untuk melihat semua baris');

    $this->actingAs($this->admin)
        ->get(route('admin.usage-logs.index', ['tanggal' => '2026-11-04']))
        ->assertOk()
        ->assertSee('Log pemakaian')
        ->assertSee('x-data="filterDrawer(\'usage-active-rooms\', false)"', false)
        ->assertSee('Ruangan Sedang Digunakan (1)')
        ->assertSee('Detail ruang aktif')
        ->assertSee('Lab AI 1')
        ->assertSee('x-data="tableScrollBox"', false)
        ->assertSee('x-data="tableDensityToggle()"', false)
        ->assertSee('Perluas')
        ->assertDontSee('Scroll di dalam tabel untuk melihat semua baris');

    $this->actingAs($this->admin)
        ->get(route('admin.keti-accounts.index'))
        ->assertOk()
        ->assertSee('Akun Keti')
        ->assertSee('1 pengajuan menunggu pemeriksaan')
        ->assertSee('rounded-full px-3 py-1 text-xs', false)
        ->assertSee('x-data="tableScrollBox"', false)
        ->assertSee('x-data="tableDensityToggle()"', false)
        ->assertSee('Perluas')
        ->assertDontSee('Scroll di dalam tabel untuk melihat semua baris');
});

test('tombol Atur Tim dan Tugaskan pada pembagian SKS tidak merender @js mentah', function () {
    $classGroup = ClassGroup::factory()->create(['name' => 'PTIK A 2026']);
    $courseA = Course::factory()->create(['name' => 'Algoritma', 'sks' => 3]);
    $courseB = Course::factory()->create(['name' => 'Basis Data', 'sks' => 3]);
    $lecturer = Lecturer::factory()->create(['name' => 'Alifya NFH']);

    $assignedOffering = CourseOffering::factory()->for($this->period)->for($courseA)->for($classGroup)->create();
    TeachingAssignment::factory()->for($assignedOffering, 'courseOffering')->for($lecturer)->create(['role' => TeachingRole::Lead]);

    $unassignedOffering = CourseOffering::factory()->for($this->period)->for($courseB)->for($classGroup)->create();

    $this->actingAs($this->admin)
        ->get(route('admin.teaching.index'))
        ->assertOk()
        ->assertDontSee('@js(', false)
        ->assertSee('openEditAssignment(JSON.parse(', false)
        ->assertSee('openQuickAssign('.$unassignedOffering->id.',', false)
        ->assertSee('Atur Tim')
        ->assertSee('Tugaskan');
});

test('jadwal resmi memaginasi 25 sesi per halaman sambil menjaga urutan blok angkatan dan warna selang-seling', function () {
    $official = ScheduleVersion::factory()->official()->for($this->period)->create(['version_no' => 1]);
    $room = Room::factory()->create(['name' => 'AE 107']);
    $class2026 = ClassGroup::factory()->create(['cohort_year' => 2026, 'name' => 'PTIK A 2026']);
    $class2025 = ClassGroup::factory()->create(['cohort_year' => 2025, 'name' => 'PTIK A 2025']);

    $createdSessions = [];
    for ($i = 1; $i <= 30; $i++) {
        $label = sprintf('Sesi Jadwal %02d', $i);
        $session = ScheduleSession::factory()
            ->for($official, 'version')
            ->for($room)
            ->create([
                'title' => $label,
                'day_of_week' => 1,
                'start_minute' => 450 + ($i * 10),
                'end_minute' => 455 + ($i * 10),
            ]);
        $session->participants()->attach($i <= 20 ? $class2026 : $class2025);
        $createdSessions[$i] = $session;
    }

    $page1 = $this->actingAs($this->admin)
        ->get(route('admin.schedules.index'))
        ->assertOk()
        ->assertSee('30 sesi • Jadwal perkuliahan resmi v1')
        ->assertSee('Sesi Jadwal 01')
        ->assertSee('Sesi Jadwal 25')
        ->assertDontSee('Sesi Jadwal 26')
        ->assertViewHas('sessions', fn ($paginator): bool => $paginator->total() === 30
            && $paginator->count() === 25
            && $paginator->perPage() === 25
            && $paginator->currentPage() === 1)
        ->assertViewHas('cohortBlockAlternate', fn (array $map): bool => $map[$createdSessions[1]->id] === false
            && $map[$createdSessions[20]->id] === false
            && $map[$createdSessions[21]->id] === true
            && $map[$createdSessions[25]->id] === true
            && $map[$createdSessions[26]->id] === true);

    $page1->assertSeeInOrder(['Sesi Jadwal 01', 'Sesi Jadwal 20', 'Sesi Jadwal 21', 'Sesi Jadwal 25']);

    $this->actingAs($this->admin)
        ->get(route('admin.schedules.index', ['page' => 2]))
        ->assertOk()
        ->assertSee('30 sesi • Jadwal perkuliahan resmi v1')
        ->assertSee('Sesi Jadwal 26')
        ->assertSee('Sesi Jadwal 30')
        ->assertDontSee('Sesi Jadwal 01')
        ->assertDontSee('Sesi Jadwal 25')
        ->assertViewHas('sessions', fn ($paginator): bool => $paginator->total() === 30
            && $paginator->count() === 5
            && $paginator->perPage() === 25
            && $paginator->currentPage() === 2)
        ->assertSee('bg-primary-soft/45', false);

    $this->actingAs($this->admin)
        ->get(route('admin.schedules.index', ['per_page' => 50]))
        ->assertOk()
        ->assertSee('Sesi Jadwal 01')
        ->assertSee('Sesi Jadwal 30')
        ->assertViewHas('sessions', fn ($paginator): bool => $paginator->total() === 30
            && $paginator->count() === 30
            && $paginator->perPage() === 50);
});

test('penetapan ulang jadwal pada hari yang sama membuat officialVersionOn mengembalikan versi terbaru', function () {
    $publisher = User::factory()->superAdmin()->create();
    $classGroup = ClassGroup::factory()->create(['name' => 'PTIK A 2026']);
    $roomA = Room::factory()->create(['name' => 'AE 101']);
    $roomB = Room::factory()->create(['name' => 'AE 102']);
    $publisherService = app(SchedulePublisher::class);

    $draft1 = app(ScheduleDraftService::class)->create($this->period, ScheduleSource::Manual);
    $session1 = ScheduleSession::factory()->for($draft1, 'version')->for($roomA)->create([
        'title' => 'Algoritma v1',
        'day_of_week' => 3,
        'start_minute' => 480,
        'end_minute' => 580,
    ]);
    $session1->participants()->sync([$classGroup->id]);
    $v1 = $publisherService->publish($draft1, $publisher, now(), $draft1->lock_version);

    $draft2 = app(ScheduleDraftService::class)->create($this->period, ScheduleSource::Manual, $v1);
    $session2 = $draft2->sessions()->sole();
    $session2->update(['title' => 'Algoritma v2', 'room_id' => $roomB->id]);
    $v2 = $publisherService->publish($draft2, $publisher, now(), $draft2->lock_version);

    $schedules = app(ClassScheduleService::class);
    $activeVersion = $schedules->officialVersionOn(now());
    $sessions = $schedules->sessionsFor($classGroup, now());

    expect($v1->fresh()->status)->toBe(ScheduleVersionStatus::Superseded)
        ->and($v1->fresh()->effective_to->toDateString())->toBe('2026-11-04')
        ->and($v2->fresh()->status)->toBe(ScheduleVersionStatus::Official)
        ->and($v2->fresh()->effective_from->toDateString())->toBe('2026-11-04')
        ->and($activeVersion?->id)->toBe($v2->id)
        ->and($sessions->pluck('title')->all())->toBe(['Algoritma v2']);
});

test('penawaran dua sesi per minggu yang satu sesinya dipindah tidak merusak blok sesi kedua saat rebuild', function () {
    $period = Period::factory()->create([
        'start_date' => '2026-11-02',
        'end_date' => '2026-11-29',
        'uts_start' => null,
        'uts_end' => null,
    ]);
    $roomA = Room::factory()->create(['name' => 'AE 201']);
    $roomB = Room::factory()->create(['name' => 'AE 202']);
    $offering = CourseOffering::factory()->for($period)->create();
    $official = ScheduleVersion::factory()->official()->for($period)->create(['effective_from' => '2026-11-02']);

    $sessionMon = ScheduleSession::factory()->for($official, 'version')->for($roomA)->create([
        'course_offering_id' => $offering->id,
        'day_of_week' => 1,
        'start_minute' => 480,
        'end_minute' => 580,
    ]);
    $sessionThu = ScheduleSession::factory()->for($official, 'version')->for($roomB)->create([
        'course_offering_id' => $offering->id,
        'day_of_week' => 4,
        'start_minute' => 600,
        'end_minute' => 700,
    ]);

    $builder = app(ScheduleBlockBuilder::class);
    $builder->rebuild($official);

    $reschedule = SessionReschedule::factory()->create([
        'schedule_session_id' => $sessionThu->id,
        'course_offering_id' => $offering->id,
        'original_start_at' => '2026-11-12 10:00:00',
        'original_end_at' => '2026-11-12 11:40:00',
        'original_room_id' => $roomB->id,
        'status' => RescheduleStatus::Active,
    ]);

    $builder->rebuild($official);

    $mondayBlock = RoomBlock::query()
        ->whereBelongsTo($official, 'scheduleVersion')
        ->where('source_type', RoomBlockSource::Schedule)
        ->where('source_id', $sessionMon->id)
        ->whereDate('start_at', '2026-11-09')
        ->sole();
    $thursdayBlock = RoomBlock::query()
        ->whereBelongsTo($official, 'scheduleVersion')
        ->where('source_type', RoomBlockSource::Schedule)
        ->where('source_id', $sessionThu->id)
        ->whereDate('start_at', '2026-11-12')
        ->sole();

    expect($mondayBlock->is_active)->toBeTrue()
        ->and($thursdayBlock->is_active)->toBeFalse()
        ->and($reschedule->fresh()->schedule_session_id)->toBe($sessionThu->id)
        ->and($reschedule->fresh()->original_room_id)->toBe($roomB->id)
        ->and($reschedule->fresh()->original_start_at->format('Y-m-d H:i'))->toBe('2026-11-12 10:00');
});

test('duplikat NIP pada bulk update dosen mengembalikan error validasi alih-alih 500', function () {
    $alpha = Lecturer::factory()->create(['name' => 'Dr. Alpha', 'nip' => '198001012005011001']);
    $beta = Lecturer::factory()->create(['name' => 'Dr. Beta', 'nip' => '198102022006022002']);

    $this->actingAs($this->admin)
        ->from(route('admin.lecturers.index'))
        ->put(route('admin.lecturers.bulk-update'), [
            'lecturers' => [
                [
                    'id' => $alpha->id,
                    'name' => 'Dr. Alpha',
                    'nip' => '198203032007031003',
                    'profile_status' => LecturerProfileStatus::Verified->value,
                ],
                [
                    'id' => $beta->id,
                    'name' => 'Dr. Beta',
                    'nip' => '198203032007031003',
                    'profile_status' => LecturerProfileStatus::Verified->value,
                ],
            ],
        ])
        ->assertSessionHasErrors(['lecturers.0.nip', 'lecturers.1.nip']);

    $this->actingAs($this->admin)
        ->from(route('admin.lecturers.index'))
        ->put(route('admin.lecturers.bulk-update'), [
            'lecturers' => [
                [
                    'id' => $alpha->id,
                    'name' => 'Dr. Alpha',
                    'nip' => $beta->nip,
                    'profile_status' => LecturerProfileStatus::Verified->value,
                ],
            ],
        ])
        ->assertSessionHasErrors('lecturers.0.nip');

    expect($alpha->fresh()->nip)->toBe('198001012005011001')
        ->and($beta->fresh()->nip)->toBe('198102022006022002');
});

test('mengosongkan jabatan pimpinan dosen tidak menghapus jabatan terjadwal masa depan dan tidak membuat rentang terbalik', function () {
    $service = app(LecturerService::class);
    $lecturer = Lecturer::factory()->create();

    LeadershipTerm::factory()->for($lecturer)->create([
        'position' => 'Sekretaris Jurusan',
        'valid_from' => '2026-11-04',
        'valid_to' => null,
    ]);
    $futureTerm = LeadershipTerm::factory()->for($lecturer)->create([
        'position' => 'Ketua Jurusan',
        'valid_from' => '2026-12-01',
        'valid_to' => null,
    ]);

    $service->save($lecturer, [
        'name' => $lecturer->name,
        'profile_status' => LecturerProfileStatus::Verified->value,
        'leadership_position' => '',
    ]);

    $fresh = $lecturer->fresh();

    expect($fresh->isLeaderOn(now()))->toBeFalse()
        ->and($fresh->isLeaderOn(CarbonImmutable::parse('2026-12-15')))->toBeTrue()
        ->and($futureTerm->fresh())->not->toBeNull()
        ->and($fresh->leadershipTerms()->whereNotNull('valid_to')->whereColumn('valid_to', '<', 'valid_from')->exists())->toBeFalse();
});

test('pembaruan kalender semester menyinkronkan blok ruang jadwal resmi', function () {
    $official = ScheduleVersion::factory()->official()->for($this->period)->create(['effective_from' => '2026-09-01']);
    $room = Room::factory()->create();
    ScheduleSession::factory()->for($official, 'version')->for($room)->create(['day_of_week' => 1]);
    app(ScheduleBlockBuilder::class)->rebuild($official);

    $this->actingAs($this->admin)
        ->from(route('admin.academic.index'))
        ->put(route('admin.academic.periods.update', $this->period), [
            'name' => $this->period->name,
            'start_date' => '2026-11-02',
            'end_date' => '2026-11-29',
        ])
        ->assertRedirect(route('admin.academic.index'))
        ->assertSessionHasNoErrors();

    $blockDates = $official->roomBlocks()->orderBy('start_at')->pluck('start_at')->map->toDateString()->all();

    expect($blockDates)->toBe(['2026-11-02', '2026-11-09', '2026-11-16', '2026-11-23']);
});

test('admin dapat mengganti draf aktif lewat tombol Gunakan dan pembuatan serta penetapan draf memperbarui draf aktif', function () {
    $draftService = app(ScheduleDraftService::class);
    $publisherService = app(SchedulePublisher::class);
    $scheduler = app(SchedulerService::class);
    $publisher = User::factory()->superAdmin()->create();

    $v1 = $draftService->create($this->period, ScheduleSource::Manual);
    $room = Room::factory()->create(['name' => 'AE 101', 'capacity' => 50]);
    $classGroup = ClassGroup::factory()->create(['name' => 'PTIK A 2026', 'student_count' => 30]);
    $lecturer = Lecturer::factory()->create(['name' => 'Dr. Andi']);
    $session = ScheduleSession::factory()->for($v1, 'version')->for($room)->create([
        'title' => 'Algoritma',
        'day_of_week' => 1,
        'start_minute' => 480,
        'end_minute' => 580,
    ]);
    $session->participants()->sync([$classGroup->id]);
    $session->lecturers()->sync([$lecturer->id => ['role' => 'lead']]);

    $v2 = $draftService->create($this->period->fresh(), ScheduleSource::Manual);

    expect($v1->version_no)->toBe(1)
        ->and($v2->version_no)->toBe(2)
        ->and($this->period->fresh()->active_draft_version_id)->toBe($v2->id);

    $this->actingAs($this->admin)
        ->get(route('admin.drafts.inspections'))
        ->assertOk()
        ->assertSeeInOrder(['Draf Aktif', 'Draf v2', 'Draf v1', route('admin.drafts.activate', $v1), 'Gunakan'])
        ->assertDontSee('action="'.route('admin.drafts.activate', $v2).'"', false);

    $this->get(route('admin.scheduler.create'))
        ->assertOk()
        ->assertSeeInOrder(['Draf Aktif', 'Draf v2 sedang dikerjakan', 'Draf Lainnya', 'Draf v1', route('admin.drafts.activate', $v1), 'Gunakan'])
        ->assertSee(['Susun Otomatis', 'Impor Excel / CSV', 'Tambah Draf', 'Arsipkan'])
        ->assertDontSee('Draf v1 sedang dikerjakan');

    $this->get(route('admin.schedules.index'))
        ->assertOk()
        ->assertSee('Draf v2 sedang disusun')
        ->assertDontSee('Draf v1 sedang disusun');

    $this->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('Draf v2')
        ->assertDontSee('Draf v1');

    $this->post(route('admin.drafts.activate', $v1))
        ->assertRedirect(route('admin.drafts.inspections'))
        ->assertSessionHas('toast', fn (array $toast): bool => ($toast['type'] ?? null) === 'success'
            && ($toast['title'] ?? null) === 'Draf v1 dijadikan draf aktif');

    expect($this->period->fresh()->active_draft_version_id)->toBe($v1->id)
        ->and($scheduler->baseVersion($this->period->fresh())?->id)->toBe($v1->id);

    $audit = AuditLog::query()
        ->where('action', 'schedule.draft_activated')
        ->where('auditable_type', $v1->getMorphClass())
        ->where('auditable_id', $v1->id)
        ->sole();

    expect($audit->before)->toBe(['active_draft_version_id' => $v2->id])
        ->and($audit->after)->toBe(['active_draft_version_id' => $v1->id, 'version_no' => 1]);

    $this->get(route('admin.drafts.inspections'))
        ->assertOk()
        ->assertSeeInOrder(['Draf Aktif', 'Draf v1', 'Draf v2', route('admin.drafts.activate', $v2), 'Gunakan'])
        ->assertDontSee('action="'.route('admin.drafts.activate', $v1).'"', false);

    $this->get(route('admin.scheduler.create'))
        ->assertOk()
        ->assertSeeInOrder(['Draf Aktif', 'Draf v1 sedang dikerjakan', 'Draf Lainnya', 'Draf v2', route('admin.drafts.activate', $v2), 'Gunakan'])
        ->assertDontSee('Draf v2 sedang dikerjakan');

    $this->post(route('admin.drafts.activate', $v2), ['redirect' => 'workspace'])
        ->assertRedirect(route('admin.scheduler.create'))
        ->assertSessionHas('toast', fn (array $toast): bool => ($toast['type'] ?? null) === 'success'
            && ($toast['title'] ?? null) === 'Draf v2 dijadikan draf aktif');

    expect($this->period->fresh()->active_draft_version_id)->toBe($v2->id);

    $this->post(route('admin.drafts.activate', $v1), ['redirect' => 'workspace'])
        ->assertRedirect(route('admin.scheduler.create'));

    expect($this->period->fresh()->active_draft_version_id)->toBe($v1->id);

    $this->get(route('admin.schedules.index'))
        ->assertOk()
        ->assertSee('Draf v1 sedang disusun')
        ->assertDontSee('Draf v2 sedang disusun');

    $this->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('Draf v1')
        ->assertDontSee('Draf v2');

    $v3 = $draftService->create($this->period->fresh(), ScheduleSource::Manual, $v1);

    expect($this->period->fresh()->active_draft_version_id)->toBe($v3->id);

    $publisherService->publish($v3, $publisher, now(), $v3->lock_version);

    expect($v3->fresh()->status)->toBe(ScheduleVersionStatus::Official)
        ->and($this->period->fresh()->active_draft_version_id)->toBeNull();
});

test('pengaktifan draf menolak versi resmi, periode tidak aktif, keti, dan tamu', function () {
    $activeDraft = ScheduleVersion::factory()->for($this->period)->create([
        'status' => ScheduleVersionStatus::Draft,
        'version_no' => 1,
    ]);
    $candidateDraft = ScheduleVersion::factory()->for($this->period)->create([
        'status' => ScheduleVersionStatus::Draft,
        'version_no' => 2,
    ]);
    $this->period->update(['active_draft_version_id' => $activeDraft->id]);

    $official = ScheduleVersion::factory()->official()->for($this->period)->create(['version_no' => 3]);
    $inactivePeriod = Period::factory()->create(['is_active' => false, 'name' => 'Genap 2025/2026']);
    $otherPeriodDraft = ScheduleVersion::factory()->for($inactivePeriod)->create(['status' => ScheduleVersionStatus::Draft]);

    $this->actingAs($this->admin)
        ->from(route('admin.drafts.inspections'))
        ->post(route('admin.drafts.activate', $official))
        ->assertRedirect(route('admin.drafts.inspections'))
        ->assertSessionHas('error_code', 'INVALID_STATUS_TRANSITION');

    expect($this->period->fresh()->active_draft_version_id)->toBe($activeDraft->id)
        ->and(AuditLog::query()->where('action', 'schedule.draft_activated')->exists())->toBeFalse();

    $this->actingAs($this->admin)
        ->post(route('admin.drafts.activate', $otherPeriodDraft))
        ->assertNotFound();

    expect($this->period->fresh()->active_draft_version_id)->toBe($activeDraft->id)
        ->and($inactivePeriod->fresh()->active_draft_version_id)->toBeNull();

    $this->actingAs(User::factory()->approvedKeti()->create())
        ->post(route('admin.drafts.activate', $candidateDraft))
        ->assertForbidden();

    expect($this->period->fresh()->active_draft_version_id)->toBe($activeDraft->id);

    auth()->logout();
    $this->post(route('admin.drafts.activate', $candidateDraft))
        ->assertRedirect(route('login'));

    expect($this->period->fresh()->active_draft_version_id)->toBe($activeDraft->id);
});

test('pengarsipan draf memindahkan draf ke arsip dan mengalihkan draf aktif bila perlu', function () {
    $draftService = app(ScheduleDraftService::class);
    $v1 = $draftService->create($this->period, ScheduleSource::Manual);
    $v2 = $draftService->create($this->period->fresh(), ScheduleSource::Generated);
    $v3 = $draftService->create($this->period->fresh(), ScheduleSource::Generated);

    expect($this->period->fresh()->active_draft_version_id)->toBe($v3->id);

    // Arsipkan draf non-aktif v1 dari halaman utama penyusunan.
    $this->actingAs($this->admin)
        ->post(route('admin.drafts.archive', $v1), ['redirect' => 'workspace'])
        ->assertRedirect(route('admin.scheduler.create'))
        ->assertSessionHas('toast', fn (array $toast): bool => ($toast['type'] ?? null) === 'success'
            && ($toast['title'] ?? null) === 'Draf v1 diarsipkan');

    expect($v1->fresh()->status)->toBe(ScheduleVersionStatus::Archived)
        ->and($this->period->fresh()->active_draft_version_id)->toBe($v3->id);

    $archiveAudit = AuditLog::query()
        ->where('action', 'schedule.draft_archived')
        ->where('auditable_type', $v1->getMorphClass())
        ->where('auditable_id', $v1->id)
        ->sole();

    expect($archiveAudit->before)->toBe(['status' => ScheduleVersionStatus::Draft->value])
        ->and($archiveAudit->after)->toBe(['status' => ScheduleVersionStatus::Archived->value, 'version_no' => 1]);

    $this->get(route('admin.scheduler.create'))
        ->assertOk()
        ->assertSee('Draf v3 sedang dikerjakan')
        ->assertSee('Draf v2')
        ->assertDontSee(route('admin.drafts.show', $v1), false)
        ->assertDontSee(route('admin.drafts.activate', $v1), false);

    $this->get(route('admin.drafts.inspections'))
        ->assertOk()
        ->assertSee('Draf v3')
        ->assertSee('Draf v2')
        ->assertDontSee('Draf v1');

    $this->get(route('admin.scheduler.create'))
        ->assertOk()
        ->assertDontSee('Draf v1');

    // Arsipkan draf aktif v3 dari halaman pemeriksaan; draf aktif berpindah ke v2.
    $this->post(route('admin.drafts.archive', $v3), ['redirect' => 'inspections'])
        ->assertRedirect(route('admin.drafts.inspections'));

    expect($v3->fresh()->status)->toBe(ScheduleVersionStatus::Archived)
        ->and($this->period->fresh()->active_draft_version_id)->toBe($v2->id);

    // Arsipkan draf terakhir v2; active_draft_version_id menjadi null.
    $this->post(route('admin.drafts.archive', $v2))
        ->assertRedirect(route('admin.scheduler.create'));

    expect($v2->fresh()->status)->toBe(ScheduleVersionStatus::Archived)
        ->and($this->period->fresh()->active_draft_version_id)->toBeNull();

    $this->get(route('admin.scheduler.create'))
        ->assertOk()
        ->assertSee('Belum ada draf jadwal');
});

test('pengarsipan draf menolak versi resmi, periode tidak aktif, keti, dan tamu', function () {
    $draft = ScheduleVersion::factory()->for($this->period)->create([
        'status' => ScheduleVersionStatus::Draft,
        'version_no' => 1,
    ]);
    $this->period->update(['active_draft_version_id' => $draft->id]);

    $official = ScheduleVersion::factory()->official()->for($this->period)->create(['version_no' => 2]);
    $inactivePeriod = Period::factory()->create(['is_active' => false, 'name' => 'Genap 2025/2026']);
    $otherPeriodDraft = ScheduleVersion::factory()->for($inactivePeriod)->create(['status' => ScheduleVersionStatus::Draft]);

    $this->actingAs($this->admin)
        ->from(route('admin.scheduler.create'))
        ->post(route('admin.drafts.archive', $official))
        ->assertRedirect(route('admin.scheduler.create'))
        ->assertSessionHas('error_code', 'INVALID_STATUS_TRANSITION');

    expect($official->fresh()->status)->toBe(ScheduleVersionStatus::Official)
        ->and(AuditLog::query()->where('action', 'schedule.draft_archived')->exists())->toBeFalse();

    $this->actingAs($this->admin)
        ->post(route('admin.drafts.archive', $otherPeriodDraft))
        ->assertNotFound();

    expect($otherPeriodDraft->fresh()->status)->toBe(ScheduleVersionStatus::Draft);

    $this->actingAs(User::factory()->approvedKeti()->create())
        ->post(route('admin.drafts.archive', $draft))
        ->assertForbidden();

    expect($draft->fresh()->status)->toBe(ScheduleVersionStatus::Draft);

    auth()->logout();
    $this->post(route('admin.drafts.archive', $draft))
        ->assertRedirect(route('login'));

    expect($draft->fresh()->status)->toBe(ScheduleVersionStatus::Draft);
});

test('admin dapat memperbarui dan menghapus label draf dengan validasi panjang serta otorisasi periode dan status', function () {
    $draft = ScheduleVersion::factory()->for($this->period)->create([
        'status' => ScheduleVersionStatus::Draft,
        'version_no' => 1,
        'lock_version' => 3,
    ]);
    $official = ScheduleVersion::factory()->official()->for($this->period)->create(['version_no' => 2]);
    $otherPeriod = Period::factory()->create(['is_active' => false, 'name' => 'Genap 2025/2026']);
    $otherPeriodDraft = ScheduleVersion::factory()->for($otherPeriod)->create(['status' => ScheduleVersionStatus::Draft]);

    $this->actingAs($this->admin)
        ->patch(route('admin.drafts.label', $draft), [
            'label' => '  Opsi Pagi Padat  ',
            'redirect' => 'inspections',
        ])
        ->assertRedirect(route('admin.drafts.inspections'))
        ->assertSessionHas('toast', fn (array $toast): bool => ($toast['type'] ?? null) === 'success'
            && ($toast['title'] ?? null) === 'Label draf v1 disimpan');

    expect($draft->fresh()->label)->toBe('Opsi Pagi Padat')
        ->and($draft->fresh()->lock_version)->toBe(3);

    $savedLog = AuditLog::query()
        ->where('action', 'schedule.draft_labeled')
        ->where('auditable_id', $draft->id)
        ->latest('id')
        ->firstOrFail();

    expect($savedLog->before)->toBe(['label' => null])
        ->and($savedLog->after)->toBe(['label' => 'Opsi Pagi Padat', 'version_no' => 1]);

    // Kosongkan label untuk menghapus.
    $this->patch(route('admin.drafts.label', $draft), [
        'label' => '   ',
    ])
        ->assertRedirect(route('admin.scheduler.create'))
        ->assertSessionHas('toast', fn (array $toast): bool => ($toast['type'] ?? null) === 'success'
            && ($toast['title'] ?? null) === 'Label draf v1 dihapus');

    expect($draft->fresh()->label)->toBeNull();

    $clearedLog = AuditLog::query()
        ->where('action', 'schedule.draft_labeled')
        ->where('auditable_id', $draft->id)
        ->latest('id')
        ->firstOrFail();

    expect($clearedLog->before)->toBe(['label' => 'Opsi Pagi Padat'])
        ->and($clearedLog->after)->toBe(['label' => null, 'version_no' => 1]);

    // Validasi maksimal 120 karakter.
    $this->from(route('admin.scheduler.create'))
        ->patch(route('admin.drafts.label', $draft), ['label' => str_repeat('a', 121)])
        ->assertRedirect(route('admin.scheduler.create'))
        ->assertSessionHasErrors('label');

    // Menolak non-draf (jadwal resmi) dan draf dari periode lain.
    $this->from(route('admin.scheduler.create'))
        ->patch(route('admin.drafts.label', $official), ['label' => 'Tidak Boleh'])
        ->assertRedirect(route('admin.scheduler.create'))
        ->assertSessionHas('error_code', 'INVALID_STATUS_TRANSITION');

    $this->patch(route('admin.drafts.label', $otherPeriodDraft), ['label' => 'Periode Lain'])
        ->assertNotFound();

    // Keti & tamu ditolak.
    $this->actingAs(User::factory()->approvedKeti()->create())
        ->patch(route('admin.drafts.label', $draft), ['label' => 'Keti'])
        ->assertForbidden();

    auth()->logout();
    $this->patch(route('admin.drafts.label', $draft), ['label' => 'Tamu'])
        ->assertRedirect(route('login'));
});

test('halaman penyusunan dan daftar pemeriksaan menampilkan mode penyusunan, skor mutu, selisih, label draf, dan menu unduh popover yang aksesibel', function () {
    $official = ScheduleVersion::factory()->official()->for($this->period)->create(['version_no' => 1]);
    $room = Room::factory()->create(['name' => 'AE 101', 'capacity' => 45]);
    $classGroup = ClassGroup::factory()->create(['name' => 'PTIK A 2026', 'student_count' => 30]);
    $lecturer = Lecturer::factory()->create(['name' => 'Dr. Andi']);

    $officialSession = ScheduleSession::factory()->for($official, 'version')->for($room)->create([
        'title' => 'Algoritma',
        'day_of_week' => 1,
        'start_minute' => 450,
        'end_minute' => 550,
    ]);
    $officialSession->participants()->sync([$classGroup->id]);
    $officialSession->lecturers()->sync([$lecturer->id => ['role' => 'lead']]);

    $draftService = app(ScheduleDraftService::class);
    $draftIdentical = $draftService->create($this->period, ScheduleSource::Manual, $official, 'Opsi Pagi');
    $draftModified = $draftService->create($this->period->fresh(), ScheduleSource::Generated, $official, 'Opsi Lab Prioritas');
    $draftModified->sessions()->sole()->update(['day_of_week' => 2]);

    $workspaceResponse = $this->actingAs($this->admin)
        ->get(route('admin.scheduler.create'))
        ->assertOk()
        ->assertSee('Mode Penyusunan')
        ->assertSee('Perbaiki & Pertahankan')
        ->assertSee('Susun Ulang Segar')
        ->assertSee('name="strategy"', false)
        ->assertSee('Label skenario (opsional)')
        ->assertSee('name="label"', false)
        ->assertSee('Opsi Lab Prioritas')
        ->assertSee('Opsi Pagi')
        ->assertSee('Skor 100/100')
        ->assertSee('1 sesi berubah dari v1')
        ->assertSee('Identik dengan v1')
        ->assertSee('popovertarget="draft-download-'.$draftModified->id.'"', false)
        ->assertSee('popover="auto"', false)
        ->assertSee(route('admin.drafts.excel', [$draftModified, 'tinjau' => 'kelas']), false)
        ->assertSee(route('admin.drafts.pdf', [$draftModified, 'tinjau' => 'ruang']), false);

    preg_match('/<button[^>]*popovertarget="draft-download-'.$draftModified->id.'"[\s\S]*?<\/div>\s*<\/div>\s*<\/div>/s', $workspaceResponse->getContent(), $workspacePopover);
    expect($workspacePopover[0] ?? '')->toContain('popover="auto"')
        ->not->toContain('role="menu"')
        ->not->toContain('aria-haspopup');

    $inspectionsResponse = $this->get(route('admin.drafts.inspections'))
        ->assertOk()
        ->assertSee('Opsi Lab Prioritas')
        ->assertSee('Opsi Pagi')
        ->assertSee('Skor 100/100')
        ->assertSee('1 sesi berubah dari v1')
        ->assertSee('Identik dengan v1')
        ->assertSee('popovertarget="draft-download-'.$draftModified->id.'"', false)
        ->assertSee('popover="auto"', false)
        ->assertSee(route('admin.drafts.excel', [$draftModified, 'tinjau' => 'dosen']), false)
        ->assertSee(route('admin.drafts.pdf', [$draftModified, 'tinjau' => 'kelas']), false);

    preg_match('/<button[^>]*popovertarget="draft-download-'.$draftModified->id.'"[\s\S]*?<\/div>\s*<\/div>\s*<\/div>/s', $inspectionsResponse->getContent(), $inspectionsPopover);
    expect($inspectionsPopover[0] ?? '')->toContain('popover="auto"')
        ->not->toContain('role="menu"')
        ->not->toContain('aria-haspopup');

    // Smoke check endpoint ekspor Excel & PDF mengembalikan 200 OK dengan content-type sesuai.
    $this->get(route('admin.drafts.excel', $draftModified))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    $this->get(route('admin.drafts.pdf', $draftModified))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});
