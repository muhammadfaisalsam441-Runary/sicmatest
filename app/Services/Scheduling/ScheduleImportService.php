<?php

namespace App\Services\Scheduling;

use App\Enums\ImportRowStatus;
use App\Enums\ImportStatus;
use App\Enums\LecturerProfileStatus;
use App\Enums\ScheduleSource;
use App\Enums\SessionMode;
use App\Exceptions\DomainException;
use App\Models\Academic\ClassGroup;
use App\Models\Academic\Course;
use App\Models\Academic\CourseOffering;
use App\Models\Academic\Period;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
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

    public function __construct(
        private ScheduleDraftService $drafts,
        private ScheduleValidator $validator,
        private AuditLogger $audit,
        private NotificationService $notifications,
        private ?ScheduleImportTemplateBuilder $templateBuilder = null,
        private ?ScheduleImportRowParser $rowParser = null,
    ) {
        $this->templateBuilder ??= new ScheduleImportTemplateBuilder;
        $this->rowParser ??= new ScheduleImportRowParser;
    }

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
            if ($existing->status === ImportStatus::Checked && $existing->rows()->where('status', ImportRowStatus::Error)->exists()) {
                try {
                    $rows = $this->readRows($file->getRealPath(), strtolower($file->getClientOriginalExtension()));
                    $existing->rows()->delete();
                    $this->check($existing, $period, $rows);

                    return $existing->fresh();
                } catch (Throwable) {
                    // Fall back to returning existing import
                }
            }

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
     * Isi baris templat jadwal lengkap (header + contoh).
     *
     * @return list<list<string|int>>
     */
    public function templateRows(): array
    {
        return $this->templateBuilder->templateRows();
    }

    /**
     * Buat lembar kerja templat Excel (.xlsx) rapi dengan format resmi JTIK dan sheet referensi.
     */
    public function templateSpreadsheet(): Spreadsheet
    {
        return $this->templateBuilder->templateSpreadsheet();
    }

    /**
     * @return list<array{row_no: int, raw: array<string, string>}>
     */
    public function readRows(string $path, string $extension): array
    {
        return $this->rowParser->readRows($path, $extension);
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
                [$parsed, $issues] = $this->rowParser->parse($row['raw'], $lookups);

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
