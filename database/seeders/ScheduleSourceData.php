<?php

namespace Database\Seeders;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Jadwal Perkuliahan Semester Ganjil 2026/2027 JTIK (sumber: "ROOM Update 21 Ags - Jadwal TA Ganjil JTIK 26-27.pdf").
 *
 * Isi PDF disalin apa adanya ke database/data/jadwal-ganjil-2026-2027.csv dengan kolom templat impor SICMA,
 * sehingga berkas yang sama juga bisa diunggah lewat Kelola Jadwal › Impor. Satu-satunya perbaikan:
 * ejaan "Alifya NFH, S.Pd.,M.Pd" diseragamkan menjadi "Alifya NFH, S.Pd., M.Pd." (orang yang sama).
 */
class ScheduleSourceData
{
    public const SOURCE_FILE = 'ROOM Update 21 Ags - Jadwal TA Ganjil JTIK 26-27.pdf';

    /**
     * @var array<string, int>
     */
    private const DAYS = ['Senin' => 1, 'Selasa' => 2, 'Rabu' => 3, 'Kamis' => 4, 'Jumat' => 5];

    /**
     * @var list<array<string, mixed>>|null
     */
    private static ?array $rows = null;

    public static function path(): string
    {
        return database_path('data/jadwal-ganjil-2026-2027.csv');
    }

    /**
     * @return Collection<int, array{no: int, class: string, day: int, slots: string, start: int, end: int, room: string, course_code: string, course_name: string, sks: int, lecturers: list<string>, raw: array<string, string>}>
     */
    public static function rows(): Collection
    {
        if (self::$rows !== null) {
            return collect(self::$rows);
        }

        $handle = fopen(self::path(), 'r');

        if ($handle === false) {
            throw new RuntimeException('Berkas jadwal sumber tidak ditemukan: '.self::path());
        }

        $header = fgetcsv($handle, escape: '');
        $rows = [];

        while (($cells = fgetcsv($handle, escape: '')) !== false) {
            if ($cells === [null]) {
                continue;
            }

            $record = array_combine($header, $cells);

            if (preg_match('/^(\d{2})\.(\d{2})-(\d{2})\.(\d{2})$/', $record['Jam'], $time) !== 1) {
                throw new RuntimeException("Jam tidak terbaca pada baris {$record['No']}: {$record['Jam']}");
            }

            $rows[] = [
                'no' => (int) $record['No'],
                'class' => $record['Kelas'],
                'day' => self::DAYS[$record['Hari']] ?? throw new RuntimeException("Hari tidak dikenal pada baris {$record['No']}."),
                'slots' => $record['Jam ke'],
                'start' => (int) $time[1] * 60 + (int) $time[2],
                'end' => (int) $time[3] * 60 + (int) $time[4],
                'room' => $record['Ruang'],
                'course_code' => $record['Kode MK'],
                'course_name' => $record['Mata Kuliah'],
                'sks' => (int) $record['SKS'],
                'lecturers' => array_values(array_filter(array_map('trim', explode(';', $record['Dosen'])))),
                'raw' => [
                    'class' => $record['Kelas'],
                    'course_code' => $record['Kode MK'],
                    'course_name' => $record['Mata Kuliah'],
                    'day' => $record['Hari'],
                    'time' => $record['Jam'],
                    'room' => $record['Ruang'],
                    'lecturers' => $record['Dosen'],
                ],
            ];
        }

        fclose($handle);
        self::$rows = $rows;

        return collect($rows);
    }

    /**
     * Kunci sesi gabungan: kelas berbeda pada hari, jam, ruang, dan mata kuliah yang sama adalah satu sesi
     * (mis. PTIK ULANG 2024 bergabung dengan PTIK F 2026) sehingga ruang hanya terblokir sekali (B12).
     *
     * @param  array{day: int, start: int, end: int, room: string, course_name: string}  $row
     */
    public static function sessionKey(array $row): string
    {
        return implode('|', [$row['day'], $row['start'], $row['end'], Str::lower($row['room']), Str::lower(Str::squish($row['course_name']))]);
    }

    /**
     * "PTIK A 2026" → "PTIK-A-2026".
     */
    public static function classCode(string $name): string
    {
        return Str::upper(Str::of($name)->squish()->replace(' ', '-')->toString());
    }

    /**
     * "Lab ICT 4" → "LAB-ICT-04", "AE 101" → "AE-101".
     */
    public static function roomCode(string $name): string
    {
        $code = Str::upper(Str::of($name)->squish()->replace(' ', '-')->toString());

        return preg_replace_callback('/-(\d)$/', fn (array $match): string => '-0'.$match[1], $code);
    }
}
