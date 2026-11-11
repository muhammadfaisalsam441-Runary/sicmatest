<?php

namespace Database\Seeders;

use App\Enums\CalendarExceptionType;
use App\Enums\LecturerProfileStatus;
use App\Models\Academic\ClassGroup;
use App\Models\Academic\Course;
use App\Models\Academic\Period;
use App\Models\Lecturer\Lecturer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Periode Ganjil 2026/2027 beserta kelas, mata kuliah, dan dosen dari Jadwal TA Ganjil JTIK 26-27.
 *
 * BELUM DITETAPKAN: tanggal semester, UTS/UAS, dan hari libur di bawah masih contoh (tidak ada di PDF).
 * Jumlah mahasiswa per kelas dan NIDN dosen tidak ada di sumber sehingga disimpan NULL. Homebase, NIP, pangkat,
 * dan jabatan dosen JTIK diisi LecturerRosterSeeder dari Daftar Dosen JTIK.
 */
class AcademicSeeder extends Seeder
{
    /**
     * Ketua Jurusan menurut lembar pengesahan jadwal ("Mengetahui, Ketua Jurusan").
     */
    public const HEAD_OF_DEPARTMENT = 'Dr. Ir. Abd. Muis Mappalotteng, S.Pd., M.Pd., M.T., IPM.';

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
        if (config('sicma.public_portal.show_sim_label')) {
            $this->seedCalendarExceptions($period);
        }
        $this->seedClassGroups();
        $this->seedCourses();
        $this->seedLecturers($period);
    }

    private function seedTimeSlots(Period $period): void
    {
        // Diturunkan dari kolom "JAM" & "WITA" pada PDF (mis. "III - IV 09.15 - 10.55").
        // Awal slot I, VI, dan XI tidak tertulis langsung; diambil dari akhir slot sebelumnya.
        $slots = [
            ['I', '07.30', '08.20'], ['II', '08.20', '09.10'], ['III', '09.15', '10.05'], ['IV', '10.05', '10.55'],
            ['V', '11.00', '11.50'], ['VI', '11.50', '12.40'], ['VII', '13.10', '14.00'], ['VIII', '14.00', '14.50'],
            ['IX', '14.50', '15.40'], ['X', '15.40', '16.35'], ['XI', '16.35', '17.30'],
        ];

        $minutes = fn (string $clock): int => (int) substr($clock, 0, 2) * 60 + (int) substr($clock, 3, 2);

        foreach ($slots as [$label, $start, $end]) {
            $period->timeSlots()->create(['label' => $label, 'start_minute' => $minutes($start), 'end_minute' => $minutes($end)]);
        }
    }

    private function seedCalendarExceptions(Period $period): void
    {
        // BELUM DITETAPKAN — contoh hari libur, belum dari kalender akademik resmi.
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
        $names = ScheduleSourceData::rows()->pluck('class')->unique()->sort(SORT_NATURAL)->values();

        foreach ($names as $name) {
            preg_match('/(\d{4})$/', $name, $year);

            ClassGroup::create([
                'code' => ScheduleSourceData::classCode($name),
                'name' => $name,
                'study_program' => Str::startsWith($name, 'PTIK') ? 'Pendidikan Teknik Informatika dan Komputer' : 'Teknik Komputer',
                'cohort_year' => isset($year[1]) ? (int) $year[1] : null,
                'student_count' => null,
                'is_active' => true,
            ]);
        }
    }

    private function seedCourses(): void
    {
        ScheduleSourceData::rows()
            ->unique('course_code')
            ->sortBy('course_code')
            ->each(fn (array $row) => Course::create([
                'code' => $row['course_code'],
                'name' => $row['course_name'],
                'sks' => $row['sks'],
                'curriculum' => null,
            ]));
    }

    private function seedLecturers(Period $period): void
    {
        $names = ScheduleSourceData::rows()->pluck('lecturers')->flatten()->unique()->sort()->values();

        foreach ($names as $name) {
            $lecturer = Lecturer::create([
                'name' => $name,
                'nidn' => null,
                'homebase' => null,
                // Nama tercantum di jadwal resmi jurusan; NIDN dilengkapi di Data Dosen.
                'profile_status' => LecturerProfileStatus::Verified,
                'is_active' => true,
                'source_name' => $name,
            ]);

            if ($name === self::HEAD_OF_DEPARTMENT) {
                // BELUM DITETAPKAN: tanggal SK masa jabatan tidak ada di sumber; sementara mulai awal semester.
                $lecturer->leadershipTerms()->create([
                    'position' => 'Ketua Jurusan',
                    'valid_from' => $period->start_date,
                    'valid_to' => null,
                ]);
            }
        }
    }
}
