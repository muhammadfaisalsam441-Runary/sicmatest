<?php

use App\Enums\ImportStatus;
use App\Enums\UserRole;
use App\Models\Academic\Period;
use App\Models\Scheduling\ScheduleIssue;
use App\Models\User\User;
use App\Services\Scheduling\ScheduleImportService;
use Carbon\CarbonImmutable;
use Database\Seeders\AcademicSeeder;
use Database\Seeders\AccountSeeder;
use Database\Seeders\RoomSeeder;
use Database\Seeders\ScheduleSourceData;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * Berkas database/data/jadwal-ganjil-2026-2027.csv (salinan PDF jadwal jurusan) bisa diunggah lewat
 * Kelola Jadwal › Impor tanpa galat dan menghasilkan draf yang sama dengan jadwal resmi seeder.
 */
beforeEach(function () {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00'));
    $this->seed([RoomSeeder::class, AcademicSeeder::class, AccountSeeder::class]);
});

test('CSV jadwal jurusan terimpor tanpa galat dan kuliah gabungan menjadi satu sesi', function () {
    $service = app(ScheduleImportService::class);
    $admin = User::query()->where('role', UserRole::SuperAdmin)->sole();
    $file = new UploadedFile(ScheduleSourceData::path(), 'jadwal-ganjil-2026-2027.csv', 'text/csv', null, true);

    $import = $service->store($file, Period::query()->active()->sole(), $admin);

    expect($import->status)->toBe(ImportStatus::Checked)
        ->and($import->summary['rows'])->toBe(364)
        ->and($import->summary['errors'])->toBe(0)
        ->and($import->summary['warnings'])->toBe(0);

    $draft = $service->commit($import, $admin);

    expect($draft->sessions()->count())->toBe(362)
        ->and($draft->sessions()->has('participants', '>', 1)->count())->toBe(2)
        // Dua bentrok ini memang ada di PDF sumber (PTIK ULANG 2025 hari Senin; Alifya NFH hari Rabu).
        ->and(ScheduleIssue::query()->whereBelongsTo($draft, 'version')->blocking()->pluck('code')->sort()->values()->all())
        ->toBe(['CLASS_CONFLICT', 'LECTURER_CONFLICT']);
});
