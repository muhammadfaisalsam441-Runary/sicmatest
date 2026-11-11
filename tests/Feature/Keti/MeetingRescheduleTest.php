<?php

use App\Enums\SessionMode;
use App\Enums\SessionPhase;
use App\Enums\TeachingRole;
use App\Models\Academic\ClassGroup;
use App\Models\Academic\SessionReschedule;
use App\Models\Lecturer\Lecturer;
use App\Models\Room\Room;
use App\Models\Scheduling\ScheduleSession;
use App\Models\User\User;
use App\Services\Room\ReservationService;
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

test('Keti kelas gabungan lain boleh melihat pengganti tetapi tidak mengelolanya', function () {
    $classA = ClassGroup::factory()->create(['name' => 'Tekom A']);
    $ketiA = User::factory()->approvedKeti($classA)->create();
    $joint = meetingSession([$this->classC, $classA], ['title' => 'Statistika', 'day_of_week' => 3, 'start_minute' => 780, 'end_minute' => 880, 'room_id' => $this->ae107->id], $this->team);
    $this->actingAs($this->keti)->post(route('keti.meetings.reschedule.store'), movePayload(['session_id' => $joint->id, 'original_date' => '2026-11-11', 'start_at' => '2026-11-12 13:00']));
    $reservationId = SessionReschedule::query()->sole()->reservation_id;

    $this->actingAs($ketiA)->get(route('keti.reservations.show', $reservationId))->assertOk()
        ->assertSee('Statistika')
        ->assertDontSee(route('keti.reservations.start', $reservationId))
        ->assertDontSee(route('keti.meetings.reschedule', ['sesi' => $joint->id, 'tanggal' => '2026-11-11', 'pengganti' => $reservationId]));
    $this->actingAs($ketiA)->post(route('keti.reservations.start', $reservationId))->assertForbidden();
    $this->actingAs($ketiA)->get(myClassUrl('2026-11-12'))->assertOk()->assertSee('Kuliah Pengganti');
});
