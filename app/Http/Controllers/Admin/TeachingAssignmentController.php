<?php

namespace App\Http\Controllers\Admin;

use App\Enums\LecturerProfileStatus;
use App\Enums\SessionPhase;
use App\Enums\TeachingRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TeachingAssignmentRequest;
use App\Models\Academic\ClassGroup;
use App\Models\Academic\CourseOffering;
use App\Models\Academic\Period;
use App\Models\Lecturer\Lecturer;
use App\Models\Lecturer\TeachingAssignment;
use App\Services\Academic\LecturerService;
use App\Services\Academic\TeachingAllocationService;
use App\Services\Academic\TeachingLoadService;
use App\Services\Academic\TeachingPhasePlanner;
use App\Services\Account\AuditLogger;
use App\Services\Scheduling\ScheduleDraftService;
use App\Support\CsvStream;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Pembagian SKS: tim pengajar (pengampu + mitra) per penawaran dan rekap beban vs target dosen.
 */
class TeachingAssignmentController extends Controller
{
    /**
     * @var list<string>
     */
    private const SORTS = ['class', 'course', 'sks'];

    public function __construct(private AuditLogger $audit, private ScheduleDraftService $drafts) {}

    public function index(Request $request, TeachingLoadService $loads, LecturerService $lecturerService): View
    {
        $period = Period::query()->active()->first();
        $keyword = trim((string) $request->query('q'));
        $filter = (string) $request->query('filter', 'semua');
        $prodi = (string) $request->query('prodi');
        $semester = trim((string) $request->query('semester'));
        $sort = in_array((string) $request->query('sort'), self::SORTS, true) ? (string) $request->query('sort') : 'class';
        $dir = $request->query('dir') === 'desc' ? 'desc' : 'asc';
        $perPage = $request->integer('per_page', 25);
        if (! in_array($perPage, [15, 25, 50, 100], true)) {
            $perPage = 25;
        }

        $periodYear = (int) ($period?->start_date?->format('Y') ?? 2026);
        $isGanjil = $period ? ! str_contains(strtolower($period->name), 'genap') : true;

        $semesterOptions = $this->semesterOptions($periodYear, $isGanjil);

        $selectedId = $request->integer('penawaran');

        $offerings = $this->applyOfferingSort(
            $this->filteredOfferingsQuery($period, $keyword, $prodi, $semester, $semesterOptions, $filter, $periodYear, $isGanjil)
                ->join('class_groups', 'course_offerings.class_group_id', '=', 'class_groups.id')
                ->join('courses', 'course_offerings.course_id', '=', 'courses.id')
                ->select('course_offerings.*')
                ->when($selectedId > 0, fn ($q) => $q->orderByRaw('CASE WHEN course_offerings.id = ? THEN 0 ELSE 1 END', [$selectedId])),
            $sort,
            $dir,
        )
            ->paginate($perPage)
            ->withQueryString();

        $classBlockAlternate = $this->classBlockAlternateMap($offerings->getCollection(), $sort);

        $selected = $selectedId > 0
            ? CourseOffering::with(['course', 'classGroup', 'teachingAssignments.lecturer'])->find($selectedId)
            : $offerings->first();

        $allLoads = $period ? $loads->summary($period) : collect();

        $lecturers = Lecturer::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $loadsByLecturerId = $allLoads->keyBy(fn ($item) => $item['lecturer']->id);

        $lecturerOptionsById = $lecturers->mapWithKeys(function (Lecturer $lecturer) use ($loadsByLecturerId): array {
            $load = $loadsByLecturerId->get($lecturer->id);
            $assigned = $load['assigned'] ?? 0.0;
            $max = $load['max'] ?? null;
            $target = $load['target'] ?? null;
            $status = $load['status'] ?? 'Belum ada beban';
            $tone = $load['tone'] ?? 'neutral';

            $meetingSks = $load['meeting_sks'] ?? 0;
            $sksInfo = "{$meetingSks} SKS × pertemuan";

            return [$lecturer->id => [
                'id' => $lecturer->id,
                'name' => $lecturer->name,
                'sks_info' => $sksInfo,
                'assigned' => $assigned,
                'target' => $target,
                'max' => $max,
                'status' => $status,
                'tone' => $tone,
                'verified' => $lecturer->profile_status === LecturerProfileStatus::Verified,
            ]];
        });
        $lecturerOptionsEnriched = $lecturerOptionsById->where('verified', true)->values();

        $instructorIdsByOffering = $lecturerService->instructorIdsByOffering($offerings->getCollection(), $lecturers);
        $lecturerOptionsByOffering = collect($instructorIdsByOffering)
            ->map(fn (array $ids): array => $lecturerOptionsById->only($ids)->values()->all());

        $studyPrograms = ClassGroup::query()->whereNotNull('study_program')
            ->distinct()
            ->orderBy('study_program')
            ->pluck('study_program')
            ->mapWithKeys(fn (string $name): array => [
                $name => ClassGroup::studyProgramLabel($name),
            ]);

        $baseCountQuery = $this->filteredOfferingsQuery($period, '', $prodi, $semester, $semesterOptions, 'semua', $periodYear, $isGanjil);
        $totalCount = (clone $baseCountQuery)->count();
        $withoutLeadCount = (clone $baseCountQuery)->whereDoesntHave('teachingAssignments', fn ($aq) => $aq->where('role', TeachingRole::Lead))->count();
        $withLeadCount = $totalCount - $withoutLeadCount;
        $singleTeacherCount = (clone $baseCountQuery)
            ->whereHas('teachingAssignments', fn ($aq) => $aq->where('role', TeachingRole::Lead))
            ->whereDoesntHave('teachingAssignments', fn ($aq) => $aq->where('role', TeachingRole::Partner))
            ->count();
        $teamTeacherCount = (clone $baseCountQuery)
            ->whereHas('teachingAssignments', fn ($aq) => $aq->where('role', TeachingRole::Lead))
            ->whereHas('teachingAssignments', fn ($aq) => $aq->where('role', TeachingRole::Partner))
            ->count();

        return view('admin.teaching.index', [
            'period' => $period,
            'offerings' => $offerings,
            'classBlockAlternate' => $classBlockAlternate,
            'selected' => $selected,
            'loads' => $allLoads,
            'withoutLead' => $withoutLeadCount,
            'withLead' => $withLeadCount,
            'singleTeacherCount' => $singleTeacherCount,
            'teamTeacherCount' => $teamTeacherCount,
            'totalCount' => $totalCount,
            'keyword' => $keyword,
            'filter' => $filter,
            'prodi' => $prodi,
            'semester' => $semester,
            'semesterOptions' => $semesterOptions,
            'perPage' => $perPage,
            'sort' => $sort,
            'dir' => $dir,
            'studyPrograms' => $studyPrograms,
            'lecturerOptionsEnriched' => $lecturerOptionsEnriched,
            'allLecturerOptions' => $lecturerOptionsById->values(),
            'lecturerOptionsByOffering' => $lecturerOptionsByOffering,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $period = Period::query()->active()->first();
        $keyword = trim((string) $request->query('q'));
        $filter = (string) $request->query('filter', 'semua');
        $prodi = (string) $request->query('prodi');
        $semester = trim((string) $request->query('semester'));
        $sort = in_array((string) $request->query('sort'), self::SORTS, true) ? (string) $request->query('sort') : 'class';
        $dir = $request->query('dir') === 'desc' ? 'desc' : 'asc';

        $periodYear = (int) ($period?->start_date?->format('Y') ?? 2026);
        $isGanjil = $period ? ! str_contains(strtolower($period->name), 'genap') : true;
        $semesterOptions = $this->semesterOptions($periodYear, $isGanjil);

        $offerings = $this->applyOfferingSort(
            $this->filteredOfferingsQuery($period, $keyword, $prodi, $semester, $semesterOptions, $filter, $periodYear, $isGanjil)
                ->join('class_groups', 'course_offerings.class_group_id', '=', 'class_groups.id')
                ->join('courses', 'course_offerings.course_id', '=', 'courses.id')
                ->select('course_offerings.*'),
            $sort,
            $dir,
        )->get();

        $rows = $offerings->map(function (CourseOffering $offering): array {
            $lead = $offering->teachingAssignments->firstWhere('role', TeachingRole::Lead);
            $partners = $offering->teachingAssignments->where('role', TeachingRole::Partner);
            $schema = match (true) {
                ! $lead && $partners->isEmpty() => 'Kosong',
                $partners->isNotEmpty() => 'Team ('.($partners->count() + 1).')',
                default => 'Tunggal',
            };

            return [
                $offering->classGroup->name,
                $offering->classGroup->study_program ?? '',
                $offering->classGroup->cohort_year ?? '',
                $offering->course->code ?? '',
                $offering->course->name,
                $offering->course->sks,
                $lead?->lecturer?->name ?? '',
                $partners->map(fn ($p) => $p->lecturer->name)->implode('; '),
                $schema,
            ];
        });

        return CsvStream::download(
            'pembagian-mengajar-'.now()->toDateString().'.csv',
            [
                'Kelas',
                'Program Studi',
                'Angkatan',
                'Kode MK',
                'Mata Kuliah',
                'SKS',
                'Dosen Pengampu',
                'Dosen Mitra',
                'Skema',
            ],
            $rows,
        );
    }

    public function previewAllocation(TeachingAllocationService $allocator): View
    {
        $period = Period::query()->active()->firstOrFail();

        return view('admin.teaching.allocation-preview', ['period' => $period, 'plan' => $allocator->preview($period)]);
    }

    public function applyAllocation(Request $request, TeachingAllocationService $allocator): RedirectResponse
    {
        $validated = $request->validate(['fingerprint' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/']]);
        $result = $allocator->apply($validated['fingerprint']);

        return to_route('admin.teaching.index')
            ->with('allocation_draft', $result['draft_id'])
            ->with('toast', ['type' => 'success', 'title' => "{$result['count']} penugasan kelas berhasil dibagi ulang."]);
    }

    public function previewPhases(TeachingPhasePlanner $planner): View
    {
        $period = Period::query()->active()->firstOrFail();

        return view('admin.teaching.phase-preview', ['period' => $period, 'plan' => $planner->preview($period)]);
    }

    public function applyPhases(Request $request, TeachingPhasePlanner $planner): RedirectResponse
    {
        $validated = $request->validate(['fingerprint' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/']]);
        $count = $planner->apply($validated['fingerprint']);

        return to_route('admin.teaching.index')->with('toast', [
            'type' => 'success',
            'title' => "Fase mengajar untuk {$count} penawaran telah disimpan.",
        ]);
    }

    public function store(TeachingAssignmentRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        if ($validated['role'] === TeachingRole::Lead->value) {
            $existingLead = TeachingAssignment::query()
                ->where('course_offering_id', $validated['course_offering_id'])
                ->where('role', TeachingRole::Lead)
                ->first();

            if ($existingLead) {
                if ($existingLead->is_locked) {
                    return back()->with('error', 'Pengampu utama terkunci. Buka kunci dulu sebelum mengganti pengampu.');
                }

                $before = $existingLead->only(['lecturer_id', 'role', 'phase']);
                $existingLead->update([
                    'lecturer_id' => $validated['lecturer_id'],
                    'phase' => $validated['phase'],
                ]);
                $this->audit->log('teaching_assignment.updated', $existingLead, $before, $validated);

                $period = Period::query()->active()->first();
                if ($period) {
                    $this->drafts->syncOfferings($period, [$validated['course_offering_id']]);
                }

                return back()->with('toast', ['type' => 'success', 'title' => 'Dosen pengampu utama berhasil diperbarui']);
            }
        }

        $assignment = TeachingAssignment::create($validated);
        $this->audit->log('teaching_assignment.created', $assignment, null, $validated);

        $period = Period::query()->active()->first();
        if ($period) {
            $this->drafts->syncOfferings($period, [$validated['course_offering_id']]);
        }

        return back()->with('toast', ['type' => 'success', 'title' => 'Pengajar ditambahkan ke tim']);
    }

    public function update(Request $request, TeachingAssignment $assignment): RedirectResponse
    {
        if ($assignment->is_locked) {
            return back()->with('error', 'Penugasan terkunci. Buka kunci dulu sebelum mengubah data.');
        }

        $validated = $request->validate([
            'lecturer_id' => [
                'required',
                Rule::exists('lecturers', 'id')->whereNull('deleted_at'),
                Rule::unique('teaching_assignments')
                    ->ignore($assignment->id)
                    ->where(fn ($query) => $query
                        ->where('course_offering_id', $assignment->course_offering_id)
                        ->where('phase', $request->input('phase', 'full'))
                    ),
            ],
            'role' => ['required', Rule::enum(TeachingRole::class)],
            'phase' => ['required', Rule::enum(SessionPhase::class)],
        ], [
            'lecturer_id.unique' => 'Dosen ini sudah ada di tim pengajar untuk periode tersebut.',
        ]);

        if ($validated['role'] === TeachingRole::Lead->value) {
            $otherLead = TeachingAssignment::query()
                ->where('course_offering_id', $assignment->course_offering_id)
                ->where('id', '!=', $assignment->id)
                ->where('role', TeachingRole::Lead)
                ->first();

            if ($otherLead) {
                if ($otherLead->is_locked) {
                    return back()->with('error', 'Pengampu utama saat ini sedang terkunci.');
                }

                $otherLead->update(['role' => TeachingRole::Partner]);
            }
        }

        $before = $assignment->only(['lecturer_id', 'role', 'phase']);
        $assignment->update($validated);

        $this->audit->log('teaching_assignment.updated', $assignment, $before, $validated);

        $period = Period::query()->active()->first();
        if ($period) {
            $this->drafts->syncOfferings($period, [$assignment->course_offering_id]);
        }

        return back()->with('toast', ['type' => 'success', 'title' => 'Penugasan mengajar berhasil diperbarui']);
    }

    public function batchStore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'offering_ids' => ['required', 'array', 'min:1'],
            'offering_ids.*' => ['required', 'exists:course_offerings,id'],
            'lecturer_id' => ['required', 'exists:lecturers,id'],
            'role' => ['required', Rule::enum(TeachingRole::class)],
            'phase' => ['required', Rule::enum(SessionPhase::class)],
        ]);

        $lecturer = Lecturer::findOrFail($validated['lecturer_id']);
        $assignedCount = 0;

        foreach ($validated['offering_ids'] as $offeringId) {
            if ($validated['role'] === TeachingRole::Lead->value) {
                $existingLead = TeachingAssignment::query()
                    ->where('course_offering_id', $offeringId)
                    ->where('role', TeachingRole::Lead)
                    ->first();

                if ($existingLead) {
                    if ($existingLead->is_locked) {
                        continue;
                    }

                    $conflict = TeachingAssignment::query()
                        ->where('course_offering_id', $offeringId)
                        ->where('id', '!=', $existingLead->id)
                        ->where('lecturer_id', $validated['lecturer_id'])
                        ->where('phase', $validated['phase'])
                        ->exists();

                    if (! $conflict) {
                        $before = $existingLead->only(['lecturer_id', 'role', 'phase']);
                        $existingLead->update([
                            'lecturer_id' => $validated['lecturer_id'],
                            'phase' => $validated['phase'],
                        ]);
                        $this->audit->log('teaching_assignment.updated', $existingLead, $before, $existingLead->toArray());
                        $assignedCount++;
                    }

                    continue;
                }
            }

            $exists = TeachingAssignment::query()
                ->where('course_offering_id', $offeringId)
                ->where('lecturer_id', $validated['lecturer_id'])
                ->where('phase', $validated['phase'])
                ->exists();

            if (! $exists) {
                $assignment = TeachingAssignment::create([
                    'course_offering_id' => $offeringId,
                    'lecturer_id' => $validated['lecturer_id'],
                    'role' => $validated['role'],
                    'phase' => $validated['phase'],
                ]);

                $this->audit->log('teaching_assignment.created', $assignment, null, $assignment->toArray());
                $assignedCount++;
            }
        }

        $period = Period::query()->active()->first();
        if ($period && $assignedCount > 0) {
            $this->drafts->syncOfferings($period, $validated['offering_ids']);
        }

        return back()->with('toast', [
            'type' => 'success',
            'title' => "{$assignedCount} kelas berhasil ditugaskan ke {$lecturer->name}",
        ]);
    }

    public function toggleLock(TeachingAssignment $assignment): RedirectResponse
    {
        $assignment->update(['is_locked' => ! $assignment->is_locked]);
        $this->audit->log('teaching_assignment.lock', $assignment, null, ['is_locked' => $assignment->is_locked]);

        return back()->with('toast', ['type' => 'success', 'title' => $assignment->is_locked ? 'Penugasan dikunci' : 'Kunci penugasan dibuka']);
    }

    public function destroy(TeachingAssignment $assignment): RedirectResponse
    {
        if ($assignment->is_locked) {
            return back()->with('error', 'Penugasan terkunci. Buka kunci dulu sebelum menghapus.');
        }

        $offeringId = $assignment->course_offering_id;
        $this->audit->log('teaching_assignment.deleted', $assignment, $assignment->only(['course_offering_id', 'lecturer_id', 'role', 'phase']));
        $assignment->delete();

        $period = Period::query()->active()->first();
        if ($period) {
            $this->drafts->syncOfferings($period, [$offeringId]);
        }

        return back()->with('toast', ['type' => 'success', 'title' => 'Pengajar dihapus dari tim']);
    }

    /**
     * @return Collection<int, array{value: string, cohort: int, label: string}>
     */
    private function semesterOptions(int $periodYear, bool $isGanjil): Collection
    {
        $cohorts = ClassGroup::query()
            ->whereNotNull('cohort_year')
            ->distinct()
            ->orderByDesc('cohort_year')
            ->pluck('cohort_year');

        return $cohorts->map(function (int $cohort) use ($periodYear, $isGanjil): array {
            $diff = $periodYear - $cohort;
            $sem = ($diff * 2) + ($isGanjil ? 1 : 2);
            if ($sem <= 8) {
                return [
                    'value' => (string) $sem,
                    'cohort' => $cohort,
                    'label' => "Semester {$sem} ({$cohort})",
                ];
            }

            return [
                'value' => (string) $cohort,
                'cohort' => $cohort,
                'label' => "Mengulang ({$cohort})",
            ];
        })->values();
    }

    /**
     * @param  Collection<int, array{value: string, cohort: int, label: string}>  $semesterOptions
     * @return Builder<CourseOffering>
     */
    private function filteredOfferingsQuery(
        ?Period $period,
        string $keyword,
        string $prodi,
        string $semester,
        Collection $semesterOptions,
        string $filter,
        int $periodYear,
        bool $isGanjil,
    ): Builder {
        $query = CourseOffering::query()
            ->when($period, fn ($q) => $q->whereBelongsTo($period))
            ->with(['course', 'classGroup', 'teachingAssignments.lecturer']);

        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->whereHas('course', function ($cq) use ($keyword) {
                    $cq->where('name', 'like', "%{$keyword}%")
                        ->orWhere('code', 'like', "%{$keyword}%");
                })->orWhereHas('classGroup', function ($gq) use ($keyword) {
                    $gq->where('name', 'like', "%{$keyword}%")
                        ->orWhere('study_program', 'like', "%{$keyword}%");
                })->orWhereHas('teachingAssignments.lecturer', function ($lq) use ($keyword) {
                    $lq->where('name', 'like', "%{$keyword}%");
                });
            });
        }

        if ($prodi !== '') {
            $query->whereHas('classGroup', fn (Builder $gq) => $gq->whereStudyProgramMatches($prodi));
        }

        if ($semester !== '') {
            $matchedOpt = $semesterOptions->firstWhere('value', $semester);
            if ($matchedOpt) {
                $query->whereHas('classGroup', fn ($gq) => $gq->where('cohort_year', $matchedOpt['cohort']));
            } elseif (is_numeric($semester) && (int) $semester >= 2000) {
                $query->whereHas('classGroup', fn ($gq) => $gq->where('cohort_year', (int) $semester));
            } elseif (is_numeric($semester)) {
                $cohort = $periodYear - intdiv((int) $semester - ($isGanjil ? 1 : 2), 2);
                $query->whereHas('classGroup', fn ($gq) => $gq->where('cohort_year', $cohort));
            }
        }

        if ($filter === 'tanpa_pengampu') {
            $query->whereDoesntHave('teachingAssignments', fn ($aq) => $aq->where('role', TeachingRole::Lead));
        } elseif ($filter === 'lengkap') {
            $query->whereHas('teachingAssignments', fn ($aq) => $aq->where('role', TeachingRole::Lead));
        } elseif ($filter === 'tunggal') {
            $query->whereHas('teachingAssignments', fn ($aq) => $aq->where('role', TeachingRole::Lead))
                ->whereDoesntHave('teachingAssignments', fn ($aq) => $aq->where('role', TeachingRole::Partner));
        } elseif ($filter === 'tim_mitra') {
            $query->whereHas('teachingAssignments', fn ($aq) => $aq->where('role', TeachingRole::Lead))
                ->whereHas('teachingAssignments', fn ($aq) => $aq->where('role', TeachingRole::Partner));
        }

        return $query;
    }

    /**
     * Terapkan urutan daftar penawaran; default per blok angkatan terbaru lalu abjad kelas.
     *
     * @param  Builder<CourseOffering>  $query
     * @return Builder<CourseOffering>
     */
    private function applyOfferingSort(Builder $query, string $sort, string $dir): Builder
    {
        if ($sort === 'course') {
            return $query->orderBy('courses.name', $dir)->orderBy('class_groups.name');
        }

        if ($sort === 'sks') {
            return $query->orderBy('courses.sks', $dir)->orderBy('class_groups.name')->orderBy('courses.name');
        }

        return $query
            ->orderByRaw('class_groups.cohort_year is null')
            ->orderBy('class_groups.cohort_year', $dir === 'desc' ? 'asc' : 'desc')
            ->orderBy('class_groups.name', $dir)
            ->orderBy('courses.name');
    }

    /**
     * Tandai warna selang-seling per blok kelas pada halaman saat ini.
     *
     * @param  Collection<int, CourseOffering>  $offerings
     * @return array<int, bool>
     */
    private function classBlockAlternateMap(Collection $offerings, string $sort): array
    {
        if ($sort !== 'class' || $offerings->isEmpty()) {
            return [];
        }

        $alternateByOffering = [];
        $blockIndex = -1;
        $previousClassId = null;

        foreach ($offerings as $offering) {
            $classId = $offering->class_group_id;
            if ($classId !== $previousClassId) {
                $blockIndex++;
                $previousClassId = $classId;
            }

            $alternateByOffering[$offering->id] = $blockIndex % 2 === 1;
        }

        return $alternateByOffering;
    }
}
