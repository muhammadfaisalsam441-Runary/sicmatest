<?php

namespace Database\Seeders;

use App\Enums\ScheduleSource;
use App\Enums\ScheduleVersionStatus;
use App\Enums\SessionMode;
use App\Enums\TeachingRole;
use App\Enums\UserRole;
use App\Models\Academic\ClassGroup;
use App\Models\Academic\Course;
use App\Models\Academic\CourseOffering;
use App\Models\Academic\Period;
use App\Models\Lecturer\Lecturer;
use App\Models\Room\Room;
use App\Models\Scheduling\ScheduleSession;
use App\Models\Scheduling\ScheduleVersion;
use App\Models\User\User;
use App\Services\Scheduling\ScheduleBlockBuilder;
use App\Services\Scheduling\ScheduleValidator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Jadwal resmi v1 = Jadwal TA Ganjil 2026/2027 JTIK (PDF update 21 Agustus 2026), room_blocks terisi.
 *
 * Jadwal sumber sendiri memuat dua bentrok yang perlu ditindaklanjuti jurusan (ikut terbawa ke draf):
 * - CLASS_CONFLICT: PTIK ULANG 2025, Senin — Strategi Pembelajaran 10.05–12.40 vs Inovasi Teknologi 11.00–12.40.
 * - LECTURER_CONFLICT: Alifya NFH, Rabu — PTIK B 2026 (Lab ICT 10, 13.10–15.40) vs PTIK ULANG 2023 (AE 102, 14.50–17.30).
 *
 * Draf v2 = salinan jadwal resmi untuk latihan menyusun ulang, ditambah dua masalah SIMULASI:
 * - LEADERSHIP_THURSDAY: satu sesi Ketua Jurusan dipindah ke hari Kamis.
 * - SESSION_WITHOUT_TIME: satu sesi diubah menjadi "Hubungi Dosen".
 */
class ScheduleSeeder extends Seeder
{
    /**
     * Baris sumber yang dijadikan "Hubungi Dosen" di draf (simulasi).
     */
    private const SIMULATED_UNSCHEDULED_ROW = 347;

    /**
     * Run the database seeds.
     */
    public function run(ScheduleBlockBuilder $blockBuilder, ScheduleValidator $validator): void
    {
        $period = Period::query()->active()->firstOrFail();
        $publisher = User::query()->where('role', UserRole::SuperAdmin)->firstOrFail();

        $official = $period->scheduleVersions()->create([
            'version_no' => 1,
            'status' => ScheduleVersionStatus::Official,
            'source' => ScheduleSource::Import,
            'effective_from' => Carbon::parse($period->start_date)->toDateString(),
            'effective_to' => null,
            'published_by' => $publisher->id,
            'published_at' => '2026-08-21 10:00:00',
            'lock_version' => 0,
        ]);
        $this->seedSessions($official, $period);
        $blockBuilder->rebuild($official);

        $draft = $period->scheduleVersions()->create([
            'version_no' => 2,
            'status' => ScheduleVersionStatus::Draft,
            'source' => ScheduleSource::Manual,
            'based_on_version_id' => $official->id,
            'effective_from' => now()->addWeek()->startOfWeek()->toDateString(),
            'lock_version' => 0,
        ]);
        $this->seedSessions($draft, $period);
        $this->simulateDraftProblems($draft, $period);

        $validator->validate($draft->fresh());
    }

    private function seedSessions(ScheduleVersion $version, Period $period): void
    {
        $classGroups = ClassGroup::query()->pluck('id', 'name');
        $courses = Course::query()->get()->keyBy('code');
        $rooms = Room::query()->pluck('id', 'name');
        $lecturers = Lecturer::query()->pluck('id', 'name');

        /** @var array<string, ScheduleSession> $sessions */
        $sessions = [];

        foreach (ScheduleSourceData::rows() as $row) {
            $course = $courses[$row['course_code']];
            $offering = CourseOffering::query()->firstOrCreate([
                'period_id' => $period->id,
                'course_id' => $course->id,
                'class_group_id' => $classGroups[$row['class']],
            ]);

            $key = ScheduleSourceData::sessionKey($row);

            $sessions[$key] ??= $version->sessions()->create([
                'course_offering_id' => $offering->id,
                'title' => $course->name,
                'mode' => SessionMode::Offline,
                'day_of_week' => $row['day'],
                'start_minute' => $row['start'],
                'end_minute' => $row['end'],
                'room_id' => $rooms[$row['room']],
                'source_file' => ScheduleSourceData::SOURCE_FILE,
                'source_row' => $row['no'],
                'source_raw' => $row['raw'],
            ]);

            $session = $sessions[$key];
            $session->participants()->syncWithoutDetaching([$classGroups[$row['class']]]);

            foreach ($row['lecturers'] as $index => $name) {
                $role = $index === 0 ? TeachingRole::Lead : TeachingRole::Partner;
                $lecturerId = $lecturers[$name];

                if (! $session->lecturers()->whereKey($lecturerId)->exists()) {
                    $session->lecturers()->attach($lecturerId, ['role' => $role->value]);
                }

                $offering->teachingAssignments()->firstOrCreate(
                    ['lecturer_id' => $lecturerId, 'phase' => 'full'],
                    ['role' => $role, 'credited_sks' => null],
                );
            }
        }
    }

    private function simulateDraftProblems(ScheduleVersion $draft, Period $period): void
    {
        $unscheduled = $draft->sessions()->where('source_row', self::SIMULATED_UNSCHEDULED_ROW)->firstOrFail();
        $unscheduled->update([
            'mode' => SessionMode::Unscheduled,
            'day_of_week' => null,
            'start_minute' => null,
            'end_minute' => null,
            'room_id' => null,
            'source_raw' => [...$unscheduled->source_raw, 'waktu' => 'Hubungi Dosen'],
        ]);

        $this->moveLeaderToThursday($draft, $period);
    }

    /**
     * Pindahkan satu sesi Ketua Jurusan ke hari Kamis pada jam & ruang yang tidak menimbulkan bentrok lain,
     * agar draf hanya memuat masalah pimpinan-Kamis untuk sesi ini.
     */
    private function moveLeaderToThursday(ScheduleVersion $draft, Period $period): void
    {
        $leader = Lecturer::query()->where('name', AcademicSeeder::HEAD_OF_DEPARTMENT)->firstOrFail();
        $sessions = $draft->sessions()->with(['participants:id', 'lecturers:id'])->get();
        $thursday = $sessions->filter(fn (ScheduleSession $session): bool => $session->day_of_week === 4)->values();
        $starts = $period->timeSlots()->orderBy('start_minute')->pluck('start_minute');
        $rooms = Room::query()->orderBy('name')->pluck('id');

        $candidates = $sessions
            ->filter(fn (ScheduleSession $session): bool => $session->lecturers->contains('id', $leader->id) && $session->day_of_week !== null)
            ->sortBy('source_row');

        foreach ($candidates as $session) {
            $duration = $session->end_minute - $session->start_minute;

            foreach ($starts as $start) {
                foreach (collect([$session->room_id])->merge($rooms)->unique() as $roomId) {
                    if ($this->isFree($thursday, $session, $start, $start + $duration, $roomId)) {
                        $session->update(['day_of_week' => 4, 'start_minute' => $start, 'end_minute' => $start + $duration, 'room_id' => $roomId]);

                        return;
                    }
                }
            }
        }

        throw new RuntimeException('Tidak menemukan jam Kamis yang bebas untuk simulasi draf.');
    }

    /**
     * @param  Collection<int, ScheduleSession>  $thursday
     */
    private function isFree(Collection $thursday, ScheduleSession $moving, int $start, int $end, int $roomId): bool
    {
        $classIds = $moving->participants->pluck('id');
        $lecturerIds = $moving->lecturers->pluck('id');

        return $thursday->every(fn (ScheduleSession $other): bool => $other->id === $moving->id
            || $other->start_minute >= $end
            || $start >= $other->end_minute
            || ($other->room_id !== $roomId
                && $other->participants->pluck('id')->intersect($classIds)->isEmpty()
                && $other->lecturers->pluck('id')->intersect($lecturerIds)->isEmpty()));
    }
}
