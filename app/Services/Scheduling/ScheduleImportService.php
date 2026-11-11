<?php

namespace App\Services\Scheduling;

use App\Enums\ImportRowStatus;
use App\Enums\ImportStatus;
use App\Enums\LecturerProfileStatus;
use App\Enums\ScheduleSource;
use App\Enums\SessionMode;
use App\Enums\SessionPhase;
use App\Enums\TeachingRole;
use App\Exceptions\DomainException;
use App\Models\Academic\ClassGroup;
use App\Models\Academic\Course;
use App\Models\Academic\CourseOffering;
use App\Models\Academic\Period;
use App\Models\Academic\TimeSlot;
use App\Models\Lecturer\Lecturer;
use App\Models\Lecturer\TeachingAssignment;
use App\Models\Room\Room;
use App\Models\Scheduling\Import;
use App\Models\Scheduling\ImportRow;
use App\Models\Scheduling\ScheduleSession;
use App\Models\Scheduling\ScheduleVersion;
use App\Models\User\User;
use App\Services\Account\AuditLogger;
use App\Services\Account\NotificationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

/**
 * Impor jadwal dari Excel/CSV: unggah → periksa hasil (perbedaan sumber) → simpan sebagai draf.
 *
 * Nilai sumber dipertahankan: jam yang tidak sama dengan slot hanya ditandai, "Hubungi Dosen" menjadi
 * sesi belum terjadwal (B15), nama dosen yang belum dikenal menjadi dosen "Perlu verifikasi" (B16).
 */
class ScheduleImportService
{
    /**
     * Kolom templat → kunci internal.
     *
     * @var array<string, string>
     */
    public const COLUMNS = [
        'kelas' => 'class',
        'kode mk' => 'course_code',
        'mata kuliah' => 'course_name',
        'hari' => 'day',
        'jam' => 'time',
        'ruang' => 'room',
        'dosen' => 'lecturers',
        'periode' => 'phase',
    ];

    /**
     * @var array<string, int>
     */
    private const DAYS = ['senin' => 1, 'selasa' => 2, 'rabu' => 3, 'kamis' => 4, 'jumat' => 5, "jum'at" => 5, 'sabtu' => 6, 'minggu' => 7];

    public function __construct(
        private ScheduleDraftService $drafts,
        private ScheduleValidator $validator,
        private AuditLogger $audit,
        private NotificationService $notifications,
    ) {}

    /**
     * Simpan dan baca file. File yang sama untuk semester yang sama tidak dibaca ulang (tanpa duplikasi).
     */
    public function store(UploadedFile $file, Period $period, User $actor): Import
    {
        $hash = hash_file('sha256', $file->getRealPath());

        $existing = Import::query()
            ->whereBelongsTo($period)
            ->where('file_hash', $hash)
            ->where('status', '!=', ImportStatus::Failed)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $path = $file->storeAs('imports', now()->format('Ymd_His').'_'.Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)).'.'.$file->getClientOriginalExtension());

        $import = Import::create([
            'period_id' => $period->id,
            'file_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'file_hash' => $hash,
            'status' => ImportStatus::Uploaded,
            'created_by' => $actor->id,
        ]);

        try {
            $rows = $this->readRows($file->getRealPath(), strtolower($file->getClientOriginalExtension()));
        } catch (Throwable $exception) {
            $import->update(['status' => ImportStatus::Failed, 'summary' => ['error' => 'File tidak dapat dibaca. Gunakan templat Excel atau CSV.']]);

            return $import;
        }

        $this->check($import, $period, $rows);

        return $import->fresh();
    }

    /**
     * Simpan baris yang tidak bermasalah ke draf baru. Memanggil ulang mengembalikan draf yang sama.
     */
    public function commit(Import $import, User $actor): ScheduleVersion
    {
        $import->refresh();

        if ($import->status === ImportStatus::Committed && $import->scheduleVersion !== null) {
            return $import->scheduleVersion;
        }

        if ($import->status !== ImportStatus::Checked) {
            throw new DomainException('DATA_INCOMPLETE', 'File impor belum berhasil diperiksa.');
        }

        $rows = $import->rows()->whereIn('status', [ImportRowStatus::Ok, ImportRowStatus::Warning])->orderBy('row_no')->get();

        if ($rows->isEmpty()) {
            throw new DomainException('DATA_INCOMPLETE', 'Tidak ada baris yang bisa disimpan ke draf.');
        }

        $draft = DB::transaction(function () use ($import, $rows): ScheduleVersion {
            $locked = Import::query()->whereKey($import->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === ImportStatus::Committed && $locked->scheduleVersion !== null) {
                return $locked->scheduleVersion;
            }

            $period = $import->period;
            $official = $period->scheduleVersions()->official()->latest('version_no')->first();
            $draft = $this->drafts->create($period, ScheduleSource::Import);
            $draft->forceFill(['based_on_version_id' => $official?->id])->save();

            $jointSessions = [];

            foreach ($rows as $row) {
                $this->createSession($draft, $period, $import, $row, $jointSessions);
            }

            $import->update(['status' => ImportStatus::Committed, 'schedule_version_id' => $draft->id]);

            return $draft;
        });

        $this->validator->validate($draft->fresh());
        $this->audit->log('import.committed', $import, null, ['schedule_version_id' => $draft->id, 'rows' => $rows->count()]);
        $this->notifications->notify($this->notifications->admins(), 'import.committed', 'Impor jadwal selesai', "{$import->original_name}: {$rows->count()} baris masuk ke draf v{$draft->version_no}. Periksa masalah draf sebelum menetapkan.", ['schedule_version_id' => $draft->id]);

        return $draft;
    }

    /**
     * Isi templat CSV (header + contoh).
     *
     * @return list<list<string>>
     */
    public function templateRows(): array
    {
        return [
            ['Kelas', 'Kode MK', 'Mata Kuliah', 'Hari', 'Jam', 'Ruang', 'Dosen', 'Periode'],
            ['Tekom C', 'TKM2301', 'Pemrograman Web', 'Senin', '07.30-10.00', 'AE 107', 'Rina Kartika, S.Kom., M.Kom.', 'Satu semester'],
            ['PTIK A', 'TKM2306', 'Kecerdasan Buatan', 'Selasa', '07.30-10.00', 'Online', 'Yusuf Habibi, S.T., M.T.; Sinta Amelia, S.T., M.T.', 'Satu semester'],
            ['PTIK B', 'TKM2305', 'Sistem Operasi', '', 'Hubungi Dosen', '', 'Bpk. Arman', ''],
        ];
    }

    /**
     * @return list<array{row_no: int, raw: array<string, string>}>
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
                $columns = [...self::COLUMNS, 'wita' => 'time', 'sks' => 'sks', 'dosen pengampu' => 'lecturer_lead', 'dosen mitra' => 'lecturer_partner', 'dosen mitra 2' => 'lecturer_partner_2', 'kategori' => 'category', 'angkatan' => 'category'];
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
     * @param  list<array{row_no: int, raw: array<string, string>}>  $rows
     */
    private function check(Import $import, Period $period, array $rows): void
    {
        $lookups = [
            'classes' => ClassGroup::query()->get(),
            'courses' => Course::query()->get(),
            'rooms' => Room::query()->get(),
            'lecturers' => Lecturer::query()->get(),
            'slots' => $period->timeSlots()->orderBy('start_minute')->get(),
        ];

        $seen = [];
        $summary = ['rows' => 0, 'offline' => 0, 'online' => 0, 'unscheduled' => 0, 'warnings' => 0, 'errors' => 0, 'skipped' => 0];

        DB::transaction(function () use ($import, $rows, $lookups, &$seen, &$summary): void {
            foreach ($rows as $row) {
                [$parsed, $issues] = $this->parse($row['raw'], $lookups);

                $key = implode('|', [$parsed['class_group_id'] ?? $row['raw']['class'], $parsed['course_id'] ?? $row['raw']['course_name'], $parsed['day'], $parsed['start_minute'], $parsed['end_minute'], $parsed['room_id'] ?? $row['raw']['room']]);

                if (isset($seen[$key])) {
                    $issues = [['level' => 'info', 'message' => 'Baris ganda dari baris '.$seen[$key].'; dilewati.']];
                    $status = ImportRowStatus::Skipped;
                } else {
                    $seen[$key] = $row['row_no'];
                    $levels = array_column($issues, 'level');
                    $status = match (true) {
                        in_array('error', $levels, true) => ImportRowStatus::Error,
                        in_array('warning', $levels, true) => ImportRowStatus::Warning,
                        default => ImportRowStatus::Ok,
                    };
                }

                $import->rows()->create([
                    'row_no' => $row['row_no'],
                    'raw' => $row['raw'],
                    'parsed' => $parsed,
                    'issues' => $issues,
                    'status' => $status,
                ]);

                $summary['rows']++;
                match ($status) {
                    ImportRowStatus::Error => $summary['errors']++,
                    ImportRowStatus::Skipped => $summary['skipped']++,
                    ImportRowStatus::Warning => $summary['warnings']++,
                    default => null,
                };

                if (in_array($status, [ImportRowStatus::Ok, ImportRowStatus::Warning], true)) {
                    $summary[match ($parsed['mode']) {
                        'online' => 'online', 'unscheduled' => 'unscheduled', default => 'offline'
                    }]++;
                }
            }

            $import->update(['status' => ImportStatus::Checked, 'summary' => $summary, 'column_mapping' => array_values(self::COLUMNS)]);
        });
    }

    /**
     * @param  array<string, string>  $raw
     * @param  array<string, Collection<int, mixed>>  $lookups
     * @return array{0: array<string, mixed>, 1: list<array{level: string, message: string}>}
     */
    private function parse(array $raw, array $lookups): array
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
    private function slotMismatches(int $start, int $end, Collection $slots): array
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

    /**
     * Baris luring dengan hari, jam, ruang, periode, dan mata kuliah yang sama adalah satu sesi gabungan
     * beberapa kelas (mis. kelas ULANG bergabung dengan kelas reguler), sehingga ruang hanya terblokir sekali (B12).
     *
     * @param  array<string, ScheduleSession>  $jointSessions
     */
    private function createSession(ScheduleVersion $draft, Period $period, Import $import, ImportRow $row, array &$jointSessions): void
    {
        $parsed = $row->parsed;
        if ($parsed['mode'] !== SessionMode::Unscheduled->value && ! in_array($parsed['day'], config('sicma.scheduler.days'), true)) {
            throw new DomainException('INVALID_TEACHING_DAY', 'Perkuliahan hanya boleh dijadwalkan Senin–Jumat. Periksa ulang file impor.');
        }

        $offering = CourseOffering::query()->firstOrCreate([
            'period_id' => $period->id,
            'course_id' => $parsed['course_id'],
            'class_group_id' => $parsed['class_group_id'],
        ]);

        $jointKey = $this->jointKey($parsed);

        if ($jointKey !== null && isset($jointSessions[$jointKey])) {
            $session = $jointSessions[$jointKey];
            $session->participants()->syncWithoutDetaching([$parsed['class_group_id']]);
            $this->attachLecturers($session, $offering, $parsed);

            return;
        }

        $session = $draft->sessions()->create([
            'course_offering_id' => $offering->id,
            'title' => $parsed['title'],
            'mode' => $parsed['mode'],
            'day_of_week' => $parsed['day'],
            'start_minute' => $parsed['start_minute'],
            'end_minute' => $parsed['end_minute'],
            'room_id' => $parsed['room_id'],
            'phase' => $parsed['phase'],
            'source_file' => $import->original_name,
            'source_row' => $row->row_no,
            'source_raw' => $parsed['mode'] === SessionMode::Unscheduled->value
                ? [...$row->raw, 'waktu' => $row->raw['time'] ?: 'Hubungi Dosen']
                : $row->raw,
        ]);

        $session->participants()->sync([$parsed['class_group_id']]);
        $this->attachLecturers($session, $offering, $parsed);

        if ($jointKey !== null) {
            $jointSessions[$jointKey] = $session;
        }
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function jointKey(array $parsed): ?string
    {
        if ($parsed['mode'] !== SessionMode::Offline->value || $parsed['room_id'] === null || $parsed['day'] === null || $parsed['start_minute'] === null) {
            return null;
        }

        return implode('|', [$parsed['day'], $parsed['start_minute'], $parsed['end_minute'], $parsed['room_id'], $parsed['phase'], Str::lower(Str::squish($parsed['title']))]);
    }

    /**
     * Peran dosen pada sesi gabungan mengikuti baris pertama; beban mengajar dicatat per kelas (penawaran).
     *
     * @param  array<string, mixed>  $parsed
     */
    private function attachLecturers(ScheduleSession $session, CourseOffering $offering, array $parsed): void
    {
        foreach ($parsed['lecturers'] as $entry) {
            $lecturerId = $entry['lecturer_id'] ?? Lecturer::query()->firstOrCreate(
                ['source_name' => $entry['name']],
                ['name' => $entry['name'], 'profile_status' => LecturerProfileStatus::Unverified, 'is_active' => true],
            )->id;

            if (! $session->lecturers()->whereKey($lecturerId)->exists()) {
                $session->lecturers()->attach($lecturerId, ['role' => $entry['role']]);
            }

            TeachingAssignment::query()->firstOrCreate(
                ['course_offering_id' => $offering->id, 'lecturer_id' => $lecturerId, 'phase' => $parsed['phase']],
                ['role' => $entry['role']],
            );
        }
    }
}
