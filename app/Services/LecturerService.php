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
 * Data dosen: simpan profil & data kepegawaian, masa jabatan pimpinan (B11), target beban, dan impor dari spreadsheet.
 */
class LecturerService
{
    /**
     * Kolom profil yang dicatat di audit log.
     *
     * @var list<string>
     */
    private const AUDITED = ['name', 'nidn', 'nip', 'homebase', 'rank', 'grade', 'functional_position', 'profile_status'];

    /**
     * Kolom data kepegawaian yang hanya diubah bila dikirim formulir.
     *
     * @var list<string>
     */
    private const OPTIONAL = ['nip', 'rank', 'grade', 'functional_position'];

    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function save(?Lecturer $lecturer, array $data): Lecturer
    {
        return DB::transaction(function () use ($lecturer, $data): Lecturer {
            $before = $lecturer?->only(self::AUDITED);
            $lecturer ??= new Lecturer(['is_active' => true]);

            $lecturer->fill([
                'name' => $data['name'],
                'nidn' => $data['nidn'] ?? null,
                'homebase' => isset($data['homebase']) ? LecturerHomebase::from($data['homebase']) : null,
                'profile_status' => LecturerProfileStatus::from($data['profile_status']),
                ...array_intersect_key($data, array_flip(self::OPTIONAL)),
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

            $this->audit->log($before === null ? 'lecturer.created' : 'lecturer.updated', $lecturer, $before, $lecturer->only(self::AUDITED));

            return $lecturer;
        });
    }

    /**
     * Impor kolom Nama, NIP, NIDN, Homebase (JTIK/Luar), Pangkat, Gol., Jabatan dari Excel/CSV.
     * Baris dicocokkan lewat NIP, lalu NIDN, lalu nama. Dosen dengan NIP atau NIDN dianggap terverifikasi.
     *
     * @return array{created: int, updated: int}
     */
    public function import(UploadedFile $file): array
    {
        $reader = IOFactory::createReader(in_array(strtolower($file->getClientOriginalExtension()), ['csv', 'txt'], true) ? 'Csv' : 'Xlsx');
        $rows = $reader->load($file->getRealPath())->getActiveSheet()->toArray(null, true, false, false);

        $header = array_map(fn ($cell): string => Str::lower(Str::squish((string) $cell)), array_shift($rows) ?? []);
        $column = function (string ...$names) use ($header): ?int {
            foreach ($names as $name) {
                if (($index = array_search($name, $header, true)) !== false) {
                    return $index;
                }
            }

            return null;
        };

        $columns = [
            'name' => $column('nama'),
            'nip' => $column('nip'),
            'nidn' => $column('nidn'),
            'homebase' => $column('homebase', 'asal'),
            'rank' => $column('pangkat'),
            'grade' => $column('gol.', 'gol', 'golongan'),
            'functional_position' => $column('jabatan', 'jabatan fungsional'),
        ];

        $result = ['created' => 0, 'updated' => 0];

        if ($columns['name'] === null) {
            return $result;
        }

        DB::transaction(function () use ($rows, $columns, &$result): void {
            foreach ($rows as $cells) {
                $value = fn (string $key): ?string => $columns[$key] === null ? null : (Str::squish((string) ($cells[$columns[$key]] ?? '')) ?: null);
                $name = $value('name');

                if ($name === null) {
                    continue;
                }

                $nip = preg_replace('/\D/', '', (string) $value('nip')) ?: null;
                $nidn = $value('nidn');
                $homebaseText = Str::lower((string) $value('homebase'));
                $homebase = match (true) {
                    Str::contains($homebaseText, 'luar') => LecturerHomebase::External,
                    Str::contains($homebaseText, 'jtik') => LecturerHomebase::Jtik,
                    default => null,
                };
                $position = $value('functional_position');

                $lecturer = match (true) {
                    $nip !== null && ($found = Lecturer::query()->where('nip', $nip)->first()) !== null => $found,
                    $nidn !== null && ($found = Lecturer::query()->where('nidn', $nidn)->first()) !== null => $found,
                    default => Lecturer::query()->where('name', $name)->orWhere('source_name', $name)->first(),
                };

                $attributes = array_filter([
                    'nip' => $nip,
                    'nidn' => $nidn,
                    'homebase' => $homebase,
                    'rank' => $value('rank'),
                    'grade' => $value('grade'),
                    'functional_position' => $position !== null && Str::upper($position) === 'BELUM' ? 'Belum ada' : $position,
                    'profile_status' => ($nip ?? $nidn) !== null ? LecturerProfileStatus::Verified : null,
                ], fn ($attribute): bool => $attribute !== null);

                if ($lecturer === null) {
                    Lecturer::create([
                        'name' => $name,
                        ...$attributes,
                        'profile_status' => $attributes['profile_status'] ?? LecturerProfileStatus::Unverified,
                        'is_active' => true,
                    ]);
                    $result['created']++;
                } else {
                    // Nama dari jadwal dipertahankan bila baris cocok lewat NIP/NIDN.
                    $lecturer->update($nip !== null || $nidn !== null ? $attributes : ['name' => $name, ...$attributes]);
                    $result['updated']++;
                }
            }
        });

        return $result;
    }
}
