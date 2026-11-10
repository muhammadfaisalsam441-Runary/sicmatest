<?php

namespace Database\Seeders;

use App\Enums\CalendarExceptionType;
use App\Enums\LecturerHomebase;
use App\Enums\LecturerProfileStatus;
use App\Models\Academic\ClassGroup;
use App\Models\Academic\Course;
use App\Models\Academic\Period;
use App\Models\Lecturer\Lecturer;
use Illuminate\Database\Seeder;

/**
 * DATA DEMO — periode Ganjil 2026/2027, slot jam, hari libur, kelas, mata kuliah, dan dosen fiktif.
 * Tanggal semester, UTS/UAS, slot jam, dan hari libur di sini adalah contoh, BELUM DITETAPKAN jurusan.
 */
class AcademicSeeder extends Seeder
{
    /**
     * Nama dosen fiktif, dirujuk ScheduleSeeder dengan kunci pendek.
     *
     * @var array<string, string>
     */
    public const LECTURERS = [
        'hendra' => 'Dr. Hendra Saputra, M.T.',
        'rina' => 'Rina Kartika, S.Kom., M.Kom.',
        'ahmad' => 'Ahmad Fauzi, S.T., M.T.',
        'dewi' => 'Dewi Lestari, S.Pd., M.Pd.',
        'bayu' => 'Bayu Nugroho, S.Kom., M.T.',
        'fitri' => 'Fitri Handayani, S.T., M.Eng.',
        'irfan' => 'Irfan Hakim, S.Kom., M.Cs.',
        'maya' => 'Maya Sari, S.Pd., M.T.',
        'yusuf' => 'Yusuf Habibi, S.T., M.T.',
        'lina' => 'Lina Marlina, S.Si., M.Si.',
        'rudi' => 'Rudi Hartono, S.Kom., M.Kom.',
        'sinta' => 'Sinta Amelia, S.T., M.T.',
        'arman' => 'Bpk. Arman',
        'nurul' => 'Ibu Nurul H.',
        'taufik' => 'Bpk. Taufik',
    ];

    /**
     * Kunci dosen yang berasal dari sumber jadwal dan belum terverifikasi.
     *
     * @var list<string>
     */
    public const UNVERIFIED_LECTURERS = ['arman', 'nurul', 'taufik'];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $period = Period::create([
            'name' => 'Ganjil 2026/2027',
            'start_date' => '2026-09-01',
            'end_date' => '2027-01-15',
            'timezone' => 'Asia/Makassar',
            'uts_start' => '2026-10-26',
            'uts_end' => '2026-10-30',
            'uas_start' => '2027-01-04',
            'uas_end' => '2027-01-15',
            'is_active' => true,
        ]);

        $this->seedTimeSlots($period);
        $this->seedCalendarExceptions($period);
        $this->seedClassGroups();
        $this->seedCourses();
        $this->seedLecturers();
    }

    private function seedTimeSlots(Period $period): void
    {
        // [label, mulai, selesai] dalam menit sejak 00.00 — jeda 15.30–15.40 meniru "15.40 vs 15.45" di desain.
        $slots = [
            ['I', 450, 500], ['II', 500, 550], ['III', 550, 600], ['IV', 600, 650],
            ['V', 650, 700], ['VI', 700, 750], ['VII', 780, 830], ['VIII', 830, 880],
            ['IX', 880, 930], ['X', 940, 990], ['XI', 990, 1040],
        ];

        foreach ($slots as [$label, $start, $end]) {
            $period->timeSlots()->create(['label' => $label, 'start_minute' => $start, 'end_minute' => $end]);
        }
    }

    private function seedCalendarExceptions(Period $period): void
    {
        $exceptions = [
            ['2026-12-24', CalendarExceptionType::Holiday, 'Cuti bersama Natal'],
            ['2026-12-25', CalendarExceptionType::Holiday, 'Hari Raya Natal'],
            ['2027-01-01', CalendarExceptionType::Holiday, 'Tahun Baru'],
        ];

        foreach ($exceptions as [$date, $type, $note]) {
            $period->calendarExceptions()->create(['date' => $date, 'type' => $type, 'note' => $note]);
        }
    }

    private function seedClassGroups(): void
    {
        $classGroups = [
            ['TEKOM-A-2025', 'Tekom A', 'Teknik Komputer', 2025, 30],
            ['TEKOM-B-2025', 'Tekom B', 'Teknik Komputer', 2025, 30],
            ['TEKOM-C-2025', 'Tekom C', 'Teknik Komputer', 2025, 30],
            ['PTIK-A-2025', 'PTIK A', 'Pendidikan Teknik Informatika dan Komputer', 2025, 30],
            ['PTIK-B-2025', 'PTIK B', 'Pendidikan Teknik Informatika dan Komputer', 2025, 30],
            ['TEKOM-A-2024', 'Tekom A 2024', 'Teknik Komputer', 2024, 30],
        ];

        foreach ($classGroups as [$code, $name, $program, $cohort, $students]) {
            ClassGroup::create([
                'code' => $code,
                'name' => $name,
                'study_program' => $program,
                'cohort_year' => $cohort,
                'student_count' => $students,
                'is_active' => true,
            ]);
        }
    }

    private function seedCourses(): void
    {
        $courses = [
            ['TKM2301', 'Pemrograman Web', 3],
            ['TKM2302', 'Struktur Data', 3],
            ['TKM2303', 'Jaringan Komputer', 3],
            ['TKM2304', 'Basis Data', 3],
            ['TKM2305', 'Sistem Operasi', 2],
            ['TKM2306', 'Kecerdasan Buatan', 3],
            ['TKM2307', 'Desain Antarmuka Pengguna', 2],
            ['TKM2308', 'Mikroprosesor', 3],
            ['TKM2309', 'Matematika Diskrit', 2],
            ['TKM2310', 'Praktikum Jaringan', 1],
        ];

        foreach ($courses as [$code, $name, $sks]) {
            Course::create(['code' => $code, 'name' => $name, 'sks' => $sks, 'curriculum' => 'Kurikulum 2025']);
        }
    }

    private function seedLecturers(): void
    {
        foreach (self::LECTURERS as $key => $name) {
            $isUnverified = in_array($key, self::UNVERIFIED_LECTURERS, true);

            $lecturer = Lecturer::create([
                'name' => $name,
                'nidn' => $isUnverified ? null : sprintf('09%08d', crc32($key) % 100000000),
                'homebase' => match (true) {
                    $isUnverified => null,
                    $key === 'lina' => LecturerHomebase::External,
                    default => LecturerHomebase::Jtik,
                },
                'profile_status' => $isUnverified ? LecturerProfileStatus::Unverified : LecturerProfileStatus::Verified,
                'is_active' => true,
                'source_name' => $isUnverified ? $name : null,
            ]);

            if ($key === 'hendra') {
                $lecturer->leadershipTerms()->create([
                    'position' => 'Ketua Jurusan',
                    'valid_from' => '2025-01-01',
                    'valid_to' => '2028-12-31',
                ]);
            }
        }
    }
}
