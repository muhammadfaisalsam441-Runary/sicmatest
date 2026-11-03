<?php

use App\Enums\RoomBlockSource;
use App\Enums\SessionPhase;
use App\Models\CalendarException;
use App\Models\ClassGroup;
use App\Models\Period;
use App\Models\Room;
use App\Models\ScheduleSession;
use App\Models\ScheduleVersion;
use App\Services\ScheduleBlockBuilder;

function septemberPeriod(array $attributes = []): Period
{
    return Period::factory()->create([
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-30',
        'uts_start' => '2026-09-14',
        'uts_end' => '2026-09-18',
        ...$attributes,
    ]);
}

test('sesi luring diblok setiap minggu pada jam yang sama', function () {
    $period = septemberPeriod();
    $version = ScheduleVersion::factory()->official()->for($period)->create(['effective_from' => '2026-09-01']);
    $room = Room::factory()->create();
    ScheduleSession::factory()->for($version, 'version')->for($room)->create([
        'day_of_week' => 1,
        'start_minute' => 450,
        'end_minute' => 600,
    ]);

    $count = app(ScheduleBlockBuilder::class)->rebuild($version);

    expect($count)->toBe(4)
        ->and($room->blocks()->orderBy('start_at')->get()->map(fn ($block) => [
            $block->start_at->format('Y-m-d H:i'),
            $block->end_at->format('H:i'),
            $block->source_type,
        ])->all())->toBe([
            ['2026-09-07 07:30', '10:00', RoomBlockSource::Schedule],
            ['2026-09-14 07:30', '10:00', RoomBlockSource::Schedule],
            ['2026-09-21 07:30', '10:00', RoomBlockSource::Schedule],
            ['2026-09-28 07:30', '10:00', RoomBlockSource::Schedule],
        ]);
});

test('tanggal pengecualian kalender tidak diblok', function () {
    $period = septemberPeriod();
    CalendarException::factory()->for($period)->create(['date' => '2026-09-14']);
    $version = ScheduleVersion::factory()->official()->for($period)->create(['effective_from' => '2026-09-01']);
    $room = Room::factory()->create();
    ScheduleSession::factory()->for($version, 'version')->for($room)->create(['day_of_week' => 1]);

    app(ScheduleBlockBuilder::class)->rebuild($version);

    expect($room->blocks()->pluck('start_at')->map->toDateString()->all())
        ->toBe(['2026-09-07', '2026-09-21', '2026-09-28']);
});

test('sesi online dan belum terjadwal tidak memblokir ruang', function () {
    $period = septemberPeriod();
    $version = ScheduleVersion::factory()->official()->for($period)->create();
    ScheduleSession::factory()->for($version, 'version')->online()->create();
    ScheduleSession::factory()->for($version, 'version')->unscheduled()->create();

    expect(app(ScheduleBlockBuilder::class)->rebuild($version))->toBe(0);
});

test('sesi gabungan beberapa kelas hanya memblokir ruang satu kali per pertemuan', function () {
    $period = septemberPeriod();
    $version = ScheduleVersion::factory()->official()->for($period)->create(['effective_from' => '2026-09-01']);
    $session = ScheduleSession::factory()->for($version, 'version')->create(['day_of_week' => 2]);
    $session->participants()->attach(ClassGroup::factory()->count(2)->create());

    expect(app(ScheduleBlockBuilder::class)->rebuild($version))->toBe(5);
});

test('fase sebelum dan sesudah UTS dibatasi tanggal UTS', function (SessionPhase $phase, array $expectedDates) {
    $period = septemberPeriod();
    $version = ScheduleVersion::factory()->official()->for($period)->create(['effective_from' => '2026-09-01']);
    $room = Room::factory()->create();
    ScheduleSession::factory()->for($version, 'version')->for($room)->create(['day_of_week' => 1, 'phase' => $phase]);

    app(ScheduleBlockBuilder::class)->rebuild($version);

    expect($room->blocks()->orderBy('start_at')->pluck('start_at')->map->toDateString()->all())->toBe($expectedDates);
})->with([
    'sebelum UTS' => [SessionPhase::BeforeUts, ['2026-09-07']],
    'sesudah UTS' => [SessionPhase::AfterUts, ['2026-09-21', '2026-09-28']],
]);

test('tanggal UTS yang belum diketahui tidak membuka ruang (blok sepanjang periode)', function () {
    $period = septemberPeriod(['uts_start' => null, 'uts_end' => null]);
    $version = ScheduleVersion::factory()->official()->for($period)->create(['effective_from' => '2026-09-01']);
    ScheduleSession::factory()->for($version, 'version')->create(['day_of_week' => 1, 'phase' => SessionPhase::BeforeUts]);

    expect(app(ScheduleBlockBuilder::class)->rebuild($version))->toBe(4);
});

test('blok hanya dibuat mulai tanggal berlaku versi', function () {
    $period = septemberPeriod();
    $version = ScheduleVersion::factory()->official()->for($period)->create(['effective_from' => '2026-09-20']);
    ScheduleSession::factory()->for($version, 'version')->create(['day_of_week' => 1]);

    expect(app(ScheduleBlockBuilder::class)->rebuild($version))->toBe(2);
});

test('membangun ulang mengganti blok lama tanpa menggandakan', function () {
    $period = septemberPeriod();
    $version = ScheduleVersion::factory()->official()->for($period)->create(['effective_from' => '2026-09-01']);
    ScheduleSession::factory()->for($version, 'version')->create(['day_of_week' => 1]);
    $builder = app(ScheduleBlockBuilder::class);

    $builder->rebuild($version);
    $builder->rebuild($version);

    expect($version->roomBlocks()->count())->toBe(4);
});
