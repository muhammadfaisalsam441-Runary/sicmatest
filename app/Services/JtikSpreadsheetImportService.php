<?php

namespace App\Services;

use App\Enums\LecturerHomebase;
use App\Enums\LecturerProfileStatus;
use App\Enums\RoomType;
use App\Models\ClassGroup;
use App\Models\Course;
use App\Models\Lecturer;
use App\Models\Period;
use App\Models\Room;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Baca data akademik sumber JTIK, siapkan master, lalu gunakan alur impor draf yang sama dengan portal admin.
 */
class JtikSpreadsheetImportService
{
    /** @var Collection<int, Lecturer>|null */
    private ?Collection $lecturers = null;

    public function __construct(private ScheduleImportService $imports, private ScheduleValidator $validator) {}

    /**
     * @return array{path: string, name: string, period: string, rows: list<array{row_no: int, raw: array<string, string>}>, roster: list<array<string, mixed>>, rooms: list<string>, slots: list<array<string, mixed>>}
     */
    public function inspect(string $path): array
    {
        if (! is_file($path) || preg_match('/(Ganjil|Genap)[_\s-]*(\d{4})[_\/-](\d{4})/i', basename($path), $period) !== 1) {
            throw new InvalidArgumentException('Nama file harus memuat semester dan tahun, misalnya Ganjil_2026_2027.xlsx.');
        }

        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly(['Daftar Dosen', 'Matriks Ruangan', 'Master Ruangan']);
        $book = $reader->load($path);
        $roster = [];
        $rosterRows = $book->getSheetByName('Daftar Dosen')?->toArray(null, false, false, false) ?? [];
        $headers = $rosterRows[3] ?? [];
        foreach (array_slice($rosterRows, 4, preserve_keys: true) as $index => $cells) {
            if (! is_numeric($cells[0] ?? null) || trim((string) ($cells[1] ?? '')) === '') {
                continue;
            }
            $raw = [];
            foreach ($headers as $column => $label) {
                if ($label !== null && $label !== '') {
                    $raw[(string) $label] = $cells[$column] ?? null;
                }
            }
            $nip = trim((string) ($cells[2] ?? ''));
            $roster[] = ['row_no' => $index + 1, 'name' => Str::squish((string) $cells[1]), 'nip' => preg_match('/^\d{18}$/', $nip) === 1 ? $nip : null, 'raw' => $raw];
        }

        $rooms = [];
        foreach (array_slice($book->getSheetByName('Master Ruangan')?->toArray(null, false, false, false) ?? [], 4) as $cells) {
            if (is_numeric($cells[0] ?? null) && trim((string) ($cells[2] ?? '')) !== '') {
                $rooms[] = Str::squish((string) $cells[2]);
            }
        }

        $slots = [];
        $matrix = $book->getSheetByName('Matriks Ruangan')?->rangeToArray('C4:M4', null, false, false)[0] ?? [];
        $roman = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI'];
        foreach ($matrix as $index => $time) {
            if (preg_match('/^(\d{1,2})[.:](\d{2})\s*[-–—]\s*(\d{1,2})[.:](\d{2})$/u', trim((string) $time), $match) === 1) {
                $slots[] = ['label' => $roman[$index], 'start_minute' => (int) $match[1] * 60 + (int) $match[2], 'end_minute' => (int) $match[3] * 60 + (int) $match[4]];
            }
        }
        $book->disconnectWorksheets();

        return [
            'path' => realpath($path),
            'name' => basename($path),
            'period' => ucfirst(strtolower($period[1])).' '.$period[2].'/'.$period[3],
            'rows' => $this->imports->readRows($path, 'xlsx'),
            'roster' => $roster,
            'rooms' => $rooms,
            'slots' => $slots,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $sources
     * @param  array<string, array<string, string|null>>  $calendars
     * @return list<array<string, mixed>>
     */
    public function import(array $sources, array $calendars, string $activeName, User $actor): array
    {
        if (! $actor->isAdmin() || ! $actor->is_active) {
            throw new InvalidArgumentException('Impor harus dijalankan atas nama admin aktif.');
        }
        if (! collect($sources)->contains('period', $activeName)) {
            throw new InvalidArgumentException('Semester aktif harus tersedia dalam file sumber.');
        }

        return DB::transaction(function () use ($sources, $calendars, $activeName, $actor): array {
            $this->lecturers = Lecturer::query()->get()->keyBy('id');
            foreach ($sources as $source) {
                $this->synchronizeMasters($source);
            }

            $results = [];
            foreach ($sources as $source) {
                $period = Period::query()->firstOrCreate(['name' => $source['period']], [
                    'start_date' => null, 'end_date' => null, 'timezone' => config('sicma.timezone'), 'is_active' => false,
                ]);
                if (isset($calendars[$source['period']])) {
                    $period->fill($calendars[$source['period']])->save();
                }
                if ($source['period'] === $activeName && ($period->start_date === null || $period->end_date === null)) {
                    throw new InvalidArgumentException('Kalender semester aktif harus dilengkapi. Semester historis boleh tetap kosong sebagai draf.');
                }
                foreach ($source['slots'] as $slot) {
                    $period->timeSlots()->updateOrCreate(['label' => $slot['label']], $slot);
                }

                $file = new UploadedFile($source['path'], $source['name'], 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
                $import = $this->imports->store($file, $period, $actor);
                if (($import->summary['errors'] ?? 0) > 0 || $import->status->value === 'failed') {
                    throw new InvalidArgumentException('Sumber '.$source['name'].' memiliki baris yang tidak dapat dimasukkan. Tidak ada data yang disimpan.');
                }
                $alreadyCommitted = $import->status->value === 'committed';
                $draft = $this->imports->commit($import, $actor);
                if ($alreadyCommitted) {
                    $this->validator->validate($draft);
                }
                $import->update(['summary' => [...$import->summary,
                    'source_rows' => count($source['rows']),
                    'source_sheet' => 'Jadwal Perkuliahan',
                    'physical_room_data_unverified' => true,
                    'teaching_phase_unconfirmed' => true,
                    'roster_rows' => count($source['roster']),
                ]]);

                $results[] = ['semester' => $period->name, 'rows' => count($source['rows']), 'sessions' => $draft->sessions()->count(), 'skipped' => $import->summary['skipped'] ?? 0, 'draft' => $draft->version_no, 'blocking' => $draft->issues()->blocking()->count()];
            }

            Period::query()->where('is_active', true)->update(['is_active' => false]);
            Period::query()->where('name', $activeName)->update(['is_active' => true]);

            return $results;
        });
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function synchronizeMasters(array $source): void
    {
        foreach ($source['roster'] as $record) {
            $lecturer = $record['nip'] !== null ? Lecturer::query()->where('nip', $record['nip'])->first() : null;
            $lecturer ??= $this->findLecturer($record['name']);
            if ($record['nip'] !== null && $lecturer?->nip !== null && $lecturer->nip !== $record['nip']) {
                $lecturer = null;
            }
            $lecturer ??= new Lecturer(['name' => $record['name'], 'source_name' => $record['name'], 'profile_status' => LecturerProfileStatus::Unverified, 'homebase' => LecturerHomebase::Jtik, 'is_active' => true]);
            if ($record['nip'] !== null && $lecturer->nip === null) {
                $lecturer->nip = $record['nip'];
            }
            $metadata = $lecturer->source_data ?? [];
            $metadata['rosters'][$source['name']] = ['row_no' => $record['row_no'], 'raw' => $record['raw']];
            $metadata['aliases'] = array_values(array_unique([...($metadata['aliases'] ?? []), $record['name']]));
            $lecturer->source_data = $metadata;
            $lecturer->save();
            $this->lecturers->put($lecturer->id, $lecturer);
        }

        $roomNames = $source['rooms'];
        foreach ($source['rows'] as $row) {
            $raw = $row['raw'];
            $className = Str::squish($raw['class'] ?? '');
            $courseCode = trim($raw['course_code'] ?? '');
            if ($className === '' || $courseCode === '' || ! is_numeric($raw['sks'] ?? null)) {
                throw new InvalidArgumentException($source['name'].' baris '.$row['row_no'].': kelas, kode mata kuliah, atau SKS belum lengkap.');
            }

            preg_match('/\b(20\d{2})\b/', $className, $cohort);
            ClassGroup::query()->firstOrCreate(['code' => Str::upper(Str::slug($className))], [
                'name' => $className, 'study_program' => Str::startsWith(Str::upper($className), 'PTIK') ? 'PTIK' : 'TEKOM',
                'cohort_year' => isset($cohort[1]) ? (int) $cohort[1] : null, 'student_count' => null, 'is_active' => true,
            ]);
            Course::query()->firstOrCreate(['code' => $courseCode], ['name' => $raw['course_name'], 'sks' => (int) $raw['sks']]);

            $roomNames[] = $raw['room'] ?? '';
            foreach (['lecturer_lead', 'lecturer_partner', 'lecturer_partner_2'] as $key) {
                $name = Str::squish($raw[$key] ?? '');
                if ($name === '') {
                    continue;
                }
                $lecturer = $this->findLecturer($name) ?? new Lecturer(['name' => $name, 'source_name' => $name, 'profile_status' => LecturerProfileStatus::Unverified, 'is_active' => true]);
                $metadata = $lecturer->source_data ?? [];
                $metadata['aliases'] = array_values(array_unique([...($metadata['aliases'] ?? []), $name]));
                $lecturer->source_data = $metadata;
                $lecturer->save();
                $this->lecturers->put($lecturer->id, $lecturer);
            }
        }

        foreach (array_unique($roomNames) as $name) {
            $name = Str::squish($name);
            if ($name === '' || in_array(Str::lower($name), ['online', 'daring', '-', 'hubungi dosen'], true)) {
                continue;
            }
            $code = Str::upper(Str::slug($name));
            if (Room::query()->where('code', $code)->orWhere('name', $name)->exists()) {
                continue;
            }
            Room::query()->create(['name' => $name, 'code' => $code, 'type' => Str::startsWith(Str::lower($name), 'lab ') ? RoomType::Lab : RoomType::Other, 'capacity' => null, 'floor' => null, 'is_bookable' => true, 'is_active' => true]);
        }
    }

    private function findLecturer(string $name): ?Lecturer
    {
        $lecturers = $this->lecturers ??= Lecturer::query()->get()->keyBy('id');
        $exact = $lecturers->first(fn (Lecturer $lecturer): bool => Str::lower($lecturer->name) === Str::lower($name)
            || Str::lower($lecturer->source_name ?? '') === Str::lower($name)
            || in_array($name, $lecturer->source_data['aliases'] ?? [], true));
        if ($exact !== null) {
            return $exact;
        }

        $matches = $lecturers->filter(fn (Lecturer $lecturer): bool => $this->nameKey($lecturer->name) === $this->nameKey($name));

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function nameKey(string $name): string
    {
        $base = Str::upper(Str::ascii(Str::before($name, ',')));
        $base = trim(preg_replace('/[^A-Z0-9]+/', ' ', $base));
        $tokens = preg_split('/\s+/', $base) ?: [];
        while ($tokens !== [] && in_array($tokens[0], ['PROF', 'DR', 'DRS', 'DRA', 'IR', 'H', 'HJ', 'ENG'], true)) {
            array_shift($tokens);
        }

        return implode(' ', array_map(fn (string $token): string => match ($token) {
            'ABD' => 'ABDUL', 'MUH' => 'MUHAMMAD', default => $token,
        }, $tokens));
    }
}
