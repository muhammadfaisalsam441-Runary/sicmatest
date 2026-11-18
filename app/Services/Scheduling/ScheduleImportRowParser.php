<?php

namespace App\Services\Scheduling;

use App\Enums\SessionMode;
use App\Enums\SessionPhase;
use App\Enums\TeachingRole;
use App\Exceptions\DomainException;
use App\Models\Academic\ClassGroup;
use App\Models\Academic\Course;
use App\Models\Academic\TimeSlot;
use App\Models\Lecturer\Lecturer;
use App\Models\Room\Room;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Pembaca file spreadsheet/CSV dan parser baris impor jadwal beserta deteksi perbedaan slot.
 */
class ScheduleImportRowParser
{
    /**
     * @var array<string, int>
     */
    private const DAYS = ['senin' => 1, 'selasa' => 2, 'rabu' => 3, 'kamis' => 4, 'jumat' => 5, "jum'at" => 5, 'sabtu' => 6, 'minggu' => 7];

    /**
     * Baca seluruh baris non-kosong dari file Excel/CSV berdasarkan header templat.
     *
     * @return list<array{row_no: int, raw: array<string, string>}>
     *
     * @throws DomainException
     */
    public function readRows(string $path, string $extension): array
    {
        $reader = IOFactory::createReader(match ($extension) {
            'csv', 'txt' => 'Csv',
            'xls' => 'Xls',
            'ods' => 'Ods',
            default => 'Xlsx',
        });
        $reader->setReadDataOnly(true);

        $workbook = $reader->load($path);
        $sheet = ($workbook->getSheetByName('Jadwal Perkuliahan') ?? $workbook->getActiveSheet())->toArray(null, false, false, false);
        $workbook->disconnectWorksheets();

        $headerIndex = null;
        $map = [];

        foreach ($sheet as $index => $cells) {
            $normalized = array_map(fn ($cell): string => Str::of((string) $cell)->lower()->squish()->toString(), $cells);

            if (in_array('kelas', $normalized, true) && (in_array('mata kuliah', $normalized, true) || in_array('kode mk', $normalized, true))) {
                $headerIndex = $index;
                $columns = [...ScheduleImportService::COLUMNS, 'fase' => 'phase', 'wita' => 'time', 'sks' => 'sks', 'dosen pengampu' => 'lecturer_lead', 'dosen mitra' => 'lecturer_partner', 'dosen mitra 2' => 'lecturer_partner_2', 'kategori' => 'category', 'angkatan' => 'category'];
                if (in_array('wita', $normalized, true)) {
                    $columns['jam'] = 'slot';
                }
                foreach ($normalized as $column => $name) {
                    if (isset($columns[$name])) {
                        $map[$column] = $columns[$name];
                    }
                }
                break;
            }
        }

        if ($headerIndex === null) {
            throw new DomainException('DATA_INCOMPLETE', 'Header templat tidak ditemukan.');
        }

        $rows = [];

        foreach (array_slice($sheet, $headerIndex + 1, preserve_keys: true) as $index => $cells) {
            $raw = [];
            foreach ($map as $column => $key) {
                $raw[$key] = trim((string) ($cells[$column] ?? ''));
            }

            if (($raw['class'] ?? '') === '' && ($raw['course_code'] ?? '') === '' && ($raw['course_name'] ?? '') === '') {
                continue;
            }

            if (array_key_exists('lecturer_lead', $raw)) {
                $raw['lecturers'] = implode('; ', array_filter([$raw['lecturer_lead'], $raw['lecturer_partner'] ?? '', $raw['lecturer_partner_2'] ?? '']));
            }

            $rows[] = ['row_no' => $index + 1, 'raw' => $raw];
        }

        return $rows;
    }

    /**
     * Parse satu baris mentah menjadi payload terstruktur dan daftar temuan/isu.
     *
     * @param  array<string, string>  $raw
     * @param  array<string, Collection<int, mixed>>  $lookups
     * @return array{0: array<string, mixed>, 1: list<array{level: string, message: string}>}
     */
    public function parse(array $raw, array $lookups): array
    {
        $issues = [];
        $same = fn (?string $a, ?string $b): bool => $a !== null && $b !== null && Str::lower(Str::squish($a)) === Str::lower(Str::squish($b));

        $class = $lookups['classes']->first(fn (ClassGroup $group): bool => $same($group->name, $raw['class'] ?? null) || $same($group->code, $raw['class'] ?? null)
            || $same($group->code, Str::slug($raw['class'] ?? '')));
        if ($class === null) {
            $issues[] = ['level' => 'error', 'message' => 'Kelas "'.($raw['class'] ?? '').'" belum terdata. Tambahkan di Data Akademik › Kelas.'];
        }

        $course = ($raw['course_code'] ?? '') !== ''
            ? $lookups['courses']->first(fn (Course $course): bool => $same($course->code, $raw['course_code']))
            : $lookups['courses']->first(fn (Course $course): bool => $same($course->name, $raw['course_name'] ?? null));
        if ($course === null) {
            $issues[] = ['level' => 'error', 'message' => 'Mata kuliah "'.($raw['course_code'] ?? '').' '.($raw['course_name'] ?? '').'" belum terdata.'];
        }

        $mode = SessionMode::Offline;
        $day = null;
        $start = null;
        $end = null;
        $time = $raw['time'] ?? '';

        if ($time === '' || Str::contains(Str::lower($time), 'hubungi')) {
            $mode = SessionMode::Unscheduled;
            $issues[] = ['level' => 'warning', 'message' => ($time === '' ? 'Jam kosong' : 'Hubungi Dosen').' • tanpa waktu/ruang. Disimpan sebagai sesi belum terjadwal.'];
        } elseif (preg_match('/(\d{1,2})[.:](\d{2})\s*[-–—]\s*(\d{1,2})[.:](\d{2})/u', $time, $match) === 1) {
            $start = (int) $match[1] * 60 + (int) $match[2];
            $end = (int) $match[3] * 60 + (int) $match[4];

            if ($end <= $start) {
                $issues[] = ['level' => 'error', 'message' => "Jam selesai harus setelah jam mulai ({$time})."];
            }

            foreach ($this->slotMismatches($start, $end, $lookups['slots']) as $message) {
                $issues[] = ['level' => 'warning', 'message' => $message];
            }
        } else {
            $issues[] = ['level' => 'error', 'message' => "Format jam \"{$time}\" tidak terbaca. Gunakan 07.30-10.00."];
        }

        if ($mode !== SessionMode::Unscheduled) {
            $day = self::DAYS[Str::lower(Str::squish($raw['day'] ?? ''))] ?? null;
            if ($day === null) {
                $issues[] = ['level' => 'error', 'message' => 'Hari "'.($raw['day'] ?? '').'" tidak dikenali.'];
            } elseif (! in_array($day, config('sicma.scheduler.days'), true)) {
                $issues[] = ['level' => 'error', 'message' => 'Perkuliahan hanya boleh dijadwalkan Senin–Jumat. Hari "'.($raw['day'] ?? '').'" tidak diizinkan.'];
            } elseif ($day === 5 && $start !== null && $end !== null) {
                $fridayBreak = config('sicma.scheduler.friday_break', ['start' => 720, 'end' => 790]);
                if ($start < $fridayBreak['end'] && $end > $fridayBreak['start']) {
                    $issues[] = ['level' => 'warning', 'message' => 'Pada hari Jumat pukul 12.00–13.10 tidak ada perkuliahan; sesi perlu dijadwalkan ulang di draf.'];
                }
            }
        }

        $roomName = $raw['room'] ?? '';
        $room = null;

        if (in_array(Str::lower($roomName), ['online', 'daring'], true)) {
            if ($mode === SessionMode::Offline) {
                $mode = SessionMode::Online;
                $issues[] = ['level' => 'info', 'message' => 'ONLINE • tetap diperiksa waktunya, tidak memesan ruang.'];
            }
        } elseif ($roomName !== '' && $mode === SessionMode::Offline) {
            $room = $lookups['rooms']->first(fn (Room $room): bool => $same($room->name, $roomName) || $same($room->code, $roomName));
            if ($room === null) {
                $issues[] = ['level' => 'warning', 'message' => "Ruang \"{$roomName}\" belum terdata; sesi masuk draf tanpa ruang."];
            }
        } elseif ($mode === SessionMode::Offline) {
            $issues[] = ['level' => 'warning', 'message' => 'Ruang kosong; sesi masuk draf tanpa ruang.'];
        }

        $entries = array_key_exists('lecturer_lead', $raw)
            ? collect(['lecturer_lead' => TeachingRole::Lead->value, 'lecturer_partner' => TeachingRole::Partner->value, 'lecturer_partner_2' => TeachingRole::Partner->value])
                ->map(fn (string $role, string $key): array => ['name' => Str::squish($raw[$key] ?? ''), 'role' => $role])->filter(fn (array $entry): bool => $entry['name'] !== '')->values()
            : collect(preg_split('/\s*[;\n]\s*/', $raw['lecturers'] ?? '') ?: [])->map(fn ($name): string => Str::squish($name))->filter()->values()
                ->map(fn (string $name, int $index): array => ['name' => $name, 'role' => $index === 0 ? TeachingRole::Lead->value : TeachingRole::Partner->value]);
        $names = $entries->pluck('name');
        $lecturers = $entries->map(function (array $entry) use ($lookups, $same, &$issues): array {
            $name = $entry['name'];
            $lecturer = $lookups['lecturers']->first(fn (Lecturer $lecturer): bool => $same($lecturer->name, $name) || $same($lecturer->source_name, $name)
                || collect($lecturer->source_data['aliases'] ?? [])->contains(fn (string $alias): bool => $same($alias, $name)));

            if ($lecturer === null) {
                $issues[] = ['level' => 'warning', 'message' => "Nama dosen \"{$name}\" belum terpetakan; akan ditambahkan sebagai Perlu verifikasi."];
            }

            return ['name' => $name, 'lecturer_id' => $lecturer?->id, 'role' => $entry['role']];
        })->all();

        if ($names->isEmpty()) {
            $issues[] = ['level' => 'warning', 'message' => 'Dosen belum diisi.'];
        } elseif ($names->count() > 1) {
            $issues[] = ['level' => 'info', 'message' => 'Satu pengampu dan '.($names->count() - 1).' mitra.'];
        }

        $phaseText = Str::lower($raw['phase'] ?? '');
        $phase = match (true) {
            Str::contains($phaseText, 'sebelum') => SessionPhase::BeforeUts,
            Str::contains($phaseText, 'setelah') => SessionPhase::AfterUts,
            default => SessionPhase::Full,
        };

        return [[
            'class_group_id' => $class?->id,
            'course_id' => $course?->id,
            'title' => ($raw['course_name'] ?? '') !== '' ? $raw['course_name'] : ($course?->name ?? ''),
            'mode' => $mode->value,
            'day' => $day,
            'start_minute' => $mode === SessionMode::Unscheduled ? null : $start,
            'end_minute' => $mode === SessionMode::Unscheduled ? null : $end,
            'room_id' => $room?->id,
            'lecturers' => $lecturers,
            'phase' => $phase->value,
        ], $issues];
    }

    /**
     * Beda jam dengan slot hanya ditandai, tidak dikoreksi otomatis (mis. 15.45 vs 15.40).
     *
     * @param  Collection<int, TimeSlot>  $slots
     * @return list<string>
     */
    public function slotMismatches(int $start, int $end, Collection $slots): array
    {
        if ($slots->isEmpty()) {
            return [];
        }

        $format = fn (int $minutes): string => sprintf('%02d.%02d', intdiv($minutes, 60), $minutes % 60);
        $messages = [];

        if (! $slots->contains('start_minute', $start)) {
            $nearest = $slots->sortBy(fn (TimeSlot $slot): int => abs($slot->start_minute - $start))->first();
            $messages[] = 'Jam mulai '.$format($start).' tidak sama dengan slot '.$nearest->label.' ('.$format($nearest->start_minute).'). Nilai sumber dipertahankan.';
        }

        if (! $slots->contains('end_minute', $end)) {
            $nearest = $slots->sortBy(fn (TimeSlot $slot): int => abs($slot->end_minute - $end))->first();
            $messages[] = 'Jam selesai '.$format($end).' tidak sama dengan akhir slot '.$nearest->label.' ('.$format($nearest->end_minute).'). Nilai sumber dipertahankan.';
        }

        return $messages;
    }
}
