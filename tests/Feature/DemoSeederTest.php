<?php

use App\Enums\KetiApplicationStatus;
use App\Enums\RoomBlockSource;
use App\Enums\ScheduleVersionStatus;
use App\Models\Reservation\Reservation;
use App\Models\Room\Room;
use App\Models\Room\RoomBlock;
use App\Models\Scheduling\ScheduleIssue;
use App\Models\Scheduling\ScheduleVersion;
use App\Models\User\KetiApplication;
use Database\Seeders\DatabaseSeeder;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 11, 4)->setTime(10, 12));
});

test('seeder demo membuat 24 ruang sesuai desain', function () {
    $this->seed(DatabaseSeeder::class);

    expect(Room::query()->count())->toBe(24)
        ->and(Room::query()->whereRelation('building', 'code', 'AE')->count())->toBe(10)
        ->and(Room::query()->whereRelation('building', 'code', 'PINISI')->count())->toBe(4)
        ->and(Room::query()->whereNull('building_id')->count())->toBe(10);
});

test('seeder demo membuat jadwal resmi v2 dan draf v3 dengan tiga masalah wajib', function () {
    $this->seed(DatabaseSeeder::class);

    $official = ScheduleVersion::query()->official()->sole();
    $draft = ScheduleVersion::query()->where('status', ScheduleVersionStatus::Draft)->sole();

    expect($official->version_no)->toBe(2)
        ->and($official->roomBlocks()->where('is_active', true)->count())->toBeGreaterThan(0)
        ->and($draft->version_no)->toBe(3)
        ->and($draft->roomBlocks()->count())->toBe(0)
        ->and(ScheduleIssue::query()->whereBelongsTo($draft, 'version')->blocking()->pluck('code')->sort()->values()->all())
        ->toBe(['LEADERSHIP_THURSDAY', 'ROOM_CONFLICT', 'SESSION_WITHOUT_TIME']);
});

test('seeder demo membuat akun Keti dengan semua status pengajuan', function () {
    $this->seed(DatabaseSeeder::class);

    expect(KetiApplication::query()->where('status', KetiApplicationStatus::Approved)->count())->toBe(3)
        ->and(KetiApplication::query()->where('status', KetiApplicationStatus::Pending)->count())->toBe(1)
        ->and(KetiApplication::query()->where('status', KetiApplicationStatus::NeedsRevision)->count())->toBe(1);
});

test('seeder demo membuat reservasi untuk setiap status', function () {
    $this->seed(DatabaseSeeder::class);

    expect(Reservation::query()->distinct()->pluck('status')->map->value->sort()->values()->all())
        ->toBe(['booked', 'cancelled', 'finished', 'in_use']);
});

test('data demo tidak punya blok aktif yang saling bentrok di ruang yang sama', function () {
    $this->seed(DatabaseSeeder::class);

    $conflicts = RoomBlock::query()->active()->orderBy('start_at')->get()
        ->groupBy('room_id')
        ->flatMap(function ($blocks) {
            return $blocks->values()->sliding(2)
                ->filter(fn ($pair) => $pair->first()->end_at->greaterThan($pair->last()->start_at))
                ->map(fn ($pair) => $pair->map(fn (RoomBlock $block) => $block->source_type->value.'#'.$block->source_id)->implode(' vs '));
        });

    expect($conflicts->all())->toBe([])
        ->and(RoomBlock::query()->where('source_type', RoomBlockSource::Closure)->active()->count())->toBe(1);
});
