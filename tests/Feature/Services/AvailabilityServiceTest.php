<?php

use App\Enums\ReservationStatus;
use App\Enums\RoomBlockSource;
use App\Enums\RoomStatus;
use App\Models\Building;
use App\Models\Period;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\ScheduleVersion;
use App\Services\Availability\SearchCriteria;
use App\Services\AvailabilityService;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00'));
    officialVersion();
    $this->availability = app(AvailabilityService::class);
});

test('ruang kosong tersedia sampai agenda berikutnya', function () {
    $room = Room::factory()->create();
    scheduleBlock($room, '2026-11-04 13:00', '2026-11-04 14:40');

    $result = $this->availability->forRoom($room, now());

    expect($result->status)->toBe(RoomStatus::Available)
        ->and($result->badge())->toBe('Tersedia')
        ->and($result->freeUntil->format('H:i'))->toBe('13:00')
        ->and($result->minutesRemaining())->toBe(180);
});

test('ruang tanpa agenda tersedia sampai jam operasional berakhir', function () {
    $result = $this->availability->forRoom(Room::factory()->create(), now());

    expect($result->freeUntil->format('H:i'))->toBe('21:30');
});

test('kuliah resmi yang sedang berlangsung memblokir ruang meski belum check-in', function () {
    $room = Room::factory()->create();
    scheduleBlock($room, '2026-11-04 09:10', '2026-11-04 11:40');

    $result = $this->availability->forRoom($room, now());

    expect($result->status)->toBe(RoomStatus::Scheduled)
        ->and($result->badge())->toBe('Digunakan')
        ->and($result->busyUntil->format('H:i'))->toBe('11:40');
});

test('"Selesai pukul" mengikuti rangkaian agenda yang bersambung', function () {
    $room = Room::factory()->create();
    scheduleBlock($room, '2026-11-04 09:10', '2026-11-04 10:30');
    RoomBlock::factory()->for($room)->create(['start_at' => '2026-11-04 10:30', 'end_at' => '2026-11-04 11:30']);
    scheduleBlock($room, '2026-11-04 13:00', '2026-11-04 14:00');

    expect($this->availability->busyUntil($room, now())->format('H:i'))->toBe('11:30');
});

test('agenda yang berakhir 10.05 tidak membuat ruang terpakai pada 10.05', function () {
    $room = Room::factory()->create();
    scheduleBlock($room, '2026-11-04 08:15', '2026-11-04 10:05');

    expect($this->availability->forRoom($room, CarbonImmutable::parse('2026-11-04 10:05'))->status)->toBe(RoomStatus::Available)
        ->and($this->availability->forRoom($room, CarbonImmutable::parse('2026-11-04 10:04'))->status)->toBe(RoomStatus::Scheduled);
});

test('ruang kosong sekarang tetapi terpakai sebelum durasi selesai tidak ditawarkan', function () {
    $room = Room::factory()->create();
    scheduleBlock($room, '2026-11-04 10:30', '2026-11-04 12:00');

    $result = $this->availability->forRoom($room, now(), now()->addHour());

    expect($result->isAvailable())->toBeFalse()
        ->and($result->reason)->toBe('Kuliah terjadwal mulai 10:30 WITA');

    $found = $this->availability->search(new SearchCriteria(start: CarbonImmutable::now(), durationMinutes: 60, status: 'available'));
    expect($found->pluck('room.id'))->not->toContain($room->id);
});

test('blok jadwal draf tidak memengaruhi status ruang', function () {
    $room = Room::factory()->create();
    $draft = ScheduleVersion::factory()->for(Period::query()->first())->create();
    scheduleBlock($room, '2026-11-04 09:00', '2026-11-04 12:00', $draft);

    expect($this->availability->forRoom($room, now())->status)->toBe(RoomStatus::Available);
});

test('status mengikuti sumber blok', function (string $kind, RoomStatus $expected) {
    $room = Room::factory()->create();
    $block = ['start_at' => '2026-11-04 09:00', 'end_at' => '2026-11-04 11:00'];

    match ($kind) {
        'penutupan' => RoomBlock::factory()->for($room)->create([...$block, 'source_type' => RoomBlockSource::Closure]),
        'dipesan', 'dipakai', 'dibatalkan' => (function () use ($room, $block, $kind) {
            $reservation = Reservation::factory()->for($room)->create([...$block, 'status' => match ($kind) {
                'dipesan' => ReservationStatus::Booked,
                'dipakai' => ReservationStatus::InUse,
                'dibatalkan' => ReservationStatus::Cancelled,
            }]);
            RoomBlock::factory()->for($room)->create([...$block, 'source_id' => $reservation->id, 'is_active' => $kind !== 'dibatalkan']);
        })(),
    };

    expect($this->availability->forRoom($room, now())->status)->toBe($expected);
})->with([
    ['penutupan', RoomStatus::Closed],
    ['dipesan', RoomStatus::Booked],
    ['dipakai', RoomStatus::InUse],
    ['dibatalkan', RoomStatus::Available],
]);

test('di luar jam operasional ruang tidak tersedia', function () {
    $result = $this->availability->forRoom(Room::factory()->create(), CarbonImmutable::parse('2026-11-04 22:00'));

    expect($result->status)->toBe(RoomStatus::Closed)
        ->and($result->reason)->toContain('Di luar jam operasional');
});

test('periode tanpa jadwal resmi membuat status ruang belum pasti', function () {
    ScheduleVersion::query()->update(['status' => 'draft']);

    expect($this->availability->forRoom(Room::factory()->create(), now())->status)->toBe(RoomStatus::Uncertain);
});

test('ringkasan menghitung semua, tersedia, dan digunakan', function () {
    $busy = Room::factory()->create();
    scheduleBlock($busy, '2026-11-04 09:00', '2026-11-04 11:00');
    Room::factory()->count(2)->create();
    Room::factory()->create(['is_active' => false]);

    expect($this->availability->summary(now()))->toMatchArray(['all' => 3, 'available' => 2, 'busy' => 1]);
});

test('pencarian menyaring kata kunci, gedung, kapasitas, dan urutan waktu terlama', function () {
    $ae = Building::factory()->create(['name' => 'AE']);
    $ae107 = Room::factory()->for($ae)->create(['name' => 'AE 107', 'capacity' => 45]);
    $ae101 = Room::factory()->for($ae)->create(['name' => 'AE 101', 'capacity' => 35]);
    $lab = Room::factory()->withoutBuilding()->create(['name' => 'Lab ICT 10', 'capacity' => null]);
    scheduleBlock($ae101, '2026-11-04 12:00', '2026-11-04 13:00');

    $search = fn (array $args) => $this->availability->search(new SearchCriteria(...['start' => CarbonImmutable::now(), ...$args]))->map(fn ($result) => $result->room->name)->all();

    expect($search(['keyword' => 'AE']))->toBe(['AE 107', 'AE 101'])
        ->and($search(['building' => 'none']))->toBe(['Lab ICT 10'])
        ->and($search(['minCapacity' => 40]))->toBe(['AE 107'])
        ->and($search(['building' => $ae->id, 'sort' => SearchCriteria::SORT_NAME]))->toBe(['AE 101', 'AE 107']);
});
