<?php

use App\Enums\LecturerHomebase;
use App\Enums\LecturerProfileStatus;
use App\Enums\ScheduleVersionStatus;
use App\Enums\SessionMode;
use App\Models\AuditLog;
use App\Models\ClassGroup;
use App\Models\Course;
use App\Models\CourseOffering;
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

test('rekap beban tidak memberi SKS penuh ke setiap anggota tim', function () {
    $offering = CourseOffering::factory()->for($this->period)->create();
    $offering->course->update(['sks' => 3]);
    $lead = Lecturer::factory()->create(['name' => 'Rina Kartika']);
    $partner = Lecturer::factory()->create(['name' => 'Sinta Amelia']);

    $this->actingAs($this->admin)->post(route('admin.teaching.store'), ['course_offering_id' => $offering->id, 'lecturer_id' => $lead->id, 'role' => 'lead', 'phase' => 'full']);
    $loads = app(TeachingLoadService::class);
    expect($loads->summary($this->period)->first()['assigned'])->toBe(3.0);

    $this->post(route('admin.teaching.store'), ['course_offering_id' => $offering->id, 'lecturer_id' => $partner->id, 'role' => 'partner', 'phase' => 'full']);
    $summary = $loads->summary($this->period);

    expect($summary->pluck('assigned')->all())->toBe([0.0, 0.0])
        ->and($summary->pluck('pending')->all())->toBe([1, 1]);

    $assignment = TeachingAssignment::query()->where('lecturer_id', $lead->id)->sole();
    $this->post(route('admin.teaching.lock', $assignment));
    $this->delete(route('admin.teaching.destroy', $assignment))->assertSessionHas('error');
    expect($assignment->fresh())->not->toBeNull();
});
