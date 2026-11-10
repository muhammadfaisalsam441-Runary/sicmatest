<?php

namespace App\Services\Academic;

use App\Enums\ImportStatus;
use App\Enums\LecturerHomebase;
use App\Enums\LecturerProfileStatus;
use App\Models\Academic\CourseOffering;
use App\Models\Academic\Period;
use App\Models\Lecturer\Lecturer;
use App\Models\Lecturer\LecturerExpertise;
use App\Models\Lecturer\TeachingAssignment;
use App\Models\Scheduling\Import;
use App\Services\Account\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
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
            $syncCourses = isset($data['course_selection_present']) || array_key_exists('course_ids', $data);
            if ($lecturer !== null && $syncCourses) {
                $lecturer = Lecturer::query()->lockForUpdate()->findOrFail($lecturer->id);
            }
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
            } elseif (array_key_exists('leadership_position', $data)) {
                $lecturer->leadershipTerms()->delete();
            }

            $period = Period::query()->active()->first();

            if ($period !== null && (array_key_exists('target_sks', $data) || array_key_exists('max_sks', $data))) {
                $lecturer->loadTargets()->updateOrCreate(
                    ['period_id' => $period->id],
                    ['target_sks' => $data['target_sks'] ?? null, 'max_sks' => $data['max_sks'] ?? null],
                );
            }

            if ($syncCourses) {
                $courseIds = array_map('intval', $data['course_ids'] ?? []);
                $manual = $lecturer->expertises()->where('field', LecturerExpertise::ADMIN_TEACHING);
                $previousIds = (clone $manual)->whereNotNull('course_id')->pluck('course_id')->all();
                $manual->whereNotIn('course_id', $courseIds)->delete();

                foreach (array_diff($courseIds, $previousIds) as $courseId) {
                    $lecturer->expertises()->create(['course_id' => $courseId, 'field' => LecturerExpertise::ADMIN_TEACHING]);
                }

                if (array_diff($courseIds, $previousIds) || array_diff($previousIds, $courseIds)) {
                    $this->audit->log('lecturer.teaching_courses_updated', $lecturer, ['course_ids' => $previousIds], ['course_ids' => $courseIds]);
                }
            }

            $this->audit->log($before === null ? 'lecturer.created' : 'lecturer.updated', $lecturer, $before, $lecturer->only(['name', 'nidn', 'homebase', 'profile_status']));

            return $lecturer;
        });
    }

    /**
     * @param  Collection<int, Lecturer>  $lecturers
     * @param  list<int>  $periodIds
     * @return array<int, array<string, list<int>>>
     */
    public function rosterInstructorIds(Collection $lecturers, array $periodIds, bool $lock = false): array
    {
        $importsByFile = Import::query()
            ->whereIn('period_id', $periodIds)
            ->where('status', ImportStatus::Committed)
            ->orderBy('id')
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->get(['period_id', 'original_name'])
            ->groupBy('original_name');
        $instructors = [];

        foreach ($lecturers as $lecturer) {
            foreach ($lecturer->source_data['rosters'] ?? [] as $file => $record) {
                $imports = $importsByFile->get($file);
                $courses = $record['raw']['MATA KULIAH YANG DIAMPU (SESUAI JADWAL)'] ?? null;
                if ($imports === null || ! is_string($courses)) {
                    continue;
                }

                foreach (preg_split('/\R/u', $courses) ?: [] as $line) {
                    $courseName = Str::lower(Str::squish(preg_replace('/^\s*[-•]\s*/u', '', $line) ?? ''));
                    if ($courseName === '') {
                        continue;
                    }

                    foreach ($imports as $import) {
                        $instructors[$import->period_id][$courseName][] = $lecturer->id;
                    }
                }
            }
        }

        return $instructors;
    }

    /**
     * @param  Collection<int, CourseOffering>  $offerings
     * @param  Collection<int, Lecturer>  $lecturers
     * @return array<int, list<int>>
     */
    public function instructorIdsByOffering(Collection $offerings, Collection $lecturers, bool $lock = false): array
    {
        $courseIds = $offerings->pluck('course_id')->unique();
        $manual = LecturerExpertise::query()->where('field', LecturerExpertise::ADMIN_TEACHING)
            ->whereIn('course_id', $courseIds)->orderBy('id')
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->get(['course_id', 'lecturer_id'])->groupBy('course_id');
        $rosters = $this->rosterInstructorIds($lecturers, $offerings->pluck('period_id')->unique()->all(), $lock);
        $current = TeachingAssignment::query()
            ->join('course_offerings', 'course_offerings.id', '=', 'teaching_assignments.course_offering_id')
            ->whereIn('course_offerings.course_id', $courseIds)
            ->whereIn('course_offerings.period_id', $offerings->pluck('period_id')->unique())
            ->select('course_offerings.period_id', 'course_offerings.course_id', 'teaching_assignments.lecturer_id')
            ->distinct()->get()
            ->groupBy(fn (TeachingAssignment $assignment): string => $assignment->period_id.':'.$assignment->course_id);

        return $offerings->mapWithKeys(function (CourseOffering $offering) use ($current, $manual, $rosters): array {
            $name = Str::lower(Str::squish($offering->course?->name ?? ''));
            $ids = $current->get($offering->period_id.':'.$offering->course_id, collect())->pluck('lecturer_id')
                ->merge($manual->get($offering->course_id, collect())->pluck('lecturer_id'))
                ->merge($rosters[$offering->period_id][$name] ?? []);

            return [$offering->id => $ids->unique()->sort()->values()->all()];
        })->all();
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
                    ->when(
                        $nidn,
                        fn ($query) => $query->where('nidn', $nidn),
                        fn ($query) => $query->where(fn ($sub) => $sub->where('name', $name)->orWhere('source_name', $name)),
                    )
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
