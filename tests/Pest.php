<?php

use App\Enums\RoomBlockSource;
use App\Models\Period;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\ScheduleVersion;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Semua tes Feature memakai database in-memory yang direset per tes.
| LazilyRefreshDatabase hanya menjalankan migrasi untuk tes yang menyentuh database.
|
*/

pest()->extend(TestCase::class)
    ->use(LazilyRefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Helper data jadwal
|--------------------------------------------------------------------------
*/

/**
 * Periode aktif Ganjil 2026/2027 dengan Jadwal resmi yang berlaku.
 */
function officialVersion(): ScheduleVersion
{
    $period = Period::query()->first() ?? Period::factory()->create();

    return ScheduleVersion::query()->official()->first()
        ?? ScheduleVersion::factory()->official()->for($period)->create(['effective_from' => $period->start_date]);
}

/**
 * Blok ruang dari jadwal resmi pada rentang waktu tertentu.
 */
function scheduleBlock(Room $room, string $start, string $end, ?ScheduleVersion $version = null): RoomBlock
{
    $version ??= officialVersion();

    return RoomBlock::factory()->for($room)->create([
        'start_at' => CarbonImmutable::parse($start),
        'end_at' => CarbonImmutable::parse($end),
        'source_type' => RoomBlockSource::Schedule,
        'schedule_version_id' => $version->id,
    ]);
}
