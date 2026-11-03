<?php

use App\Models\Building;
use App\Models\ClassGroup;
use App\Models\Room;
use App\Models\User;
use App\Services\AvailabilityService;
use App\Services\ReservationService;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00'));
    officialVersion();
});

test('beranda menampilkan jumlah ruang tersedia dan pratinjau tiga ruang', function () {
    Room::factory()->count(4)->create();
    $busy = Room::factory()->create(['name' => 'AE 103']);
    scheduleBlock($busy, '2026-11-04 09:10', '2026-11-04 11:40');

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('Layanan ruang kelas JTIK')
        ->assertSee('4 ruang tersedia sekarang')
        ->assertSee('Pratinjau 3 dari 4 ruang tersedia')
        ->assertSee('Data simulasi')
        ->assertSee('4 Nov 2026 • 10.00 WITA')
        ->assertDontSee('AE 103');
});

test('halaman ruangan memakai judul dinamis sesuai filter', function (array $query, string $title) {
    $ae = Building::factory()->create(['name' => 'AE']);
    Room::factory()->for($ae)->create(['name' => 'AE 107']);
    Room::factory()->withoutBuilding()->create(['name' => 'Lab ICT 10']);
    $query = array_map(fn ($value) => $value === ':ae' ? $ae->id : $value, $query);

    $this->get(route('public.rooms', $query))->assertOk()->assertSee($title);
})->with([
    'semua' => [[], 'Semua ruangan JTIK'],
    'tersedia' => [['status' => 'tersedia'], 'Ruangan tersedia saat ini'],
    'digunakan' => [['status' => 'digunakan'], 'Ruangan sedang digunakan'],
    'gedung AE' => [['gedung' => ':ae'], 'Ruangan di gedung AE'],
    'tanpa gedung' => [['gedung' => 'lainnya'], 'Ruangan lainnya'],
    'pencarian' => [['q' => 'AE 107'], 'Hasil pencarian “AE 107”'],
]);

test('pencarian tanpa hasil menampilkan "Ruangan tidak ditemukan"', function () {
    Room::factory()->create(['name' => 'AE 107']);

    $this->get(route('public.rooms', ['q' => 'AE 999']))
        ->assertSee('0 ruang ditemukan • Semua gedung')
        ->assertSee('Ruangan tidak ditemukan')
        ->assertSee('Tampilkan semua ruangan');
});

test('tab ringkasan menghitung semua ruang di semua gedung', function () {
    Room::factory()->count(2)->create();
    $busy = Room::factory()->create();
    scheduleBlock($busy, '2026-11-04 09:00', '2026-11-04 11:00');

    $this->get(route('public.rooms', ['status' => 'tersedia']))
        ->assertSee('Semua 3')
        ->assertSee('Tersedia 2')
        ->assertSee('Digunakan 1')
        ->assertSee('2 ruang ditemukan');
});

test('portal publik tidak menampilkan data pribadi pemesan', function () {
    $classGroup = ClassGroup::factory()->create(['name' => 'Kelas Rahasia']);
    $keti = User::factory()->approvedKeti($classGroup)->create([
        'name' => 'Nama Pemesan Unik',
        'nim' => '2509999999',
        'email' => 'pemesan.unik@sicma.test',
    ]);
    $room = Room::factory()->create();
    app(ReservationService::class)->create($keti, $room, now(), now()->addHour(), 'Rapat kelas rahasia', 10, 'kunci');

    foreach ([route('home'), route('public.rooms'), route('public.rooms', ['status' => 'digunakan'])] as $url) {
        $this->get($url)
            ->assertOk()
            ->assertDontSee('Nama Pemesan Unik')
            ->assertDontSee('2509999999')
            ->assertDontSee('pemesan.unik@sicma.test')
            ->assertDontSee('Kelas Rahasia')
            ->assertDontSee('Rapat kelas rahasia');
    }
});

test('status di portal publik sama dengan AvailabilityService', function () {
    $rooms = Room::factory()->count(3)->create();
    scheduleBlock($rooms[1], '2026-11-04 09:00', '2026-11-04 12:00');

    $response = $this->get(route('public.rooms'));

    foreach (app(AvailabilityService::class)->forRooms($rooms, now()) as $result) {
        $response->assertSeeInOrder([$result->room->name, $result->badge()]);
    }
});

test('reservasi baru langsung terlihat meski halaman publik di-cache', function () {
    $room = Room::factory()->create(['name' => 'Ruang Uji Cache']);
    $this->get(route('public.rooms', ['status' => 'digunakan']))->assertDontSee('Ruang Uji Cache');

    app(ReservationService::class)->create(User::factory()->approvedKeti()->create(), $room, now(), now()->addHour(), 'Kerja kelompok', 10, 'kunci-cache');

    $this->get(route('public.rooms', ['status' => 'digunakan']))->assertSee('Ruang Uji Cache');
});

test('halaman panduan dan bantuan tampil', function () {
    $this->get(route('public.guide'))->assertOk()->assertSee('Panduan menggunakan SICMA')->assertSee('3. Pesan dan gunakan');
    $this->get(route('public.help'))->assertOk()->assertSee('Bantuan akun dan penggunaan')->assertSee('Apakah status ruang selalu pasti?');
});
