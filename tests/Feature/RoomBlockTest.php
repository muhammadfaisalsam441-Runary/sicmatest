<?php

use App\Models\Room\Room;
use App\Models\Room\RoomBlock;
use Carbon\CarbonImmutable;

function blockFrom(Room $room, string $start, string $end): RoomBlock
{
    return RoomBlock::factory()->for($room)->create([
        'start_at' => CarbonImmutable::parse($start),
        'end_at' => CarbonImmutable::parse($end),
    ]);
}

test('interval setengah terbuka: blok yang berakhir 10.05 tidak bentrok dengan mulai 10.05', function () {
    $room = Room::factory()->create();
    blockFrom($room, '2026-11-04 08:15', '2026-11-04 10:05');

    $overlaps = RoomBlock::query()->whereBelongsTo($room)->active()
        ->overlapping(CarbonImmutable::parse('2026-11-04 10:05'), CarbonImmutable::parse('2026-11-04 11:00'))
        ->exists();

    expect($overlaps)->toBeFalse();
});

test('interval yang beririsan satu menit dianggap bentrok', function (string $start, string $end) {
    $room = Room::factory()->create();
    blockFrom($room, '2026-11-04 08:15', '2026-11-04 10:05');

    $overlaps = RoomBlock::query()->whereBelongsTo($room)->active()
        ->overlapping(CarbonImmutable::parse($start), CarbonImmutable::parse($end))
        ->exists();

    expect($overlaps)->toBeTrue();
})->with([
    'mulai sebelum blok selesai' => ['2026-11-04 10:04', '2026-11-04 11:00'],
    'selesai setelah blok mulai' => ['2026-11-04 07:00', '2026-11-04 08:16'],
    'di dalam blok' => ['2026-11-04 09:00', '2026-11-04 09:30'],
    'menutupi blok' => ['2026-11-04 07:00', '2026-11-04 12:00'],
]);

test('blok yang sudah tidak aktif tidak dihitung', function () {
    $room = Room::factory()->create();
    RoomBlock::factory()->for($room)->inactive()->create([
        'start_at' => CarbonImmutable::parse('2026-11-04 08:00'),
        'end_at' => CarbonImmutable::parse('2026-11-04 10:00'),
    ]);

    $overlaps = RoomBlock::query()->whereBelongsTo($room)->active()
        ->overlapping(CarbonImmutable::parse('2026-11-04 09:00'), CarbonImmutable::parse('2026-11-04 09:30'))
        ->exists();

    expect($overlaps)->toBeFalse();
});
