<?php

use App\Enums\RoomBlockSource;
use App\Enums\RoomStatus;
use App\Enums\SessionMode;
use App\Enums\SessionPhase;
use App\Enums\TeachingRole;
use App\Models\Academic\ClassGroup;
use App\Models\Academic\SessionReschedule;
use App\Models\Lecturer\Lecturer;
use App\Models\Room\Room;
use App\Models\Room\RoomBlock;
use App\Models\Scheduling\ScheduleSession;
use App\Models\User\AppNotification;
use App\Models\User\AuditLog;
use App\Models\User\User;
use App\Services\Room\AvailabilityService;
use App\Services\Room\ReservationService;
use App\Services\Scheduling\ScheduleBlockBuilder;
use App\Support\Wita;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00'));
    $this->classC = ClassGroup::factory()->create(['name' => 'Tekom C']);
    $this->keti = User::factory()->approvedKeti($this->classC)->create();
    $this->lead = Lecturer::factory()->create(['name' => 'Budi Santoso']);
    $this->partner = Lecturer::factory()->create(['name' => 'Sari Dewi']);
    $this->team = [
        [$this->lead, TeachingRole::Lead, SessionPhase::BeforeUts],
        [$this->partner, TeachingRole::Partner, SessionPhase::AfterUts],
    ];
    $this->ae107 = Room::factory()->create(['name' => 'AE 107']);
    $this->ae209 = Room::factory()->create(['name' => 'AE 209']);
    $this->session = meetingSession([$this->classC], ['title' => 'Pemrograman Web', 'room_id' => $this->ae107->id], $this->team);
});

function reschedulePage(array $query = []): string
{
    return route('keti.meetings.reschedule', ['sesi' => test()->session->id, 'tanggal' => '2026-11-09', ...$query]);
}

function movePayload(array $overrides = []): array
{
    return [
        'session_id' => test()->session->id,
        'original_date' => '2026-11-09',
        'start_at' => '2026-11-10 10:00',
        'room_id' => test()->ae209->id,
        'lecturer_ids' => [test()->partner->id],
        'idempotency_key' => (string) str()->uuid(),
        ...$overrides,
    ];
}

test('halaman Pindahkan menampilkan pertemuan dan mencentang dosen yang bergiliran', function () {
    $content = $this->actingAs($this->keti)->get(reschedulePage())->assertOk()
        ->assertSee('Pindahkan pertemuan')
        ->assertSee('Pemrograman Web')
        ->assertSee('bukan giliran sesuai fase')
        ->getContent();

    expect($content)->toMatch('/value="'.$this->partner->id.'"[^>]*checked/')
        ->and($content)->not->toMatch('/value="'.$this->lead->id.'"[^>]*checked/');
});

test('slot yang aman menampilkan ruang kosong dengan ruang asli di urutan pertama', function () {
    // Nama ruang asli sengaja di urutan abjad terakhir agar pengurutannya teruji.
    $this->ae107->update(['name' => 'Ruang Z']);

    $content = $this->actingAs($this->keti)->get(reschedulePage(['ke_tanggal' => '2026-11-10', 'jam' => '10:00']))->assertOk()
        ->assertSee('Pindahkan ke sini')
        ->getContent();
    $listing = substr($content, strpos($content, 'ruangan kosong'));

    expect(strpos($listing, 'Ruang Z'))->toBeLessThan(strpos($listing, 'AE 209'));
});

test('dosen yang bentrok ditampilkan sebelum daftar ruang', function () {
    meetingSession([ClassGroup::factory()->create()], ['title' => 'Basis Data', 'day_of_week' => 2, 'start_minute' => 600, 'end_minute' => 750, 'room_id' => Room::factory()->create()->id], $this->team);

    $this->actingAs($this->keti)->get(reschedulePage(['ke_tanggal' => '2026-11-10', 'jam' => '10:00']))->assertOk()
        ->assertSee('Sari Dewi (mitra)')
        ->assertDontSee('Pindahkan ke sini');
});

test('memindahkan pertemuan mengarahkan ke reservasi pengganti', function () {
    $response = $this->actingAs($this->keti)->post(route('keti.meetings.reschedule.store'), movePayload());

    $reschedule = SessionReschedule::query()->sole();
    $response->assertRedirect(route('keti.reservations.show', $reschedule->reservation_id));
});

test('pelanggaran aturan kembali ke halaman Pindahkan dengan pesan error', function () {
    $this->actingAs($this->keti)
        ->post(route('keti.meetings.reschedule.store'), movePayload(['lecturer_ids' => []]))
        ->assertRedirect(reschedulePage(['ke_tanggal' => '2026-11-10', 'jam' => '10:00']))
        ->assertSessionHas('reschedule_error.code', 'INVALID_LECTURER');

    $this->actingAs($this->keti)
        ->postJson(route('keti.meetings.reschedule.store'), movePayload(['lecturer_ids' => []]))
        ->assertConflict()
        ->assertJsonPath('code', 'INVALID_LECTURER');

    expect(SessionReschedule::query()->count())->toBe(0);
});

test('pertemuan yang tidak ada 404 dan Keti kelas lain 403', function () {
    $this->actingAs($this->keti)->get(reschedulePage(['tanggal' => '2026-11-10']))->assertNotFound();
    $this->actingAs(User::factory()->approvedKeti()->create())->get(reschedulePage())->assertForbidden();
});

function myClassUrl(string $date): string
{
    return route('keti.my-class', ['tanggal' => $date]);
}

function rescheduleUrl(ScheduleSession $session, string $date): string
{
    return route('keti.meetings.reschedule', ['sesi' => $session->id, 'tanggal' => $date]);
}

test('My Class menampilkan status pertemuan mengikuti jam', function () {
    $morning = meetingSession([$this->classC], ['title' => 'Kuliah Pagi', 'day_of_week' => 3, 'start_minute' => 450, 'end_minute' => 600]);
    $afternoon = meetingSession([$this->classC], ['title' => 'Kuliah Siang', 'day_of_week' => 3, 'start_minute' => 780, 'end_minute' => 880]);
    $online = meetingSession([$this->classC], ['title' => 'Kuliah Daring', 'day_of_week' => 3, 'start_minute' => 900, 'end_minute' => 960, 'mode' => SessionMode::Online, 'room_id' => null]);

    $this->actingAs($this->keti)->get(myClassUrl('2026-11-04'))->assertOk()
        ->assertSee('Selesai')
        ->assertSee('Akan datang')
        ->assertSee('Online')
        ->assertSee(rescheduleUrl($afternoon, '2026-11-04'))
        ->assertDontSee(rescheduleUrl($morning, '2026-11-04'))
        ->assertDontSee(rescheduleUrl($online, '2026-11-04'));

    $this->travelTo(CarbonImmutable::parse('2026-11-04 13:30'));

    $this->actingAs($this->keti)->get(myClassUrl('2026-11-04'))->assertOk()
        ->assertSee('Sedang berlangsung')
        ->assertDontSee(rescheduleUrl($afternoon, '2026-11-04'));
});

test('pertemuan yang dipindah tampil sebagai Dipindahkan dan penggantinya tampil di tanggal tujuan', function () {
    $this->actingAs($this->keti)->post(route('keti.meetings.reschedule.store'), movePayload());
    $start = CarbonImmutable::parse('2026-11-10 10:00');

    $this->actingAs($this->keti)->get(myClassUrl('2026-11-09'))->assertOk()
        ->assertSee('Dipindahkan')
        ->assertSee('ke '.Wita::dayName($start).', '.Wita::longDate($start).' • '.Wita::clock($start, false).' • AE 209')
        ->assertSee('Lihat pengganti')
        ->assertDontSee(rescheduleUrl($this->session, '2026-11-09'));

    $this->actingAs($this->keti)->get(myClassUrl('2026-11-10'))->assertOk()
        ->assertSee('Kuliah Pengganti')
        ->assertSee('Pemrograman Web');
});

test('halaman reservasi pengganti menampilkan jadwal asal, dosen, dan aksi pemindahan', function () {
    $this->actingAs($this->keti)->post(route('keti.meetings.reschedule.store'), movePayload());
    $reschedule = SessionReschedule::query()->sole();

    $this->actingAs($this->keti)->get(route('keti.reservations.show', $reschedule->reservation_id))->assertOk()
        ->assertSee('Pertemuan berhasil dipindahkan')
        ->assertSee('Pemrograman Web')
        ->assertSee('Dosen: Sari Dewi (mitra)')
        ->assertSee('Batalkan pemindahan')
        ->assertSee('Pindahkan lagi');
});

test('reservasi biasa tetap memakai tombol Batalkan', function () {
    $reservation = app(ReservationService::class)->create($this->keti, $this->ae209, CarbonImmutable::parse('2026-11-05 10:00'), CarbonImmutable::parse('2026-11-05 11:00'), 'Rapat', null, (string) str()->uuid());

    $this->actingAs($this->keti)->get(route('keti.reservations.show', $reservation))->assertOk()
        ->assertSee('Batalkan')
        ->assertDontSee('Batalkan pemindahan')
        ->assertDontSee('Pindahkan lagi');
});

test('Keti kelas gabungan lain boleh melihat dan mengelola pengganti', function () {
    $classA = ClassGroup::factory()->create(['name' => 'Tekom A']);
    $ketiA = User::factory()->approvedKeti($classA)->create();
    $joint = meetingSession([$this->classC, $classA], ['title' => 'Statistika', 'day_of_week' => 3, 'start_minute' => 780, 'end_minute' => 880, 'room_id' => $this->ae107->id], $this->team);
    $this->actingAs($this->keti)->post(route('keti.meetings.reschedule.store'), movePayload(['session_id' => $joint->id, 'original_date' => '2026-11-11', 'start_at' => '2026-11-12 13:00']));
    $reservationId = SessionReschedule::query()->sole()->reservation_id;

    $this->actingAs($ketiA)->get(route('keti.reservations.show', $reservationId))->assertOk()
        ->assertSee('Statistika')
        ->assertSee(route('keti.meetings.reschedule', ['sesi' => $joint->id, 'tanggal' => '2026-11-11', 'pengganti' => $reservationId]));
    $this->actingAs($ketiA)->get(myClassUrl('2026-11-12'))->assertOk()->assertSee('Kuliah Pengganti');
});

test('memilih tanggal baru menampilkan rekomendasi slot waktu dan ruangan kosong yang cocok dengan durasi SKS', function () {
    $this->actingAs($this->keti)->get(reschedulePage(['ke_tanggal' => '2026-11-10']))->assertOk()
        ->assertSee('Rekomendasi Waktu dan Ruangan Kosong')
        ->assertSee('ruangan kosong')
        ->assertSee('Pindahkan ke sini')
        ->assertSee('AE 107')
        ->assertSee('AE 209');

    // Slot yang bentrok dosen (Sari Dewi) pada hari Selasa jam 10.00-12.30 tidak boleh mematikan slot lain yang aman
    meetingSession([ClassGroup::factory()->create()], ['title' => 'Basis Data', 'day_of_week' => 2, 'start_minute' => 600, 'end_minute' => 750, 'room_id' => Room::factory()->create()->id], $this->team);

    $content = $this->actingAs($this->keti)->get(reschedulePage(['ke_tanggal' => '2026-11-10']))->assertOk()->getContent();
    expect($content)->toContain('07:30')
        ->and($content)->toContain('Pindahkan ke sini');
});

test('halaman menampilkan panduan saat tanggal pemindahan belum dipilih', function () {
    $this->actingAs($this->keti)->get(reschedulePage())->assertOk()
        ->assertSee('Pilih Tanggal Pemindahan')
        ->assertDontSee('Pindahkan ke sini');
});

test('Keti dapat langsung menjadikan pertemuan Online dari My Class dan mengembalikannya ke luring', function () {
    $this->actingAs($this->keti)->get(myClassUrl('2026-11-09'))->assertOk()
        ->assertSee('Jadikan Online')
        ->assertSee('Ya, Jadikan Online');

    $this->actingAs($this->keti)->post(route('keti.meetings.online.store'), [
        'session_id' => $this->session->id,
        'original_date' => '2026-11-09',
        'lecturer_ids' => [$this->partner->id],
        'note' => 'Arahan dosen via WhatsApp',
        'idempotency_key' => (string) str()->uuid(),
    ])->assertRedirect(myClassUrl('2026-11-09'));

    $reschedule = SessionReschedule::query()->sole();

    $this->actingAs($this->keti)->get(myClassUrl('2026-11-09'))->assertOk()
        ->assertSee('Online • Ruang dilepas')
        ->assertSee('Ruang AE 107 dilepas untuk kelas lain')
        ->assertSee('Arahan dosen via WhatsApp')
        ->assertSee('Kembalikan ke luring');

    $this->actingAs($this->keti)->post(route('keti.meetings.online.cancel', $reschedule))
        ->assertRedirect(myClassUrl('2026-11-09'));

    $this->actingAs($this->keti)->get(myClassUrl('2026-11-09'))->assertOk()
        ->assertSee('Akan datang')
        ->assertSee('Detail ruangan');
});

test('Keti dapat memilih Kuliah Online di halaman Pindahkan meskipun ruangan fisik penuh', function () {
    $otherKeti = User::factory()->approvedKeti(ClassGroup::factory()->create())->create();
    $thirdKeti = User::factory()->approvedKeti(ClassGroup::factory()->create())->create();
    foreach ([['07:30', '10:50'], ['10:50', '14:10'], ['14:10', '17:30']] as [$from, $to]) {
        app(ReservationService::class)->create($otherKeti, $this->ae107, CarbonImmutable::parse("2026-11-10 {$from}"), CarbonImmutable::parse("2026-11-10 {$to}"), 'Acara 1', null, (string) str()->uuid());
        app(ReservationService::class)->create($thirdKeti, $this->ae209, CarbonImmutable::parse("2026-11-10 {$from}"), CarbonImmutable::parse("2026-11-10 {$to}"), 'Acara 2', null, (string) str()->uuid());
    }

    $this->actingAs($this->keti)->get(reschedulePage(['ke_tanggal' => '2026-11-10']))->assertOk()
        ->assertSee('Ruangan fisik penuh • Tersedia Online')
        ->assertSee('Kuliah Online (Tanpa Ruangan)')
        ->assertSee('Pilih Kuliah Online');

    $this->actingAs($this->keti)->post(route('keti.meetings.online.store'), [
        'session_id' => $this->session->id,
        'original_date' => '2026-11-09',
        'start_at' => '2026-11-10 10:00',
        'lecturer_ids' => [$this->partner->id],
        'idempotency_key' => (string) str()->uuid(),
    ])->assertRedirect(myClassUrl('2026-11-10'));

    $this->actingAs($this->keti)->get(myClassUrl('2026-11-09'))->assertOk()
        ->assertSee('Dipindahkan')
        ->assertSee('Kuliah Online');

    $this->actingAs($this->keti)->get(myClassUrl('2026-11-10'))->assertOk()
        ->assertSee('Kuliah Online (Tanpa Ruangan)')
        ->assertSee('Kuliah Pengganti • Pemrograman Web');
});

test('Keti kelas lain dilarang mengelola kuliah online kelas yang bukan pesertanya', function () {
    $this->actingAs($this->keti)->post(route('keti.meetings.online.store'), [
        'session_id' => $this->session->id,
        'original_date' => '2026-11-09',
        'lecturer_ids' => [$this->partner->id],
        'idempotency_key' => (string) str()->uuid(),
    ])->assertRedirect();

    $reschedule = SessionReschedule::query()->sole();
    $otherKeti = User::factory()->approvedKeti(ClassGroup::factory()->create(['name' => 'Tekom A']))->create();

    $this->actingAs($otherKeti)->post(route('keti.meetings.online.cancel', $reschedule))
        ->assertForbidden();

    $this->actingAs($otherKeti)->postJson(route('keti.meetings.online.store'), [
        'session_id' => $this->session->id,
        'original_date' => '2026-11-09',
        'lecturer_ids' => [$this->partner->id],
        'idempotency_key' => (string) str()->uuid(),
    ])->assertStatus(409)->assertJsonPath('code', 'NOT_CLASS_REPRESENTATIVE');
});

test('Keti kelas gabungan lain ikut melihat status Kuliah Online di My Class', function () {
    $classA = ClassGroup::factory()->create(['name' => 'Tekom A']);
    $ketiA = User::factory()->approvedKeti($classA)->create();
    $joint = meetingSession([$this->classC, $classA], ['title' => 'Statistika', 'day_of_week' => 3, 'start_minute' => 780, 'end_minute' => 880, 'room_id' => $this->ae107->id], $this->team);

    $this->actingAs($this->keti)->post(route('keti.meetings.online.store'), [
        'session_id' => $joint->id,
        'original_date' => '2026-11-11',
        'lecturer_ids' => [$this->partner->id],
        'idempotency_key' => (string) str()->uuid(),
    ])->assertRedirect();

    $this->actingAs($ketiA)->get(myClassUrl('2026-11-11'))->assertOk()
        ->assertSee('Online • Ruang dilepas')
        ->assertSee('Statistika')
        ->assertSee('Kembalikan ke luring');
});

test('Keti dapat menyelesaikan kelas yang sedang berlangsung lebih awal dan melepas ruangan', function () {
    $admin = User::factory()->admin()->create();
    $otherKeti = User::factory()->approvedKeti(ClassGroup::factory()->create(['name' => 'Tekom A']))->create();
    $this->travelTo(CarbonImmutable::parse('2026-11-09 08:30'));

    $this->actingAs($this->keti)->get(myClassUrl('2026-11-09'))->assertOk()
        ->assertSee('Sedang berlangsung')
        ->assertSee('Selesaikan kelas')
        ->assertSee('Selesaikan Perkuliahan Sekarang?')
        ->assertSee('Ya, Selesaikan &amp; Kosongkan Ruang', false);

    expect(app(AvailabilityService::class)->forRoom($this->ae107, now())->status)->toBe(RoomStatus::Scheduled);

    $this->actingAs($this->keti)->post(route('keti.meetings.finish'), [
        'session_id' => $this->session->id,
        'original_date' => '2026-11-09',
    ])->assertRedirect(myClassUrl('2026-11-09'))
        ->assertSessionHas('toast.type', 'success');

    $block = RoomBlock::query()
        ->where('source_type', RoomBlockSource::Schedule)
        ->where('source_id', $this->session->id)
        ->whereDate('start_at', '2026-11-09')
        ->sole();

    expect($block->end_at->format('Y-m-d H:i:s'))->toBe('2026-11-09 08:30:00')
        ->and(AuditLog::query()->where('action', 'meeting.finished_early')->exists())->toBeTrue()
        ->and(AppNotification::query()->whereBelongsTo($admin)->where('type', 'meeting.finished_early')->exists())->toBeTrue();

    $availability = app(AvailabilityService::class)->forRoom($this->ae107, now());
    expect($availability->status)->toBe(RoomStatus::Available)
        ->and($availability->badge())->toBe('Tersedia');

    $booking = app(ReservationService::class)->create(
        $otherKeti,
        $this->ae107,
        CarbonImmutable::parse('2026-11-09 08:30'),
        CarbonImmutable::parse('2026-11-09 10:00'),
        'Diskusi / Kerja Kelompok',
        20,
        (string) str()->uuid(),
    );
    expect($booking->exists)->toBeTrue();

    $ownClassBooking = app(ReservationService::class)->create(
        $this->keti,
        $this->ae209,
        CarbonImmutable::parse('2026-11-09 08:30'),
        CarbonImmutable::parse('2026-11-09 10:00'),
        'Diskusi / Kerja Kelompok',
        20,
        (string) str()->uuid(),
    );
    expect($ownClassBooking->exists)->toBeTrue();

    $this->actingAs($this->keti)->get(myClassUrl('2026-11-09'))->assertOk()
        ->assertSee('Selesai • Ruang dilepas')
        ->assertSee('Diselesaikan pukul 08:30 • Ruang AE 107 dilepas untuk kelas lain')
        ->assertDontSee('Selesaikan kelas');

    $this->actingAs($this->keti)->postJson(route('keti.meetings.finish'), [
        'session_id' => $this->session->id,
        'original_date' => '2026-11-09',
    ])->assertConflict()->assertJsonPath('code', 'MEETING_ALREADY_FINISHED');
});

test('ScheduleBlockBuilder rebuild mempertahankan waktu selesai kelas yang dipendekkan', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-09 08:45'));

    $this->actingAs($this->keti)->post(route('keti.meetings.finish'), [
        'session_id' => $this->session->id,
        'original_date' => '2026-11-09',
    ])->assertRedirect(myClassUrl('2026-11-09'));

    app(ScheduleBlockBuilder::class)->rebuild($this->session->version->fresh());

    $shortenedBlock = RoomBlock::query()
        ->where('source_type', RoomBlockSource::Schedule)
        ->where('source_id', $this->session->id)
        ->whereDate('start_at', '2026-11-09')
        ->sole();

    $nextWeekBlock = RoomBlock::query()
        ->where('source_type', RoomBlockSource::Schedule)
        ->where('source_id', $this->session->id)
        ->whereDate('start_at', '2026-11-16')
        ->sole();

    expect($shortenedBlock->end_at->format('Y-m-d H:i:s'))->toBe('2026-11-09 08:45:00')
        ->and($nextWeekBlock->end_at->format('Y-m-d H:i:s'))->toBe('2026-11-16 10:00:00');
});

test('menyelesaikan kelas yang belum dimulai atau milik kelas lain ditolak', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-09 07:00'));

    $this->actingAs($this->keti)
        ->from(myClassUrl('2026-11-09'))
        ->post(route('keti.meetings.finish'), [
            'session_id' => $this->session->id,
            'original_date' => '2026-11-09',
        ])
        ->assertRedirect(myClassUrl('2026-11-09'))
        ->assertSessionHas('toast.type', 'error');

    $this->actingAs($this->keti)
        ->postJson(route('keti.meetings.finish'), [
            'session_id' => $this->session->id,
            'original_date' => '2026-11-09',
        ])
        ->assertConflict()
        ->assertJsonPath('code', 'MEETING_NOT_STARTED');

    $this->travelTo(CarbonImmutable::parse('2026-11-09 08:30'));
    $otherKeti = User::factory()->approvedKeti(ClassGroup::factory()->create(['name' => 'Tekom A']))->create();

    $this->actingAs($otherKeti)
        ->from(myClassUrl('2026-11-09'))
        ->post(route('keti.meetings.finish'), [
            'session_id' => $this->session->id,
            'original_date' => '2026-11-09',
        ])
        ->assertRedirect(myClassUrl('2026-11-09'))
        ->assertSessionHas('toast.type', 'error');

    $this->actingAs($otherKeti)
        ->postJson(route('keti.meetings.finish'), [
            'session_id' => $this->session->id,
            'original_date' => '2026-11-09',
        ])
        ->assertConflict()
        ->assertJsonPath('code', 'NOT_CLASS_REPRESENTATIVE');

    $block = RoomBlock::query()
        ->where('source_type', RoomBlockSource::Schedule)
        ->where('source_id', $this->session->id)
        ->whereDate('start_at', '2026-11-09')
        ->sole();

    expect($block->end_at->format('Y-m-d H:i:s'))->toBe('2026-11-09 10:00:00');
});

test('My Class menampilkan tombol Selesai menggunakan pada Kuliah Pengganti dan Pesanan kelas yang sedang digunakan', function () {
    $this->actingAs($this->keti)->post(route('keti.meetings.reschedule.store'), movePayload());
    $replacement = SessionReschedule::query()->sole()->reservation;

    $reservation = app(ReservationService::class)->create(
        $this->keti,
        $this->ae107,
        CarbonImmutable::parse('2026-11-10 13:00'),
        CarbonImmutable::parse('2026-11-10 14:30'),
        'Diskusi / Kerja Kelompok',
        15,
        (string) str()->uuid(),
    );

    $this->travelTo(CarbonImmutable::parse('2026-11-10 10:05'));
    app(ReservationService::class)->start($replacement, $this->keti);

    $this->travelTo(CarbonImmutable::parse('2026-11-10 13:05'));
    app(ReservationService::class)->start($reservation, $this->keti);

    $content = $this->actingAs($this->keti)->get(myClassUrl('2026-11-10'))
        ->assertOk()
        ->assertSee(route('keti.reservations.finish', $replacement))
        ->assertSee(route('keti.reservations.finish', $reservation))
        ->assertSee('Selesai menggunakan')
        ->getContent();

    expect($content)->not->toMatch('/<a\b[^>]*>(?:(?!<\/a>).)*<form\b/s');
});
