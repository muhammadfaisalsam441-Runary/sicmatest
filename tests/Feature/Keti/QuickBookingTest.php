<?php

use App\Enums\ReservationStatus;
use App\Models\Academic\ClassGroup;
use App\Models\Reservation\Reservation;
use App\Models\Room\Room;
use App\Models\User\User;
use App\Services\Room\ReservationService;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00'));
    officialVersion();
    $this->classGroup = ClassGroup::factory()->create(['name' => 'Tekom C']);
    $this->keti = User::factory()->approvedKeti($this->classGroup)->create();
});

/**
 * Ambil payload tombol "Pesan" dari HTML: JSON.parse('...') di atribut Alpine.
 *
 * @return list<array<string, mixed>>
 */
function bookingPayloads(string $html, string $prefix = "open-booking', "): array
{
    preg_match_all('/'.preg_quote($prefix, '/')."JSON\\.parse\\('([^']+)'\\)/", html_entity_decode($html, ENT_QUOTES), $matches);

    return array_map(fn (string $inner): array => json_decode(json_decode('"'.$inner.'"'), true), $matches[1]);
}

test('Cari Ruangan memesan langsung dari kartu tanpa tab Filter', function () {
    $room = Room::factory()->create(['name' => 'AE 107', 'capacity' => 40]);

    $response = $this->actingAs($this->keti)->get(route('keti.search'))->assertOk()
        ->assertSee('name="tanggal"', false)->assertSee('name="durasi"', false)->assertSee('name="orang"', false)
        ->assertDontSee('tab=filter', false)
        ->assertSee('open-booking', false)
        ->assertSee('Pesan sekarang')
        ->assertSee('Detail ruangan');

    // Payload tombol Pesan membawa ruang, jam, dan durasi bawaan 2 SKS.
    $payload = bookingPayloads($response->getContent())[0];
    expect($payload['roomId'])->toBe($room->id)
        ->and($payload['duration'])->toBe(100)
        ->and($payload['startAt'])->toBe('2026-11-04 10:00')
        ->and($payload['capacity'])->toBe(40);
});

test('kegiatan bawaan modal mengikuti kegiatan terakhir kelas', function () {
    Room::factory()->create();
    Reservation::factory()->create([
        'class_group_id' => $this->classGroup->id, 'created_by' => $this->keti->id,
        'purpose' => 'Seminar / Ujian', 'status' => ReservationStatus::Finished,
        'start_at' => '2026-11-03 10:00', 'end_at' => '2026-11-03 11:40',
    ]);

    $content = $this->actingAs($this->keti)->get(route('keti.search'))->assertOk()->getContent();

    expect($content)->toMatch('/<option value="Seminar \/ Ujian"[^>]*selected/');
});

test('pencarian akhir pekan menonaktifkan kegiatan akademik di modal', function () {
    Room::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-11-06 10:00'));

    $content = $this->actingAs($this->keti)->get(route('keti.search', ['tanggal' => '2026-11-07', 'mulai' => '10:00']))->assertOk()->getContent();

    expect($content)->toMatch('/<option value="Praktikum Tambahan"[^>]*disabled/')
        ->and($content)->toMatch('/<option value="Diskusi \/ Kerja Kelompok"[^>]*selected/');
});

test('pemesanan gagal menyarankan ruang lain yang kosong di jam yang sama', function () {
    $taken = Room::factory()->create(['name' => 'AE 107']);
    Room::factory()->create(['name' => 'AE 209']);
    $busy = Room::factory()->create(['name' => 'AE 215']);
    scheduleBlock($busy, '2026-11-04 10:30', '2026-11-04 12:00');
    app(ReservationService::class)->create(User::factory()->approvedKeti()->create(), $taken, now(), now()->addMinutes(100), 'Rapat', null, (string) str()->uuid());

    $this->actingAs($this->keti)->post(route('keti.reservations.store'), [
        'room_id' => $taken->id, 'start_at' => '2026-11-04 10:00', 'duration' => 100,
        'purpose' => 'Diskusi / Kerja Kelompok', 'idempotency_key' => (string) str()->uuid(),
    ])->assertRedirect(route('keti.reservations.failed'));

    $response = $this->get(route('keti.reservations.failed'))->assertOk()
        ->assertSee('Ruangan gagal dipesan')
        ->assertSee('Ruangan lain yang kosong di jam yang sama')
        ->assertSee('AE 209')
        ->assertDontSee('AE 215')
        ->assertSee('Pesan sekarang');

    $suggested = collect(bookingPayloads($response->getContent()));
    expect($suggested->pluck('roomName')->all())->toBe(['AE 209'])
        ->and($suggested->first()['startAt'])->toBe('2026-11-04 10:00');
});

test('kalender membuka modal pemesanan dari slot tanpa ke halaman Detail', function () {
    $room = Room::factory()->create(['name' => 'Lab ICT 4']);

    $response = $this->actingAs($this->keti)->get(route('keti.rooms.calendar', ['tanggal' => '2026-11-05', 'durasi' => 100]))->assertOk()
        ->assertSee("\$dispatch('open-booking', payloads[start])", false)
        ->assertSee('Pesan sekarang');

    $slots = collect(bookingPayloads($response->getContent(), 'payloads: ')[0]);
    expect($slots->first()['roomId'])->toBe($room->id)
        ->and($slots->first()['duration'])->toBe(100);
});

test('setiap pembukaan modal memakai idempotency key baru dari browser', function () {
    Room::factory()->create();

    $this->actingAs($this->keti)->get(route('keti.search'))->assertOk()
        ->assertSee('x-bind:value="key"', false)
        ->assertSee('bookingModal(', false);
});

test('booking biasa menolak kegiatan Kuliah Pengganti', function (string $purpose) {
    $room = Room::factory()->create();

    $this->actingAs($this->keti)->post(route('keti.reservations.store'), [
        'room_id' => $room->id, 'start_at' => '2026-11-04 13:00', 'duration' => 100,
        'purpose' => $purpose, 'idempotency_key' => (string) str()->uuid(),
    ])->assertSessionHasErrors(['purpose' => 'Untuk mengganti jadwal kuliah, gunakan Pindahkan di My Class.']);

    expect(Reservation::query()->count())->toBe(0);
})->with(['Kuliah Pengganti', ' kuliah  pengganti ']);

test('modal booking tidak lagi menawarkan Kuliah Pengganti', function () {
    Room::factory()->create();

    $content = $this->actingAs($this->keti)->get(route('keti.search'))->assertOk()->getContent();

    expect($content)->not->toContain('<option value="Kuliah Pengganti"')
        ->and($content)->toMatch('/<option value="Diskusi \/ Kerja Kelompok"[^>]*selected/');
});
