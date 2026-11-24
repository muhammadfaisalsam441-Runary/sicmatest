<?php

use App\Models\Academic\ClassGroup;
use App\Models\Room\Building;
use App\Models\Room\Room;
use App\Models\User\User;
use App\Services\Room\AvailabilityService;
use App\Services\Room\ReservationService;
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

    $this->get(route('home'))
        ->assertOk()
        ->assertDontSee('Nama Pemesan Unik')
        ->assertDontSee('2509999999')
        ->assertDontSee('pemesan.unik@sicma.test');

    foreach ([route('public.rooms'), route('public.rooms', ['status' => 'digunakan'])] as $url) {
        $this->get($url)
            ->assertOk()
            ->assertSee('Rapat kelas rahasia')
            ->assertSee('Kelas Rahasia')
            ->assertDontSee('Nama Pemesan Unik')
            ->assertDontSee('2509999999')
            ->assertDontSee('pemesan.unik@sicma.test');
    }
});

test('portal publik menampilkan mata kuliah dan kelas pada kartu ruang yang sedang digunakan jadwal resmi', function () {
    $room = Room::factory()->create(['name' => 'AE 107']);
    $classGroup = ClassGroup::factory()->create(['name' => 'TEKOM C 2025']);
    meetingSession([$classGroup], [
        'title' => 'Pemrograman Web',
        'room_id' => $room->id,
        'day_of_week' => 3,
        'start_minute' => 540,
        'end_minute' => 690,
    ]);

    foreach ([route('public.rooms'), route('public.rooms', ['status' => 'digunakan'])] as $url) {
        $this->get($url)
            ->assertOk()
            ->assertSeeInOrder(['AE 107', 'Digunakan', 'Pemrograman Web', 'TEKOM C 2025']);
    }
});

test('portal publik menampilkan jadwal terdekat saat tutup sebelum jam operasional', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 06:45'));
    $room = Room::factory()->create(['name' => 'AE 101']);
    $classGroup = ClassGroup::factory()->create(['name' => 'TEKOM C 2025']);
    meetingSession([$classGroup], [
        'title' => 'Basis Data',
        'room_id' => $room->id,
        'day_of_week' => 3,
        'start_minute' => 450,
        'end_minute' => 550,
    ]);

    foreach ([route('public.rooms'), route('public.rooms', ['status' => 'tutup'])] as $url) {
        $this->get($url)
            ->assertOk()
            ->assertSeeInOrder(['AE 101', 'Tutup', 'Jadwal terdekat', '07:30–09:10 WITA', 'Basis Data', 'TEKOM C 2025']);
    }
});

test('daftar ruangan mengurutkan ruang tersedia paling atas dan menampilkan jadwal berikutnya atau bebas jadwal', function () {
    $busyRoom = Room::factory()->create(['name' => 'AE 101']);
    $freeWithNext = Room::factory()->create(['name' => 'Lab ICT 3']);
    $freeAllDay = Room::factory()->create(['name' => 'Pasca 1']);
    $classGroup = ClassGroup::factory()->create(['name' => 'TEKOM C 2025']);

    meetingSession([$classGroup], [
        'title' => 'Sistem Operasi',
        'room_id' => $busyRoom->id,
        'day_of_week' => 3,
        'start_minute' => 540,
        'end_minute' => 660,
    ]);
    meetingSession([$classGroup], [
        'title' => 'Kewirausahaan',
        'room_id' => $freeWithNext->id,
        'day_of_week' => 3,
        'start_minute' => 780,
        'end_minute' => 880,
    ]);

    $this->get(route('public.rooms'))
        ->assertOk()
        ->assertSeeInOrder([
            'Pasca 1',
            'Tersedia',
            'Bebas jadwal hari ini',
            'Lab ICT 3',
            'Tersedia',
            'Jadwal berikutnya',
            '13:00–14:40 WITA',
            'Kewirausahaan',
            'TEKOM C 2025',
            'AE 101',
            'Digunakan',
            'Sistem Operasi',
        ]);
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

test('portal publik merender container splash screen 3D, tombol putar ulang, dan ikon merek baru', function () {
    $response = $this->get(route('home'))->assertOk();

    $response->assertSee('id="sicma-splash-root"', false)
        ->assertSee('data-splash-screen', false)
        ->assertSee('sicma-splash-3d.mp4', false)
        ->assertSee('sicma-splash-3d.webm', false)
        ->assertSee('sicma-splash-poster.webp', false)
        ->assertSee('data-replay-splash', false)
        ->assertSee('favicon.svg', false)
        ->assertSee('#00F0FF', false);
});

test('portal publik dan halaman masuk merender latar belakang ambient serta tombol jeda animasi tanpa aria-pressed', function () {
    Room::factory()->create(['name' => 'AE 107']);

    $home = $this->get(route('home'))->assertOk();
    $home->assertSee('data-ambient-backdrop="public"', false)
        ->assertSee('Space+Grotesk', false)
        ->assertSee('Plus+Jakarta+Sans', false)
        ->assertSee('data-prefetch', false)
        ->assertSee('data-room-card', false)
        ->assertSee('sicma-card-spotlight', false)
        ->assertSee('sicma-animated-btn', false)
        ->assertSee('sicma-animated-btn__circle', false)
        ->assertSee('data-toggle-bg-motion', false)
        ->assertDontSee('data-toggle-bg-motion" aria-pressed', false);

    $rooms = $this->get(route('public.rooms'))->assertOk();
    $rooms->assertSee('sicma-btn-outline', false);

    $login = $this->get(route('login'))->assertOk();
    $login->assertSee('data-ambient-backdrop="auth"', false)
        ->assertSee('data-ambient-backdrop="portal"', false)
        ->assertSee('sicma-btn-solid', false)
        ->assertSee('data-toggle-bg-motion', false);
});

test('portal publik dan halaman masuk merender viewport safe-area serta tata letak responsif mobile', function () {
    $home = $this->get(route('home'))->assertOk();
    $home->assertSee('viewport-fit=cover', false)
        ->assertSee('min-h-dvh', false)
        ->assertSee('sicma-safe-top', false)
        ->assertSee('sicma-safe-bottom', false);

    $login = $this->get(route('login'))->assertOk();
    $login->assertSee('viewport-fit=cover', false)
        ->assertSee('min-h-dvh', false)
        ->assertSee('grid-cols-1 lg:grid-cols-[41%_1fr]', false);
});
