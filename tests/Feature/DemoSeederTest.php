<?php

use App\Enums\KetiApplicationStatus;
use App\Enums\RoomBlockSource;
use App\Enums\ScheduleVersionStatus;
use App\Models\ClassGroup;
use App\Models\Course;
use App\Models\KetiApplication;
use App\Models\Lecturer;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\ScheduleIssue;
use App\Models\ScheduleSession;
use App\Models\ScheduleVersion;
use Database\Seeders\AcademicSeeder;
use Database\Seeders\DatabaseSeeder;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 11, 4)->setTime(10, 12));
});

test('seeder memuat 21 ruang dari jadwal TA Ganjil 2026/2027 tanpa mengarang kapasitas', function () {
    $this->seed(DatabaseSeeder::class);

    expect(Room::query()->count())->toBe(21)
        ->and(Room::query()->whereRelation('building', 'code', 'AE')->count())->toBe(12)
        ->and(Room::query()->whereNull('building_id')->count())->toBe(9)
        ->and(Room::query()->whereNotNull('capacity')->count())->toBe(0)
        ->and(Room::query()->where('name', 'Lab ICT 4')->value('code'))->toBe('LAB-ICT-04');
});

test('seeder memuat kelas, mata kuliah, dan dosen dari jadwal sumber', function () {
    $this->seed(DatabaseSeeder::class);

    $head = Lecturer::query()->where('name', AcademicSeeder::HEAD_OF_DEPARTMENT)->sole();

    expect(ClassGroup::query()->count())->toBe(52)
        ->and(ClassGroup::query()->where('code', 'TEKOM-C-2025')->value('name'))->toBe('TEKOM C 2025')
        ->and(Course::query()->count())->toBe(103)
        ->and(Lecturer::query()->count())->toBe(102)
        ->and(Lecturer::query()->where('name', 'like', 'Alifya NFH%')->count())->toBe(1)
        ->and($head->leadershipTerms()->value('position'))->toBe('Ketua Jurusan');
});

test('jadwal resmi v1 sama dengan PDF: 364 baris menjadi 362 sesi karena 2 kuliah gabungan', function () {
    $this->seed(DatabaseSeeder::class);

    $official = ScheduleVersion::query()->official()->sole();
    $joint = $official->sessions()->has('participants', '>', 1)->with('participants')->get();

    expect($official->version_no)->toBe(1)
        ->and($official->sessions()->count())->toBe(362)
        ->and($joint)->toHaveCount(2)
        ->and($joint->flatMap->participants->pluck('name')->unique()->sort()->values()->all())->toBe(['PTIK F 2026', 'PTIK ULANG 2024'])
        ->and($official->roomBlocks()->where('is_active', true)->count())->toBeGreaterThan(0);

    $firstRow = $official->sessions()->where('source_row', 1)->sole();
    expect($firstRow->day_of_week)->toBe(4)
        ->and($firstRow->start_minute)->toBe(450)
        ->and($firstRow->end_minute)->toBe(550)
        ->and($firstRow->room->name)->toBe('AE 107');
});

test('jadwal resmi tidak menjadwalkan Ketua Jurusan pada hari Kamis', function () {
    $this->seed(DatabaseSeeder::class);

    $head = Lecturer::query()->where('name', AcademicSeeder::HEAD_OF_DEPARTMENT)->sole();

    expect(ScheduleSession::query()
        ->whereBelongsTo(ScheduleVersion::query()->official()->sole(), 'version')
        ->whereHas('lecturers', fn ($query) => $query->whereKey($head->id))
        ->where('day_of_week', 4)
        ->count())->toBe(0);
});

test('draf v2 memuat dua bentrok dari sumber dan dua masalah simulasi', function () {
    $this->seed(DatabaseSeeder::class);

    $draft = ScheduleVersion::query()->where('status', ScheduleVersionStatus::Draft)->sole();

    expect($draft->version_no)->toBe(2)
        ->and($draft->roomBlocks()->count())->toBe(0)
        ->and(ScheduleIssue::query()->whereBelongsTo($draft, 'version')->blocking()->pluck('code')->sort()->values()->all())
        ->toBe(['CLASS_CONFLICT', 'LEADERSHIP_THURSDAY', 'LECTURER_CONFLICT', 'SESSION_WITHOUT_TIME']);
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

test('data seeder tidak punya blok aktif yang saling bentrok di ruang yang sama', function () {
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
