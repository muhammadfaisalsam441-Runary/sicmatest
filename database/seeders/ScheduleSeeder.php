<?php

namespace Database\Seeders;

use App\Enums\IssueSeverity;
use App\Enums\ScheduleSource;
use App\Enums\ScheduleVersionStatus;
use App\Enums\SessionMode;
use App\Enums\TeachingRole;
use App\Enums\UserRole;
use App\Models\ClassGroup;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\Lecturer;
use App\Models\Period;
use App\Models\Room;
use App\Models\ScheduleSession;
use App\Models\ScheduleVersion;
use App\Models\User;
use App\Services\ScheduleBlockBuilder;
use Illuminate\Database\Seeder;

/**
 * DATA DEMO — jadwal v1 (digantikan), v2 (Jadwal resmi, room_blocks terisi), dan draf v3 dengan 3 masalah wajib.
 */
class ScheduleSeeder extends Seeder
{
    /**
     * Rentang slot → [menit mulai, menit selesai].
     *
     * @var array<string, array{0: int, 1: int}>
     */
    private const RANGES = [
        'I-II' => [450, 550],
        'I-III' => [450, 600],
        'IV-V' => [600, 700],
        'IV-VI' => [600, 750],
        'VII-VIII' => [780, 880],
        'VII-IX' => [780, 930],
        'X-XI' => [940, 1040],
    ];

    /**
     * Sesi jadwal resmi: [kode MK, kelas peserta, dosen (kunci => peran), hari ISO, rentang slot, kode ruang, mode].
     *
     * @var list<array{0: string, 1: list<string>, 2: array<string, string>, 3: ?int, 4: ?string, 5: ?string, 6: string}>
     */
    private const SESSIONS = [
        ['TKM2301', ['TEKOM-C-2025'], ['rina' => 'lead'], 1, 'I-III', 'AE-107', 'offline'],
        ['TKM2302', ['TEKOM-A-2025'], ['ahmad' => 'lead'], 1, 'I-III', 'AE-101', 'offline'],
        ['TKM2303', ['PTIK-B-2025'], ['bayu' => 'lead'], 1, 'IV-VI', 'LAB-JARINGAN', 'offline'],
        ['TKM2304', ['TEKOM-B-2025'], ['irfan' => 'lead'], 1, 'IV-VI', 'AE-102', 'offline'],
        ['TKM2309', ['TEKOM-A-2025', 'TEKOM-B-2025'], ['lina' => 'lead'], 1, 'VII-VIII', 'AE-209', 'offline'],
        ['TKM2306', ['PTIK-A-2025'], ['yusuf' => 'lead'], 2, 'I-III', null, 'online'],
        ['TKM2305', ['TEKOM-C-2025'], ['rudi' => 'lead'], 2, 'IV-V', 'AE-103', 'offline'],
        ['TKM2307', ['PTIK-B-2025'], ['maya' => 'lead'], 2, 'VII-VIII', 'AE-105', 'offline'],
        ['TKM2308', ['TEKOM-B-2025'], ['fitri' => 'lead'], 2, 'I-III', 'LAB-EMBEDDED', 'offline'],
        ['TKM2301', ['PTIK-A-2025'], ['rina' => 'lead'], 2, 'VII-IX', 'LAB-PEMROGRAMAN', 'offline'],
        ['TKM2303', ['TEKOM-C-2025'], ['bayu' => 'lead'], 3, 'I-III', 'LAB-JARINGAN', 'offline'],
        ['TKM2304', ['TEKOM-A-2025'], ['irfan' => 'lead'], 3, 'IV-VI', 'AE-106', 'offline'],
        ['TKM2302', ['PTIK-B-2025'], ['ahmad' => 'lead'], 3, 'VII-IX', 'AE-104', 'offline'],
        ['TKM2306', ['TEKOM-C-2025'], ['yusuf' => 'lead'], 3, 'VII-IX', 'LAB-SISTEM-CERDAS', 'offline'],
        ['TKM2309', ['PTIK-A-2025'], ['lina' => 'lead'], 3, 'X-XI', 'PINISI-C1-R2', 'offline'],
        ['TKM2301', ['TEKOM-B-2025'], ['rina' => 'lead'], 4, 'I-III', 'LAB-ICT-10', 'offline'],
        ['TKM2305', ['TEKOM-A-2025'], ['rudi' => 'lead'], 4, 'IV-V', 'AE-213', 'offline'],
        ['TKM2307', ['PTIK-A-2025'], ['nurul' => 'lead'], 4, 'IV-V', 'PINISI-C2-R3', 'offline'],
        ['TKM2307', ['TEKOM-C-2025'], ['maya' => 'lead'], 4, 'VII-VIII', 'AE-107', 'offline'],
        ['TKM2306', ['PTIK-B-2025'], ['yusuf' => 'lead'], 4, 'VII-IX', 'PINISI-C1-R5', 'offline'],
        ['TKM2308', ['TEKOM-C-2025'], ['fitri' => 'lead', 'sinta' => 'partner'], 5, 'I-III', 'LAB-EMBEDDED', 'offline'],
        ['TKM2303', ['TEKOM-A-2025'], ['hendra' => 'lead', 'bayu' => 'partner'], 5, 'I-III', 'LAB-JARINGAN', 'offline'],
        ['TKM2304', ['PTIK-A-2025'], ['sinta' => 'lead'], 5, 'IV-VI', 'LAB-ICT-11', 'offline'],
        ['TKM2302', ['TEKOM-C-2025'], ['ahmad' => 'lead'], 5, 'IV-VI', 'AE-209', 'offline'],
        ['TKM2310', ['TEKOM-B-2025'], ['hendra' => 'lead'], 5, 'VII-IX', 'LAB-JARINGAN', 'offline'],
        ['TKM2305', ['PTIK-B-2025'], ['arman' => 'lead'], null, null, null, 'unscheduled'],
    ];

    /**
     * Run the database seeds.
     */
    public function run(ScheduleBlockBuilder $blockBuilder): void
    {
        $period = Period::query()->active()->firstOrFail();
        $publisher = User::query()->where('role', UserRole::SuperAdmin)->firstOrFail();

        $v1 = $period->scheduleVersions()->create([
            'version_no' => 1,
            'status' => ScheduleVersionStatus::Superseded,
            'source' => ScheduleSource::Import,
            'effective_from' => '2026-09-01',
            'effective_to' => '2026-09-13',
            'published_by' => $publisher->id,
            'published_at' => '2026-08-28 10:00:00',
        ]);
        $this->seedSessions($v1, $period);

        $v2 = $period->scheduleVersions()->create([
            'version_no' => 2,
            'status' => ScheduleVersionStatus::Official,
            'source' => ScheduleSource::Manual,
            'based_on_version_id' => $v1->id,
            'effective_from' => '2026-09-14',
            'effective_to' => null,
            'published_by' => $publisher->id,
            'published_at' => '2026-09-12 09:00:00',
        ]);
        $this->seedSessions($v2, $period);
        $blockBuilder->rebuild($v2);

        $v3 = $period->scheduleVersions()->create([
            'version_no' => 3,
            'status' => ScheduleVersionStatus::Draft,
            'source' => ScheduleSource::Manual,
            'based_on_version_id' => $v2->id,
            'effective_from' => now()->addWeek()->startOfWeek()->toDateString(),
        ]);
        $this->seedSessions($v3, $period);
        $this->introduceDraftProblems($v3);
    }

    private function seedSessions(ScheduleVersion $version, Period $period): void
    {
        $lecturers = Lecturer::query()->get()->keyBy('name');
        $rooms = Room::query()->pluck('id', 'code');
        $classGroups = ClassGroup::query()->pluck('id', 'code');
        $courses = Course::query()->get()->keyBy('code');

        foreach (self::SESSIONS as $row => [$courseCode, $classCodes, $teachers, $day, $range, $roomCode, $mode]) {
            $course = $courses[$courseCode];
            $offering = CourseOffering::query()->firstOrCreate([
                'period_id' => $period->id,
                'course_id' => $course->id,
                'class_group_id' => $classGroups[$classCodes[0]],
            ]);

            foreach (array_slice($classCodes, 1) as $jointClassCode) {
                CourseOffering::query()->firstOrCreate([
                    'period_id' => $period->id,
                    'course_id' => $course->id,
                    'class_group_id' => $classGroups[$jointClassCode],
                ]);
            }

            [$start, $end] = $range === null ? [null, null] : self::RANGES[$range];

            $session = $version->sessions()->create([
                'course_offering_id' => $offering->id,
                'title' => $course->name,
                'mode' => SessionMode::from($mode),
                'day_of_week' => $day,
                'start_minute' => $start,
                'end_minute' => $end,
                'room_id' => $roomCode === null ? null : $rooms[$roomCode],
                'source_file' => $version->source === ScheduleSource::Import ? 'jadwal-ganjil-2026.xlsx' : null,
                'source_row' => $version->source === ScheduleSource::Import ? $row + 2 : null,
                'source_raw' => $mode === 'unscheduled' ? ['waktu' => 'Hubungi Dosen', 'ruang' => null] : null,
            ]);

            $session->participants()->attach(array_map(fn (string $code): int => $classGroups[$code], $classCodes));

            foreach ($teachers as $key => $role) {
                $lecturer = $lecturers[AcademicSeeder::LECTURERS[$key]];
                $session->lecturers()->attach($lecturer->id, ['role' => $role]);

                $offering->teachingAssignments()->firstOrCreate(
                    ['lecturer_id' => $lecturer->id, 'phase' => 'full'],
                    ['role' => TeachingRole::from($role), 'credited_sks' => null],
                );
            }
        }
    }

    /**
     * Tiga masalah wajib di draf v3: bentrok ruang, pimpinan hari Kamis, dan sesi tanpa waktu.
     */
    private function introduceDraftProblems(ScheduleVersion $draft): void
    {
        $rooms = Room::query()->pluck('id', 'code');
        $leader = Lecturer::query()->where('name', AcademicSeeder::LECTURERS['hendra'])->firstOrFail();

        $sessionInRoom = fn (string $roomCode, int $day): ScheduleSession => $draft->sessions()
            ->where('room_id', $rooms[$roomCode])
            ->where('day_of_week', $day)
            ->firstOrFail();

        $movedSession = $sessionInRoom('AE-213', 4);
        $occupiedSession = $sessionInRoom('PINISI-C2-R3', 4);
        $movedSession->update(['room_id' => $rooms['PINISI-C2-R3']]);

        $leaderSession = $draft->sessions()
            ->whereHas('lecturers', fn ($query) => $query->whereKey($leader->id))
            ->where('day_of_week', 5)
            ->where('start_minute', 780)
            ->firstOrFail();
        $leaderSession->update(['day_of_week' => 4]);

        $unscheduledSession = $draft->sessions()->where('mode', SessionMode::Unscheduled)->firstOrFail();

        $issues = [
            ['ROOM_CONFLICT', [$movedSession->id, $occupiedSession->id], 'C2-R3 dipakai dua sesi pada Kamis 10.00–11.40 WITA.'],
            ['LEADERSHIP_THURSDAY', [$leaderSession->id], $leader->name.' (pimpinan) dijadwalkan pada hari Kamis.'],
            ['SESSION_WITHOUT_TIME', [$unscheduledSession->id], 'Sistem Operasi PTIK B belum punya hari, jam, dan ruang ("Hubungi Dosen").'],
        ];

        foreach ($issues as [$code, $sessionIds, $message]) {
            $draft->issues()->create([
                'code' => $code,
                'severity' => IssueSeverity::Error,
                'session_ids' => $sessionIds,
                'message' => $message,
            ]);
        }
    }
}
