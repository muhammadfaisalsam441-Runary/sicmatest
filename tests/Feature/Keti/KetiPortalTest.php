<?php

use App\Enums\ReservationStatus;
use App\Models\Academic\ClassGroup;
use App\Models\Reservation\Reservation;
use App\Models\Room\Room;
use App\Models\Scheduling\ScheduleSession;
use App\Models\User\AppNotification;
use App\Models\User\User;
use App\Services\Room\ReservationService;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00'));
    officialVersion();
    $this->classGroup = ClassGroup::factory()->create(['name' => 'Tekom C']);
    $this->keti = User::factory()->approvedKeti($this->classGroup)->create(['name' => 'Dimas Pratama']);
});

function bookingPayload(Room $room, array $overrides = []): array
{
    return [
        'room_id' => $room->id,
        'start_at' => '2026-11-04 10:00',
        'duration' => 60,
        'purpose' => 'Kerja kelompok',
        'participants' => 20,
        'idempotency_key' => (string) str()->uuid(),
        ...$overrides,
    ];
}

test('semua halaman Portal Keti tampil untuk Keti yang disetujui', function (string $routeName, string $text) {
    $room = Room::factory()->create();

    $this->actingAs($this->keti)
        ->get(route($routeName, $routeName === 'keti.rooms.show' ? $room : []))
        ->assertOk()
        ->assertSee($text)
        ->assertSee('Keti • Tekom C');
})->with([
    ['keti.search', 'Temukan ruangan kosong yang sesuai untuk kelasmu.'],
    ['keti.my-class', 'Kelas Tekom C'],
    ['keti.rooms.index', 'Daftar ruangan'],
    ['keti.rooms.show', 'Gunakan untuk kelasmu'],
    ['keti.favorites.index', 'Belum ada ruangan favorit'],
    ['keti.activity', 'Belum ada aktivitas'],
    ['keti.notifications', 'Belum ada notifikasi'],
    ['keti.profile', 'Profil Pengguna'],
]);

test('cari ruangan hanya menawarkan ruang yang bebas sepanjang durasi', function () {
    $free = Room::factory()->create(['name' => 'AE 107']);
    $soonBusy = Room::factory()->create(['name' => 'AE 101']);
    scheduleBlock($soonBusy, '2026-11-04 10:30', '2026-11-04 12:00');

    $this->actingAs($this->keti)
        ->get(route('keti.search', ['durasi' => 60]))
        ->assertSee('1 ruangan tersedia')
        ->assertSee('AE 107')
        ->assertDontSee('AE 101')
        ->assertSee('Untuk 1 jam • Semua gedung • Waktu terlama');
});

test('cari ruangan tanpa hasil menampilkan kondisi kosong', function () {
    $this->actingAs($this->keti)
        ->get(route('keti.search', ['q' => 'tidak-ada']))
        ->assertSee('Tidak ada ruangan yang cocok')
        ->assertSee('Ubah filter');
});

test('My Class menampilkan jadwal resmi kelas pada tanggal terpilih', function () {
    $room = Room::factory()->create(['name' => 'AE 101']);
    $session = ScheduleSession::factory()->for(officialVersion(), 'version')->for($room)->create([
        'title' => 'Matematika Diskrit', 'day_of_week' => 3, 'start_minute' => 960, 'end_minute' => 1060,
    ]);
    $session->participants()->attach($this->classGroup);

    $this->actingAs($this->keti)->get(route('keti.my-class'))
        ->assertSee('1 jadwal')
        ->assertSee('Matematika Diskrit')
        ->assertSee('16:00–17:40 WITA');

    $this->actingAs($this->keti)->get(route('keti.my-class', ['tanggal' => '2026-11-05']))
        ->assertSee('Belum ada jadwal');
});

test('kelas pemesan diambil dari akun, bukan dari request', function () {
    $otherClass = ClassGroup::factory()->create();
    $room = Room::factory()->create();

    $response = $this->actingAs($this->keti)->post(route('keti.reservations.store'), bookingPayload($room, ['class_group_id' => $otherClass->id]));

    $reservation = Reservation::query()->sole();
    $response->assertRedirect(route('keti.reservations.show', $reservation));
    expect($reservation->class_group_id)->toBe($this->classGroup->id);
});

test('pemesanan lewat fetch mengembalikan tujuan detail reservasi', function () {
    $room = Room::factory()->create();

    $response = $this->actingAs($this->keti)
        ->postJson(route('keti.reservations.store'), bookingPayload($room))
        ->assertCreated();

    $reservation = Reservation::query()->sole();
    $response->assertJsonPath('redirect_url', route('keti.reservations.show', $reservation))
        ->assertJsonPath('reservation_id', $reservation->id);
});

test('kirim ulang formulir yang sama tidak menggandakan reservasi', function () {
    $room = Room::factory()->create();
    $payload = bookingPayload($room);

    $this->actingAs($this->keti)->post(route('keti.reservations.store'), $payload);
    $this->actingAs($this->keti)->post(route('keti.reservations.store'), $payload);

    expect(Reservation::query()->count())->toBe(1);
});

test('ruang yang sudah dipesan kelas lain menampilkan "Pemesanan gagal"', function () {
    $room = Room::factory()->create();
    app(ReservationService::class)->create(User::factory()->approvedKeti()->create(), $room, now(), now()->addHour(), 'Rapat', 10, 'kunci-lain');

    $this->actingAs($this->keti)
        ->post(route('keti.reservations.store'), bookingPayload($room))
        ->assertRedirect(route('keti.reservations.failed'))
        ->assertSessionHas('reservation_error.code', 'ROOM_CONFLICT');

    $this->actingAs($this->keti)->withSession(['reservation_error' => ['code' => 'ROOM_CONFLICT', 'message' => 'Ruangan sudah lebih dulu dipesan kelas lain untuk jam tersebut.']])
        ->get(route('keti.reservations.failed'))
        ->assertSee('Ruangan gagal dipesan')
        ->assertSee('Cari ruangan lain');
});

test('alur Dipesan → Mulai gunakan → Selesai menggunakan', function () {
    $room = Room::factory()->create(['name' => 'AE 107']);
    $this->actingAs($this->keti)->post(route('keti.reservations.store'), bookingPayload($room));
    $reservation = Reservation::query()->sole();

    $this->actingAs($this->keti)->get(route('keti.reservations.show', $reservation))->assertSee('Ruangan berhasil dipesan')->assertSee('Mulai gunakan');
    $this->actingAs($this->keti)->post(route('keti.reservations.start', $reservation))->assertRedirect(route('keti.reservations.show', $reservation));
    $this->actingAs($this->keti)->get(route('keti.reservations.show', $reservation))->assertSee('Sedang menggunakan ruangan')->assertSee('Sisa waktu penggunaan');

    $this->travel(20)->minutes();
    $this->actingAs($this->keti)->post(route('keti.reservations.finish', $reservation));
    $this->actingAs($this->keti)->get(route('keti.reservations.show', $reservation))->assertSee('Penggunaan selesai')->assertSee('Riwayat penggunaan terbaru');

    expect($reservation->fresh()->status)->toBe(ReservationStatus::Finished);
});

test('Keti bisa membatalkan pesanan berstatus Dipesan', function () {
    $room = Room::factory()->create();
    $this->actingAs($this->keti)->post(route('keti.reservations.store'), bookingPayload($room, ['start_at' => '2026-11-04 13:00']));
    $reservation = Reservation::query()->sole();

    $this->actingAs($this->keti)->post(route('keti.reservations.cancel', $reservation), ['reason' => 'Tidak jadi']);

    expect($reservation->fresh()->status)->toBe(ReservationStatus::Cancelled);
    $this->actingAs($this->keti)->get(route('keti.reservations.show', $reservation))->assertSee('Pesanan dibatalkan');
});

test('Keti kelas lain tidak bisa membuka atau mengelola reservasi', function () {
    $reservation = app(ReservationService::class)->create($this->keti, Room::factory()->create(), now(), now()->addHour(), 'Rapat', 10, 'kunci');
    $other = User::factory()->approvedKeti()->create();

    $this->actingAs($other)->get(route('keti.reservations.show', $reservation))->assertForbidden();
    $this->actingAs($other)->post(route('keti.reservations.cancel', $reservation))->assertForbidden();
    $this->actingAs($other)->get(route('keti.reservations.status', $reservation))->assertForbidden();

    expect($reservation->fresh()->status)->toBe(ReservationStatus::Booked);
});

test('perpanjang 30 menit hanya bila tidak bentrok', function () {
    $room = Room::factory()->create();
    $service = app(ReservationService::class);
    $reservation = $service->create($this->keti, $room, now(), now()->addHour(), 'Rapat', 10, 'kunci');
    $service->start($reservation, $this->keti);

    $this->actingAs($this->keti)->post(route('keti.reservations.extend', $reservation));
    expect($reservation->fresh()->end_at->format('H:i'))->toBe('11:30')
        ->and($reservation->fresh()->block->end_at->format('H:i'))->toBe('11:30');

    scheduleBlock($room, '2026-11-04 11:40', '2026-11-04 13:00');
    $this->actingAs($this->keti)->from(route('keti.reservations.show', $reservation))
        ->post(route('keti.reservations.extend', $reservation))
        ->assertSessionHas('error_code', 'ROOM_CONFLICT');
    expect($reservation->fresh()->end_at->format('H:i'))->toBe('11:30');
});

test('lapor kendala diteruskan ke admin', function () {
    $admin = User::factory()->admin()->create();
    $service = app(ReservationService::class);
    $reservation = $service->create($this->keti, Room::factory()->create(['name' => 'AE 107']), now(), now()->addHour(), 'Rapat', 10, 'kunci');
    $service->start($reservation, $this->keti);

    $this->actingAs($this->keti)->post(route('keti.reservations.report', $reservation), ['issue' => 'AC tidak dingin']);

    expect(AppNotification::query()->whereBelongsTo($admin)->where('title', 'Laporan kendala AE 107')->exists())->toBeTrue();
});

test('favorit bisa disimpan dan dihapus', function () {
    $room = Room::factory()->create(['name' => 'AE 101']);

    $this->actingAs($this->keti)->put(route('keti.favorites.store', $room));
    $this->actingAs($this->keti)->get(route('keti.favorites.index'))->assertSee('1 ruangan tersimpan')->assertSee('AE 101');

    $this->actingAs($this->keti)->delete(route('keti.favorites.destroy', $room));
    expect($this->keti->favoriteRooms()->count())->toBe(0);
});

test('notifikasi ditandai dibaca dan tidak bisa dibuka pengguna lain', function () {
    $notification = AppNotification::create(['user_id' => $this->keti->id, 'type' => 'info', 'title' => 'Halo', 'body' => 'Isi', 'data' => []]);

    $this->actingAs(User::factory()->approvedKeti()->create())->post(route('keti.notifications.read', $notification))->assertForbidden();

    $this->actingAs($this->keti)->get(route('keti.notifications'))->assertSee('Belum dibaca • 1');
    $this->actingAs($this->keti)->post(route('keti.notifications.read-all'));
    expect($notification->fresh()->read_at)->not->toBeNull();
});

test('aktivitas menampilkan semua pengguna atau hanya kelas sendiri', function () {
    $service = app(ReservationService::class);
    $service->create($this->keti, Room::factory()->create(['name' => 'AE 107']), now(), now()->addHour(), 'Belajar kelompok', 10, 'k1');
    $service->create(User::factory()->approvedKeti(ClassGroup::factory()->create(['name' => 'PTIK B']))->create(['name' => 'Nama Pribadi Lain']), Room::factory()->create(['name' => 'AE 215']), now(), now()->addHour(), 'Praktikum', 10, 'k2');

    $this->actingAs($this->keti)->get(route('keti.activity'))
        ->assertSee('AE 107')->assertSee('AE 215')->assertSee('Keti PTIK B')->assertDontSee('Nama Pribadi Lain');

    $this->actingAs($this->keti)->get(route('keti.activity', ['cakupan' => 'saya']))
        ->assertSee('AE 107')->assertDontSee('AE 215');
});
