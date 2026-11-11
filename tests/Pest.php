<?php

use App\Enums\RoomBlockSource;
use App\Enums\ScheduleSource;
use App\Enums\SessionPhase;
use App\Enums\TeachingRole;
use App\Exceptions\DomainException;
use App\Models\Academic\ClassGroup;
use App\Models\Academic\CourseOffering;
use App\Models\Academic\Period;
use App\Models\Lecturer\Lecturer;
use App\Models\Lecturer\TeachingAssignment;
use App\Models\Room\Room;
use App\Models\Room\RoomBlock;
use App\Models\Scheduling\ScheduleSession;
use App\Models\Scheduling\ScheduleVersion;
use App\Models\User\User;
use App\Services\Scheduling\ScheduleBlockBuilder;
use App\Services\Scheduling\ScheduleDraftService;
use App\Services\Scheduling\SchedulePublisher;
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
 * Sesi kuliah di Jadwal resmi untuk kelas-kelas tertentu, lengkap dengan penawaran, tim pengajar, dan blok ruang.
 *
 * Penawaran dibuat untuk kelas pertama kecuali `course_offering_id` diberikan di $attributes (boleh null).
 * $team berisi [Lecturer, TeachingRole, SessionPhase]: penugasan mengajar + dosen sesi dengan perannya.
 * Blok jadwal versi resmi dibangun ulang, jadi panggil scheduleBlock() SESUDAH helper ini.
 *
 * @param  list<ClassGroup>  $classGroups
 * @param  list<array{0: Lecturer, 1: TeachingRole, 2: SessionPhase}>  $team
 */
function meetingSession(array $classGroups, array $attributes = [], array $team = []): ScheduleSession
{
    $version = officialVersion();
    $offeringId = array_key_exists('course_offering_id', $attributes)
        ? $attributes['course_offering_id']
        : CourseOffering::factory()->create(['period_id' => $version->period_id, 'class_group_id' => $classGroups[0]->id])->id;

    $session = ScheduleSession::factory()->create([
        ...$attributes,
        'schedule_version_id' => $version->id,
        'course_offering_id' => $offeringId,
    ]);
    $session->participants()->attach(collect($classGroups)->pluck('id')->all());

    foreach ($team as [$lecturer, $role, $phase]) {
        if ($offeringId !== null) {
            TeachingAssignment::factory()->create(['course_offering_id' => $offeringId, 'lecturer_id' => $lecturer->id, 'role' => $role, 'phase' => $phase]);
        }
        $session->lecturers()->attach($lecturer->id, ['role' => $role->value]);
    }

    app(ScheduleBlockBuilder::class)->rebuild($version->fresh());

    return $session->fresh(['participants', 'lecturers', 'courseOffering']);
}

/**
 * Admin menetapkan ulang jadwal: draf disalin dari Jadwal resmi, $changeDraft boleh mengubah sesinya, lalu
 * ditetapkan berlaku mulai hari ini. Mengembalikan versi resmi yang baru.
 */
function republishSchedule(?Closure $changeDraft = null): ScheduleVersion
{
    $official = officialVersion();
    $draft = app(ScheduleDraftService::class)->create($official->period, ScheduleSource::Manual, $official);

    if ($changeDraft !== null) {
        $changeDraft($draft);
    }

    return app(SchedulePublisher::class)->publish($draft->fresh(), User::factory()->superAdmin()->create(), CarbonImmutable::today(), $draft->fresh()->lock_version);
}

/**
 * Jalankan $callback dan pastikan DomainException dengan kode tersebut dilempar; kembalikan exception-nya.
 */
function expectDomainCode(Closure $callback, string $code): DomainException
{
    try {
        $callback();
    } catch (DomainException $exception) {
        expect($exception->errorCode)->toBe($code, $exception->getMessage());

        return $exception;
    }

    test()->fail("DomainException {$code} tidak dilempar.");
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
