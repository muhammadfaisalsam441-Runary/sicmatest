<?php

namespace App\Services\Academic;

use App\Models\Academic\ClassGroup;
use App\Models\Academic\CourseOffering;
use App\Models\Academic\Period;
use App\Models\User\ClassRepresentative;
use App\Models\User\User;
use App\Services\Account\AuditLogger;
use App\Services\Account\KetiApprovalService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pengelolaan siklus hidup angkatan kelas mahasiswa (kelulusan & pembukaan angkatan baru).
 */
class CohortLifecycleService
{
    public function __construct(
        private AuditLogger $audit,
        private KetiApprovalService $ketiApprovalService,
    ) {}

    /**
     * Preview dampak kelulusan angkatan: daftar kelas aktif, daftar Keti aktif, dan penawaran kuliah berjalan.
     *
     * @return array{
     *   cohort_year: int,
     *   classes: Collection<int, array{id: int, name: string, code: string, study_program: string, student_count: ?int, is_active: bool}>,
     *   active_classes_count: int,
     *   active_ketis: Collection<int, array{id: int, name: string, nim: ?string, email: string, class_name: string}>,
     *   active_ketis_count: int,
     *   active_offerings_count: int
     * }
     */
    public function getArchivePreview(int $cohortYear): array
    {
        $classes = ClassGroup::query()
            ->where('cohort_year', $cohortYear)
            ->orderBy('name')
            ->get();

        $classIds = $classes->pluck('id')->all();

        $activeReps = ClassRepresentative::query()
            ->whereIn('class_group_id', $classIds)
            ->activeOn(now())
            ->with(['user', 'classGroup'])
            ->get();

        $activeKetis = $activeReps
            ->filter(fn (ClassRepresentative $rep): bool => $rep->user !== null)
            ->map(fn (ClassRepresentative $rep): array => [
                'id' => (int) $rep->user->id,
                'name' => (string) $rep->user->name,
                'nim' => $rep->user->nim,
                'email' => (string) $rep->user->email,
                'class_name' => (string) ($rep->classGroup?->name ?? ''),
            ])
            ->values();

        $activePeriod = Period::query()->active()->first();

        $activeOfferingsCount = CourseOffering::query()
            ->whereIn('class_group_id', $classIds)
            ->when($activePeriod !== null, fn ($q) => $q->where('period_id', $activePeriod->id))
            ->count();

        return [
            'cohort_year' => $cohortYear,
            'classes' => $classes->map(fn (ClassGroup $c): array => [
                'id' => (int) $c->id,
                'name' => (string) $c->name,
                'code' => (string) $c->code,
                'study_program' => (string) $c->study_program,
                'student_count' => $c->student_count,
                'is_active' => (bool) $c->is_active,
            ]),
            'active_classes_count' => $classes->where('is_active', true)->count(),
            'active_ketis' => $activeKetis,
            'active_ketis_count' => $activeKetis->unique('id')->count(),
            'active_offerings_count' => $activeOfferingsCount,
        ];
    }

    /**
     * Luluskan dan arsipkan angkatan:
     * 1. Set is_active = false untuk semua kelas pada angkatan tersebut.
     * 2. Nonaktifkan semua ClassRepresentative aktif (valid_to = now()->subDay()->toDateString()).
     * 3. Jika deactivateKetis = true, nonaktifkan akun user Keti terkait (is_active = false) & catat audit 'keti.deactivated'.
     * 4. Catat audit 'cohort.graduated' dengan detail kelas & keti yang terdampak.
     *
     * @return array{
     *   cohort_year: int,
     *   archived_classes_count: int,
     *   deactivated_ketis_count: int
     * }
     */
    public function graduateCohort(int $cohortYear, User $admin, ?string $reason = null, bool $deactivateKetis = true): array
    {
        return DB::transaction(function () use ($cohortYear, $admin, $reason, $deactivateKetis): array {
            $classGroups = ClassGroup::query()
                ->where('cohort_year', $cohortYear)
                ->lockForUpdate()
                ->get();

            if ($classGroups->isEmpty()) {
                return [
                    'cohort_year' => $cohortYear,
                    'archived_classes_count' => 0,
                    'deactivated_ketis_count' => 0,
                ];
            }

            $classIds = $classGroups->pluck('id')->all();
            $archivedClassesCount = $classGroups->where('is_active', true)->count();
            if ($archivedClassesCount === 0) {
                $archivedClassesCount = $classGroups->count();
            }

            ClassGroup::query()
                ->whereIn('id', $classIds)
                ->update(['is_active' => false]);

            $activeReps = ClassRepresentative::query()
                ->whereIn('class_group_id', $classIds)
                ->activeOn(now())
                ->with('user')
                ->lockForUpdate()
                ->get();

            $deactivatedKetisCount = 0;
            $deactivationReason = filled($reason) ? (string) $reason : "Kelulusan Angkatan {$cohortYear}";

            if ($deactivateKetis) {
                $uniqueUsers = $activeReps
                    ->pluck('user')
                    ->filter(fn (?User $user): bool => $user !== null && (bool) $user->is_active)
                    ->unique('id');

                foreach ($uniqueUsers as $keti) {
                    $this->ketiApprovalService->deactivate($keti, $admin, $deactivationReason);
                    $deactivatedKetisCount++;
                }
            }

            // Ensure all active reps for these classes are deactivated as of yesterday
            ClassRepresentative::query()
                ->whereIn('id', $activeReps->pluck('id'))
                ->where(fn ($q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', now()->toDateString()))
                ->update(['valid_to' => now()->subDay()->toDateString()]);

            $firstClass = $classGroups->first();
            if ($firstClass !== null) {
                $this->audit->log(
                    'cohort.graduated',
                    $firstClass,
                    ['is_active' => true],
                    [
                        'cohort_year' => $cohortYear,
                        'archived_classes_count' => $archivedClassesCount,
                        'deactivated_ketis_count' => $deactivatedKetisCount,
                        'class_ids' => $classIds,
                    ],
                    $deactivationReason,
                );
            }

            return [
                'cohort_year' => $cohortYear,
                'archived_classes_count' => $archivedClassesCount,
                'deactivated_ketis_count' => $deactivatedKetisCount,
            ];
        });
    }

    /**
     * Preview generasi angkatan baru dari templat angkatan sumber.
     * Mengubah nama kelas dengan mengganti tahun sumber menjadi tahun target (atau menambahkan tahun target jika tidak ada).
     *
     * @return array{
     *   source_cohort_year: int,
     *   target_cohort_year: int,
     *   classes: array<int, array{
     *     source_id: int,
     *     source_name: string,
     *     target_name: string,
     *     target_code: string,
     *     study_program: string,
     *     student_count: ?int,
     *     already_exists: bool
     *   }>
     * }
     */
    public function getGeneratePreview(int $sourceCohortYear, int $targetCohortYear, bool $copyStudentCount = true, ?int $defaultStudentCount = null): array
    {
        $sourceClasses = ClassGroup::query()
            ->where('cohort_year', $sourceCohortYear)
            ->orderBy('name')
            ->get();

        $existingTargetClasses = ClassGroup::query()
            ->where('cohort_year', $targetCohortYear)
            ->get()
            ->keyBy(fn (ClassGroup $c): string => Str::upper(Str::squish($c->name)));

        $existingCodesSet = array_flip(ClassGroup::withTrashed()->pluck('code')->all());

        $classes = [];

        foreach ($sourceClasses as $sourceClass) {
            $sourceName = trim($sourceClass->name);

            if (preg_match('/\b'.preg_quote((string) $sourceCohortYear, '/').'\b$/', $sourceName)) {
                $targetName = preg_replace('/\b'.preg_quote((string) $sourceCohortYear, '/').'\b$/', (string) $targetCohortYear, $sourceName) ?? $sourceName;
            } elseif (preg_match('/\b'.preg_quote((string) $sourceCohortYear, '/').'\b/', $sourceName)) {
                $targetName = preg_replace('/\b'.preg_quote((string) $sourceCohortYear, '/').'\b/', (string) $targetCohortYear, $sourceName) ?? $sourceName;
            } else {
                $targetName = trim($sourceName.' '.$targetCohortYear);
            }

            $cleanName = Str::squish($targetName);
            $sourceForCode = $cleanName;
            if (! preg_match('/\b'.preg_quote((string) $targetCohortYear, '/').'\b$/', $cleanName)) {
                $sourceForCode = trim($cleanName.' '.$targetCohortYear);
            }
            $baseCode = Str::upper(Str::slug($sourceForCode));
            if ($baseCode === '') {
                $baseCode = 'KELAS-'.$targetCohortYear;
            }

            $alreadyExists = isset($existingTargetClasses[Str::upper(Str::squish($targetName))])
                || isset($existingCodesSet[$baseCode]);

            $studentCount = $copyStudentCount
                ? ($sourceClass->student_count ?? $defaultStudentCount)
                : $defaultStudentCount;

            $classes[] = [
                'source_id' => (int) $sourceClass->id,
                'source_name' => (string) $sourceClass->name,
                'target_name' => (string) $targetName,
                'target_code' => $baseCode,
                'study_program' => (string) $sourceClass->study_program,
                'student_count' => $studentCount !== null ? (int) $studentCount : null,
                'already_exists' => $alreadyExists,
            ];
        }

        return [
            'source_cohort_year' => $sourceCohortYear,
            'target_cohort_year' => $targetCohortYear,
            'classes' => $classes,
        ];
    }

    /**
     * Generate kelas angkatan baru dari templat dalam DB transaction:
     * Membuat ClassGroup baru dengan is_active = true, code via ClassGroup::formatCode(), dan mencatat audit 'cohort.generated'.
     *
     * @param  array<int, array{name: string, study_program: string, student_count: ?int}>|null  $customClasses
     * @return array{
     *   target_cohort_year: int,
     *   source_cohort_year: int,
     *   created_count: int,
     *   classes: Collection<int, ClassGroup>
     * }
     */
    public function generateCohort(
        int $sourceCohortYear,
        int $targetCohortYear,
        User $admin,
        ?array $customClasses = null,
        bool $copyStudentCount = true,
        ?int $defaultStudentCount = null
    ): array {
        return DB::transaction(function () use ($sourceCohortYear, $targetCohortYear, $customClasses, $copyStudentCount, $defaultStudentCount): array {
            $createdClasses = collect();

            if ($customClasses !== null) {
                foreach ($customClasses as $item) {
                    $name = trim((string) ($item['name'] ?? ''));
                    $studyProgram = trim((string) ($item['study_program'] ?? ''));
                    $studentCount = isset($item['student_count']) && is_numeric($item['student_count'])
                        ? (int) $item['student_count']
                        : null;

                    if ($name === '' || $studyProgram === '') {
                        continue;
                    }

                    // Duplication prevention
                    $existsInCohort = ClassGroup::query()
                        ->where('cohort_year', $targetCohortYear)
                        ->where('name', $name)
                        ->exists();

                    if ($existsInCohort) {
                        continue;
                    }

                    $code = ClassGroup::formatCode($name, $targetCohortYear);

                    $created = ClassGroup::create([
                        'name' => $name,
                        'code' => $code,
                        'study_program' => $studyProgram,
                        'cohort_year' => $targetCohortYear,
                        'student_count' => $studentCount,
                        'is_active' => true,
                    ]);

                    $createdClasses->push($created);
                }
            } else {
                $preview = $this->getGeneratePreview($sourceCohortYear, $targetCohortYear, $copyStudentCount, $defaultStudentCount);

                foreach ($preview['classes'] as $item) {
                    if ($item['already_exists']) {
                        continue;
                    }

                    $name = $item['target_name'];

                    // Extra check in case two items in loop target the same name/code
                    $existsInCohort = ClassGroup::query()
                        ->where('cohort_year', $targetCohortYear)
                        ->where('name', $name)
                        ->exists();

                    if ($existsInCohort) {
                        continue;
                    }

                    $code = ClassGroup::formatCode($name, $targetCohortYear);

                    $created = ClassGroup::create([
                        'name' => $name,
                        'code' => $code,
                        'study_program' => $item['study_program'],
                        'cohort_year' => $targetCohortYear,
                        'student_count' => $item['student_count'],
                        'is_active' => true,
                    ]);

                    $createdClasses->push($created);
                }
            }

            $auditableClass = $createdClasses->first()
                ?? ClassGroup::query()->where('cohort_year', $targetCohortYear)->first()
                ?? ClassGroup::query()->where('cohort_year', $sourceCohortYear)->first();

            if ($auditableClass !== null) {
                $this->audit->log(
                    'cohort.generated',
                    $auditableClass,
                    null,
                    [
                        'source_cohort_year' => $sourceCohortYear,
                        'target_cohort_year' => $targetCohortYear,
                        'created_count' => $createdClasses->count(),
                        'class_ids' => $createdClasses->pluck('id')->all(),
                    ],
                    "Generate Angkatan {$targetCohortYear} dari templat Angkatan {$sourceCohortYear}"
                );
            }

            return [
                'target_cohort_year' => $targetCohortYear,
                'source_cohort_year' => $sourceCohortYear,
                'created_count' => $createdClasses->count(),
                'classes' => $createdClasses,
            ];
        });
    }
}
