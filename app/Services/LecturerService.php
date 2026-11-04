<?php

namespace App\Services;

use App\Enums\LecturerHomebase;
use App\Enums\LecturerProfileStatus;
use App\Models\Lecturer;
use App\Models\Period;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Data dosen: simpan profil, masa jabatan pimpinan (B11), target beban, dan impor dari spreadsheet.
 */
class LecturerService
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function save(?Lecturer $lecturer, array $data): Lecturer
    {
        return DB::transaction(function () use ($lecturer, $data): Lecturer {
            $before = $lecturer?->only(['name', 'nidn', 'homebase', 'profile_status']);
            $lecturer ??= new Lecturer(['is_active' => true]);

            $lecturer->fill([
                'name' => $data['name'],
                'nidn' => $data['nidn'] ?? null,
                'homebase' => isset($data['homebase']) ? LecturerHomebase::from($data['homebase']) : null,
                'profile_status' => LecturerProfileStatus::from($data['profile_status']),
            ])->save();

            if (! empty($data['leadership_position'])) {
                $lecturer->leadershipTerms()->updateOrCreate(
                    ['position' => $data['leadership_position']],
                    ['valid_from' => $data['leadership_from'], 'valid_to' => $data['leadership_to'] ?? null],
                );
            }

            $period = Period::query()->active()->first();

            if ($period !== null && (array_key_exists('target_sks', $data) || array_key_exists('max_sks', $data))) {
                $lecturer->loadTargets()->updateOrCreate(
                    ['period_id' => $period->id],
                    ['target_sks' => $data['target_sks'] ?? null, 'max_sks' => $data['max_sks'] ?? null],
                );
            }

            $this->audit->log($before === null ? 'lecturer.created' : 'lecturer.updated', $lecturer, $before, $lecturer->only(['name', 'nidn', 'homebase', 'profile_status']));

            return $lecturer;
        });
    }

    /**
     * Impor kolom Nama, NIDN, Homebase (JTIK/Luar) dari Excel/CSV. Dosen dengan NIDN dianggap terverifikasi.
     *
     * @return array{created: int, updated: int}
     */
    public function import(UploadedFile $file): array
    {
        $reader = IOFactory::createReader(in_array(strtolower($file->getClientOriginalExtension()), ['csv', 'txt'], true) ? 'Csv' : 'Xlsx');
        $rows = $reader->load($file->getRealPath())->getActiveSheet()->toArray(null, true, false, false);

        $header = array_map(fn ($cell): string => Str::lower(Str::squish((string) $cell)), array_shift($rows) ?? []);
        $column = fn (string $name): ?int => ($index = array_search($name, $header, true)) === false ? null : $index;
        [$nameColumn, $nidnColumn, $homebaseColumn] = [$column('nama'), $column('nidn'), $column('homebase')];

        $result = ['created' => 0, 'updated' => 0];

        if ($nameColumn === null) {
            return $result;
        }

        DB::transaction(function () use ($rows, $nameColumn, $nidnColumn, $homebaseColumn, &$result): void {
            foreach ($rows as $cells) {
                $name = Str::squish((string) ($cells[$nameColumn] ?? ''));

                if ($name === '') {
                    continue;
                }

                $nidn = $nidnColumn !== null ? (trim((string) ($cells[$nidnColumn] ?? '')) ?: null) : null;
                $homebaseText = Str::lower((string) ($homebaseColumn !== null ? ($cells[$homebaseColumn] ?? '') : ''));
                $homebase = match (true) {
                    Str::contains($homebaseText, 'jtik') => LecturerHomebase::Jtik,
                    Str::contains($homebaseText, 'luar') => LecturerHomebase::External,
                    default => null,
                };

                $lecturer = Lecturer::query()
                    ->when($nidn, fn ($query) => $query->where('nidn', $nidn), fn ($query) => $query->where('name', $name)->orWhere('source_name', $name))
                    ->first();

                $attributes = array_filter([
                    'name' => $name,
                    'nidn' => $nidn,
                    'homebase' => $homebase,
                    'profile_status' => $nidn ? LecturerProfileStatus::Verified : null,
                ], fn ($value): bool => $value !== null);

                if ($lecturer === null) {
                    Lecturer::create([...$attributes, 'profile_status' => $attributes['profile_status'] ?? LecturerProfileStatus::Unverified, 'is_active' => true]);
                    $result['created']++;
                } else {
                    $lecturer->update($attributes);
                    $result['updated']++;
                }
            }
        });

        return $result;
    }
}
