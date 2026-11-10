<?php

use App\Exceptions\DomainException;
use App\Models\Room\Room;
use App\Models\Room\RoomClosure;
use App\Models\User\User;
use App\Services\Room\ReservationService;
use App\Services\Room\RoomClosureService;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 08:00'));
    officialVersion();
});

function conflictMessage(Room $room, string $start = '2026-11-04 10:00', string $end = '2026-11-04 11:00'): DomainException
{
    try {
        app(ReservationService::class)->create(
            User::factory()->approvedKeti()->create(), $room, CarbonImmutable::parse($start), CarbonImmutable::parse($end), 'Kerja kelompok', null, (string) str()->uuid(),
        );
    } catch (DomainException $exception) {
        return $exception;
    }

    test()->fail('Pemesanan seharusnya ditolak.');
}

test('bentrok dengan kuliah terjadwal menyebut kuliah dan jamnya', function () {
    $room = Room::factory()->create();
    scheduleBlock($room, '2026-11-04 09:15', '2026-11-04 10:55');

    $exception = conflictMessage($room);

    expect($exception->errorCode)->toBe('ROOM_CONFLICT')
        ->and($exception->getMessage())->toContain('Ada kuliah terjadwal')->toContain('09:15–10:55')
        ->and($exception->details['source'])->toBe('schedule');
});

test('bentrok dengan reservasi kelas lain menyebut jam reservasinya', function () {
    $room = Room::factory()->create();
    app(ReservationService::class)->create(
        User::factory()->approvedKeti()->create(), $room, CarbonImmutable::parse('2026-11-04 10:30'), CarbonImmutable::parse('2026-11-04 11:30'), 'Rapat', null, (string) str()->uuid(),
    );

    $exception = conflictMessage($room);

    expect($exception->errorCode)->toBe('ROOM_CONFLICT')
        ->and($exception->getMessage())->toContain('dipesan kelas lain')->toContain('10:30–11:30')
        ->and($exception->details['source'])->toBe('reservation');
});

test('ruang yang ditutup menyebut kapan dibuka kembali', function () {
    $room = Room::factory()->create();
    $admin = User::factory()->admin()->create();
    app(RoomClosureService::class)->create($room, CarbonImmutable::parse('2026-11-04 09:00'), CarbonImmutable::parse('2026-11-04 12:00'), 'Perbaikan AC', $admin);

    $exception = conflictMessage($room);

    expect($exception->errorCode)->toBe('ROOM_CLOSED')
        ->and($exception->getMessage())->toContain('ditutup sampai')->toContain('12.00');
    expect(RoomClosure::query()->count())->toBe(1);
});
