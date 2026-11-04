<?php

use App\Enums\LecturerHomebase;
use App\Enums\LecturerProfileStatus;
use App\Enums\ScheduleVersionStatus;
use App\Enums\SessionMode;
use App\Models\AuditLog;
use App\Models\ClassGroup;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\Import;
use App\Models\Lecturer;
use App\Models\Period;
use App\Models\Room;
use App\Models\ScheduleSession;
use App\Models\ScheduleVersion;
use App\Models\TeachingAssignment;
use App\Models\User;
use App\Services\TeachingLoadService;
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
    $c1 = ClassGroup::factory()->create(['name' => 'PTIK A 2026', 'study_program' => 'PTIK', 'cohort_year' => 2026, 'student_count' => null]);
    $c2 = ClassGroup::factory()->create(['name' => 'PTIK B 2026', 'study_program' => 'PTIK', 'cohort_year' => 2026, 'student_count' => null]);
    $c3 = ClassGroup::factory()->create(['name' => 'TEKOM A 2026', 'study_program' => 'TEKOM', 'cohort_year' => 2026, 'student_count' => null]);

    // 1. Set Jumlah Mahasiswa massal
    $this->actingAs($this->admin)->post(route('admin.academic.classes.batch'), [
        'action' => 'set_student_count',
        'class_ids' => [$c1->id, $c2->id],
        'student_count' => 40,
    ])->assertRedirect(route('admin.academic.index', ['tab' => 'kelas']));

    expect($c1->fresh()->student_count)->toBe(40)
        ->and($c2->fresh()->student_count)->toBe(40)
        ->and($c3->fresh()->student_count)->toBeNull();

    // 2. Set Angkatan massal
    $this->post(route('admin.academic.classes.batch'), [
        'action' => 'set_cohort_year',
        'class_ids' => [$c1->id, $c2->id],
        'cohort_year' => 2027,
    ])->assertRedirect();

    expect($c1->fresh()->cohort_year)->toBe(2027)
        ->and($c2->fresh()->cohort_year)->toBe(2027);

    // 3. Set Program Studi massal
    $this->post(route('admin.academic.classes.batch'), [
        'action' => 'set_study_program',
        'class_ids' => [$c1->id, $c2->id],
        'study_program' => 'Pendidikan Teknik Informatika',
    ])->assertRedirect();

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
    $c1 = ClassGroup::factory()->create(['name' => 'PTIK A', 'study_program' => 'PTIK', 'cohort_year' => 2026, 'student_count' => null]);
    $c2 = ClassGroup::factory()->create(['name' => 'PTIK B', 'study_program' => 'PTIK', 'cohort_year' => 2026, 'student_count' => null]);

    $this->actingAs($this->admin)->put(route('admin.academic.classes.bulk-update'), [
        'classes' => [
            [
                'id' => $c1->id,
                'name' => 'PTIK A Unggulan',
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
    ])->assertRedirect(route('admin.academic.index', ['tab' => 'kelas']));

    expect($c1->fresh())
        ->name->toBe('PTIK A Unggulan')
        ->student_count->toBe(38);

    expect($c2->fresh())
        ->name->toBe('PTIK B Reguler')
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
    $partner = TeachingAssignment::factory()->for($offering, 'courseOffering')->create(['role' => 'partner']);
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
        ->and($partner->fresh()->credited_sks)->toBeNull();

    $response = $this->get(route('admin.teaching.index'))->assertOk()->assertSee('SKS Semester');
    $loads = $response->viewData('loads');
    expect($loads->pluck('meeting_sks')->all())->toBe([$expectedLoad, $expectedLoad])
        ->and($loads->pluck('assigned')->all())->toBe([(float) $sks, (float) $sks])
        ->and($loads->pluck('pending')->all())->toBe([0, 0])
        ->and($loads->pluck('pending_phases')->all())->toBe([0, 0]);

    expect($this->get($preview)->assertOk()->viewData('plan')['already_set'])->toBe(1);
})->with([[2, 14], [3, 21], [4, 28]]);

test('pembagian pertemuan melewati tim yang belum lengkap dan menolak usulan yang berubah setelah ditinjau', function () {
    $offering = CourseOffering::factory()->for($this->period)->create();
    $lead = TeachingAssignment::factory()->for($offering, 'courseOffering')->create();
    $partner = TeachingAssignment::factory()->for($offering, 'courseOffering')->create(['role' => 'partner']);
    $incomplete = CourseOffering::factory()->for($this->period)->create();
    TeachingAssignment::factory()->for($incomplete, 'courseOffering')->create();
    $preview = route('admin.teaching.phases.preview');

    $plan = $this->actingAs($this->admin)->get($preview)->assertOk()->viewData('plan');
    expect($plan['rows'])->toHaveCount(1)
        ->and(collect($plan['skipped'])->pluck('reason')->all())->toBe(['Tetapkan tepat satu pengampu dan satu mitra yang berbeda.']);

    $lead->update(['is_locked' => true]);
    $this->from($preview)->post(route('admin.teaching.phases.apply'), ['fingerprint' => $plan['fingerprint']])
        ->assertRedirect($preview)->assertSessionHasErrors('fingerprint');

    expect($lead->fresh()->phase->value)->toBe('full')
        ->and($partner->fresh()->phase->value)->toBe('full');
    expect(collect($this->get($preview)->assertOk()->viewData('plan')['skipped'])->pluck('reason'))
        ->toContain('Fase yang perlu diubah berada pada penugasan terkunci.');
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
});
