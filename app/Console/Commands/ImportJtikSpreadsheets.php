<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\JtikSpreadsheetImportService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Throwable;

#[Signature('sicma:import-jtik
    {--directory= : Folder kedua spreadsheet sumber}
    {--admin= : Email admin yang dicatat sebagai pelaksana impor}
    {--preview : Periksa sumber tanpa menulis ke database}
    {--ganjil-start=2026-08-18}
    {--ganjil-end=2026-12-12}
    {--ganjil-uts-start=2026-10-12}
    {--ganjil-uts-end=2026-10-18}
    {--genap-start=}
    {--genap-end=}
')]
#[Description('Impor spreadsheet asli JTIK menjadi master akademik dan draf kedua semester; Ganjil 2026/2027 aktif')]
class ImportJtikSpreadsheets extends Command
{
    public function handle(JtikSpreadsheetImportService $service): int
    {
        try {
            $directory = rtrim($this->option('directory') ?: base_path(), '/\\');
            $sources = [];
            foreach (['Ganjil_2026_2027', 'Genap_2025_2026'] as $suffix) {
                $sources[] = $service->inspect($directory.DIRECTORY_SEPARATOR.'Jadwal_dan_Dosen_JTIK_UNM_'.$suffix.'.xlsx');
            }

            $this->table(['Semester', 'Baris jadwal', 'Kelas', 'Daftar dosen'], array_map(fn (array $source): array => [
                $source['period'], count($source['rows']), count(array_unique(array_column(array_column($source['rows'], 'raw'), 'class'))), count($source['roster']),
            ], $sources));

            if ($this->option('preview')) {
                $this->info('Pratinjau selesai. Tidak ada data yang ditulis.');

                return self::SUCCESS;
            }

            if (! Schema::hasColumn('lecturers', 'nip') || ! Schema::hasColumn('lecturers', 'source_data')) {
                throw new InvalidArgumentException('Jalankan php artisan migrate --no-interaction terlebih dahulu untuk metadata sumber dan kalender yang belum lengkap.');
            }

            $calendars = [
                'Ganjil 2026/2027' => [
                    'start_date' => $this->option('ganjil-start'), 'end_date' => $this->option('ganjil-end'),
                    'uts_start' => $this->option('ganjil-uts-start'), 'uts_end' => $this->option('ganjil-uts-end'),
                    'uas_start' => null, 'uas_end' => null,
                ],
            ];
            if ($this->option('genap-start') || $this->option('genap-end')) {
                $calendars['Genap 2025/2026'] = ['start_date' => $this->option('genap-start'), 'end_date' => $this->option('genap-end')];
            }
            foreach ($calendars as $calendar) {
                Validator::make($calendar, [
                    'start_date' => ['required', 'date_format:Y-m-d'],
                    'end_date' => ['required', 'date_format:Y-m-d', 'after:start_date'],
                    'uts_start' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date', 'before_or_equal:end_date'],
                    'uts_end' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:uts_start', 'before_or_equal:end_date'],
                ])->validate();
            }

            $admins = User::query()->whereIn('role', ['admin', 'super_admin'])->where('is_active', true);
            if ($email = $this->option('admin')) {
                $admins->where('email', $email);
            }
            $admin = $admins->orderByDesc('can_publish')->orderBy('id')->first();
            if ($admin === null) {
                throw new InvalidArgumentException('Admin aktif tidak ditemukan. Isi --admin dengan email admin yang sudah terdaftar.');
            }

            $results = $service->import($sources, $calendars, 'Ganjil 2026/2027', $admin);
            $this->table(['Semester', 'Baris sumber', 'Sesi tersimpan', 'Duplikat dilewati', 'Draf versi', 'Masalah wajib'], array_map(fn (array $result): array => array_values($result), $results));
            $this->info('Impor selesai. Ganjil 2026/2027 aktif. Kedua jadwal tetap draf dan tidak mengganti jadwal resmi.');
            $this->warn('Kalender Genap, UAS, pembagian periode mengajar, dan data fisik ruang yang belum dikonfirmasi tetap perlu dilengkapi.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
