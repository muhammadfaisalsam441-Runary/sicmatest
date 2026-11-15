<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\GenerateCohortRequest;
use App\Http\Requests\Admin\GraduateCohortRequest;
use App\Services\Academic\CohortLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Pengelolaan siklus hidup angkatan: arsip/kelulusan massal dan generasi angkatan baru.
 */
class CohortLifecycleController extends Controller
{
    public function __construct(
        private CohortLifecycleService $cohortLifecycleService,
    ) {}

    /**
     * Preview dampak kelulusan angkatan (kelas, Keti aktif, penawaran aktif).
     */
    public function previewArchive(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'cohort_year' => ['required', 'integer', 'between:2000,2100'],
        ]);

        $data = $this->cohortLifecycleService->getArchivePreview((int) $validated['cohort_year']);

        return response()->json($data);
    }

    /**
     * Luluskan dan arsipkan seluruh kelas dalam satu angkatan.
     */
    public function graduateCohort(GraduateCohortRequest $request): RedirectResponse|JsonResponse
    {
        $validated = $request->validated();
        $cohortYear = (int) $validated['cohort_year'];
        $reason = $validated['reason'] ?? null;
        $deactivateKetis = $request->boolean('deactivate_ketis', true);

        $result = $this->cohortLifecycleService->graduateCohort(
            cohortYear: $cohortYear,
            admin: $request->user(),
            reason: $reason,
            deactivateKetis: $deactivateKetis,
        );

        if ($request->wantsJson()) {
            return response()->json([
                'message' => "Angkatan {$cohortYear} berhasil diluluskan & diarsipkan",
                'result' => $result,
            ]);
        }

        return to_route('admin.academic.index', ['tab' => 'kelas'])->with('toast', [
            'type' => 'success',
            'title' => "Angkatan {$cohortYear} berhasil diluluskan & diarsipkan",
            'message' => "{$result['archived_classes_count']} kelas diarsipkan dan {$result['deactivated_ketis_count']} akun Keti dinonaktifkan.",
        ]);
    }

    /**
     * Preview pembukaan angkatan baru berdasarkan templat angkatan sumber.
     */
    public function previewGenerate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'source_cohort_year' => ['required', 'integer', 'between:2000,2100'],
            'target_cohort_year' => ['required', 'integer', 'between:2000,2100'],
            'copy_student_count' => ['sometimes', 'boolean'],
            'default_student_count' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $copyStudentCount = $request->boolean('copy_student_count', true);
        $defaultStudentCount = isset($validated['default_student_count']) && is_numeric($validated['default_student_count'])
            ? (int) $validated['default_student_count']
            : null;

        $data = $this->cohortLifecycleService->getGeneratePreview(
            sourceCohortYear: (int) $validated['source_cohort_year'],
            targetCohortYear: (int) $validated['target_cohort_year'],
            copyStudentCount: $copyStudentCount,
            defaultStudentCount: $defaultStudentCount,
        );

        return response()->json($data);
    }

    /**
     * Buat kelas-kelas baru untuk angkatan target berdasarkan templat angkatan sumber.
     */
    public function generateCohort(GenerateCohortRequest $request): RedirectResponse|JsonResponse
    {
        $validated = $request->validated();
        $sourceCohortYear = (int) $validated['source_cohort_year'];
        $targetCohortYear = (int) $validated['target_cohort_year'];
        $copyStudentCount = $request->boolean('copy_student_count', true);
        $defaultStudentCount = isset($validated['default_student_count']) && is_numeric($validated['default_student_count'])
            ? (int) $validated['default_student_count']
            : null;
        $customClasses = $validated['classes'] ?? null;

        $result = $this->cohortLifecycleService->generateCohort(
            sourceCohortYear: $sourceCohortYear,
            targetCohortYear: $targetCohortYear,
            admin: $request->user(),
            customClasses: $customClasses,
            copyStudentCount: $copyStudentCount,
            defaultStudentCount: $defaultStudentCount,
        );

        if ($request->wantsJson()) {
            return response()->json([
                'message' => "Angkatan {$targetCohortYear} berhasil dibuka",
                'result' => $result,
            ]);
        }

        return to_route('admin.academic.index', ['tab' => 'kelas'])->with('toast', [
            'type' => 'success',
            'title' => "Angkatan {$targetCohortYear} berhasil dibuka",
            'message' => "Berhasil membuat {$result['created_count']} kelas baru dari templat Angkatan {$sourceCohortYear}.",
        ]);
    }
}
