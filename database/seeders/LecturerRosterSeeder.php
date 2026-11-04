<?php

namespace Database\Seeders;

use App\Enums\LecturerHomebase;
use App\Enums\LecturerProfileStatus;
use App\Models\Lecturer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Daftar Dosen Jurusan Teknik Informatika dan Komputer (51 dosen; nomor 27–30 tidak ada di dokumen sumber).
 *
 * Disalin ke database/data/dosen-jtik.csv. Kolom "Nama di SICMA" menautkan setiap baris ke nama dosen di
 * jadwal (gelar di jadwal lebih lengkap). Dosen yang belum mengajar semester ini ditambahkan sebagai dosen baru.
 * Semua dosen di daftar ber-homebase JTIK; dosen jadwal yang tidak ada di daftar asalnya tetap belum diverifikasi.
 */
class LecturerRosterSeeder extends Seeder
{
    public const SOURCE_FILE = 'Daftar Dosen Teknik Informatika dan Komputer.pdf';

    public static function path(): string
    {
        return database_path('data/dosen-jtik.csv');
    }

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $handle = fopen(self::path(), 'r');

        if ($handle === false) {
            throw new RuntimeException('Berkas daftar dosen tidak ditemukan: '.self::path());
        }

        $header = fgetcsv($handle, escape: '');

        while (($cells = fgetcsv($handle, escape: '')) !== false) {
            if ($cells === [null]) {
                continue;
            }

            $row = array_combine($header, $cells);
            $lecturer = Lecturer::query()->firstOrNew(['name' => $row['Nama di SICMA']]);

            if (! $lecturer->exists) {
                $lecturer->fill(['source_name' => $row['Nama'], 'is_active' => true]);
            }

            $lecturer->fill([
                'nip' => $row['NIP'],
                'homebase' => LecturerHomebase::Jtik,
                'profile_status' => LecturerProfileStatus::Verified,
                'rank' => Str::squish($row['Pangkat']),
                'grade' => Str::squish($row['Gol.']),
                'grade_since' => self::date($row['TMT Gol.']),
                'functional_position' => self::position($row['Jabatan']),
                'functional_position_since' => self::date($row['TMT Jab.']),
            ])->save();
        }

        fclose($handle);
    }

    /**
     * "01/06/2024" → "2024-06-01"; "-" → NULL.
     */
    private static function date(string $value): ?string
    {
        return preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', trim($value), $match) === 1 ? "{$match[3]}-{$match[2]}-{$match[1]}" : null;
    }

    /**
     * "LEKTOR KEPALA" → "Lektor Kepala"; "BELUM" → "Belum ada".
     */
    private static function position(string $value): string
    {
        $value = Str::squish($value);

        return Str::upper($value) === 'BELUM' ? 'Belum ada' : Str::title(Str::lower($value));
    }
}
