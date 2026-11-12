<?php

use App\Enums\RescheduleStatus;
use App\Enums\RoomBlockSource;
use App\Enums\SessionPhase;
use App\Models\Academic\CalendarException;
use App\Models\Academic\ClassGroup;
use App\Models\Academic\CourseOffering;
use App\Models\Academic\Period;
use App\Models\Academic\SessionReschedule;
use App\Models\Room\Room;
use App\Models\Scheduling\ScheduleSession;
use App\Models\Scheduling\ScheduleVersion;
use App\Services\Scheduling\ScheduleBlockBuilder;

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

test('pertemuan yang dipindah Keti tetap nonaktif dan disinkronkan saat jam atau ruang sesi berubah di jadwal resmi baru', function () {
    $period = septemberPeriod();
    $roomA = Room::factory()->create(['name' => 'AE 107']);
    $roomB = Room::factory()->create(['name' => 'AE 108']);
    $offering = CourseOffering::factory()->for($period)->create();

    // Sesi resmi versi 1: Senin 07:30 - 10:00 di AE 107
    $version1 = ScheduleVersion::factory()->official()->for($period)->create(['effective_from' => '2026-09-01']);
    $session1 = ScheduleSession::factory()->for($version1, 'version')->for($roomA)->create([
        'course_offering_id' => $offering->id,
        'day_of_week' => 1,
        'start_minute' => 450,
        'end_minute' => 600,
    ]);

    // Reschedule aktif untuk pertemuan 2026-09-07
    $reschedule = SessionReschedule::factory()->create([
        'schedule_session_id' => $session1->id,
        'course_offering_id' => $offering->id,
        'original_start_at' => '2026-09-07 07:30:00',
        'original_end_at' => '2026-09-07 10:00:00',
        'original_room_id' => $roomA->id,
        'status' => RescheduleStatus::Active,
    ]);

    // Sesi resmi versi 2: Geser ke Selasa 10:00 - 12:30 di AE 108
    $version2 = ScheduleVersion::factory()->official()->for($period)->create(['effective_from' => '2026-09-01']);
    $session2 = ScheduleSession::factory()->for($version2, 'version')->for($roomB)->create([
        'course_offering_id' => $offering->id,
        'day_of_week' => 2,
        'start_minute' => 600,
        'end_minute' => 750,
    ]);

    $builder = app(ScheduleBlockBuilder::class);
    $builder->rebuild($version2);

    // Blok Selasa 2026-09-08 di AE 108 harus is_active = false
    $block = $roomB->blocks()->whereDate('start_at', '2026-09-08')->first();
    expect($block)->not->toBeNull()
        ->and($block->is_active)->toBeFalse();

    // Reschedule harus tersinkron ke start dan ruang baru di versi 2
    $reschedule->refresh();
    expect($reschedule->original_start_at->format('Y-m-d H:i'))->toBe('2026-09-08 10:00')
        ->and($reschedule->original_room_id)->toBe($roomB->id)
        ->and($reschedule->schedule_session_id)->toBe($session2->id);
});
