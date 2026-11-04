<?php

namespace App\Http\Controllers\Admin;

use App\Enums\LecturerProfileStatus;
use App\Enums\SessionPhase;
use App\Enums\TeachingRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TeachingAssignmentRequest;
use App\Models\ClassGroup;
use App\Models\CourseOffering;
use App\Models\Lecturer;
use App\Models\LecturerExpertise;
use App\Models\Period;
use App\Models\TeachingAssignment;
use App\Services\AuditLogger;
use App\Services\LecturerService;
use App\Services\TeachingLoadService;
use App\Services\TeachingPhasePlanner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Pembagian SKS: tim pengajar (pengampu + mitra) per penawaran dan rekap beban vs target dosen.
 */
class TeachingAssignmentController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(Request $request, TeachingLoadService $loads, LecturerService $lecturerService): View
    {
        $period = Period::query()->active()->first();
        $keyword = trim((string) $request->query('q'));
        $filter = (string) $request->query('filter', 'semua');
        $prodi = (string) $request->query('prodi');
        $perPage = $request->integer('per_page', 25);
        if (! in_array($perPage, [15, 25, 50, 100], true)) {
            $perPage = 25;
        }

        // Query penawaran mata kuliah
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
            $query->whereHas('classGroup', fn ($gq) => $gq->where('study_program', $prodi));
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

        $selectedId = $request->integer('penawaran');

        $offerings = $query->join('class_groups', 'course_offerings.class_group_id', '=', 'class_groups.id')
            ->join('courses', 'course_offerings.course_id', '=', 'courses.id')
            ->select('course_offerings.*')
            ->when($selectedId > 0, fn ($q) => $q->orderByRaw('CASE WHEN course_offerings.id = ? THEN 0 ELSE 1 END', [$selectedId]))
            ->orderBy('class_groups.name')
            ->orderBy('courses.name')
            ->paginate($perPage)
            ->withQueryString();

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

        $instructorsByCourseAndPeriod = TeachingAssignment::query()
            ->join('course_offerings', 'course_offerings.id', '=', 'teaching_assignments.course_offering_id')
            ->whereIn('course_offerings.course_id', $offerings->pluck('course_id')->unique())
            ->whereIn('course_offerings.period_id', $offerings->pluck('period_id')->unique())
            ->select('course_offerings.course_id', 'course_offerings.period_id', 'teaching_assignments.lecturer_id')
            ->distinct()
            ->get()
            ->groupBy(fn (TeachingAssignment $assignment): string => $assignment->period_id.':'.$assignment->course_id);

        $rosterInstructors = $lecturerService->rosterInstructorIds($lecturers, $offerings->pluck('period_id')->unique()->all());
        $manualInstructors = LecturerExpertise::query()->where('field', LecturerExpertise::ADMIN_TEACHING)
            ->whereIn('course_id', $offerings->pluck('course_id')->unique())
            ->get(['course_id', 'lecturer_id'])->groupBy('course_id');
        $lecturerOptionsByOffering = $offerings->getCollection()->mapWithKeys(function (CourseOffering $offering) use ($instructorsByCourseAndPeriod, $lecturerOptionsById, $rosterInstructors, $manualInstructors): array {
            $instructorIds = $instructorsByCourseAndPeriod->get($offering->period_id.':'.$offering->course_id, collect())->pluck('lecturer_id');
            $courseName = Str::lower(Str::squish($offering->course->name));
            $instructorIds = $instructorIds->merge($rosterInstructors[$offering->period_id][$courseName] ?? []);
            $instructorIds = $instructorIds->merge($manualInstructors->get($offering->course_id, collect())->pluck('lecturer_id'));

            return [$offering->id => $lecturerOptionsById->only($instructorIds->all())->values()->all()];
        });

        $studyPrograms = ClassGroup::query()->whereNotNull('study_program')->distinct()->orderBy('study_program')->pluck('study_program');

        $baseCountQuery = CourseOffering::query()->when($period, fn ($q) => $q->whereBelongsTo($period));
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
            'selected' => $selected,
            'credits' => $selected?->teachingAssignments->mapWithKeys(fn (TeachingAssignment $assignment): array => [$assignment->id => $loads->credit($assignment->setRelation('courseOffering', $selected))]) ?? collect(),
            'loads' => $allLoads,
            'withoutLead' => $withoutLeadCount,
            'withLead' => $withLeadCount,
            'singleTeacherCount' => $singleTeacherCount,
            'teamTeacherCount' => $teamTeacherCount,
            'totalCount' => $totalCount,
            'keyword' => $keyword,
            'filter' => $filter,
            'prodi' => $prodi,
            'perPage' => $perPage,
            'studyPrograms' => $studyPrograms,
            'lecturerOptionsEnriched' => $lecturerOptionsEnriched,
            'allLecturerOptions' => $lecturerOptionsById->values(),
            'lecturerOptionsByOffering' => $lecturerOptionsByOffering,
        ]);
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

                $before = $existingLead->only(['lecturer_id', 'role', 'phase', 'credited_sks']);
                $existingLead->update([
                    'lecturer_id' => $validated['lecturer_id'],
                    'phase' => $validated['phase'],
                    'credited_sks' => $validated['credited_sks'] ?? null,
                ]);
                $this->audit->log('teaching_assignment.updated', $existingLead, $before, $validated);

                return back()->with('toast', ['type' => 'success', 'title' => 'Dosen pengampu utama berhasil diperbarui']);
            }
        }

        $assignment = TeachingAssignment::create($validated);
        $this->audit->log('teaching_assignment.created', $assignment, null, $validated);

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
            'credited_sks' => ['nullable', 'integer', 'min:0', 'max:12'],
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

        $before = $assignment->only(['lecturer_id', 'role', 'phase', 'credited_sks']);
        $assignment->update([
            'lecturer_id' => $validated['lecturer_id'],
            'role' => $validated['role'],
            'phase' => $validated['phase'],
            'credited_sks' => $validated['credited_sks'] ?? null,
        ]);

        $this->audit->log('teaching_assignment.updated', $assignment, $before, $validated);

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
            'credited_sks' => ['nullable', 'integer', 'min:0', 'max:12'],
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
                        $before = $existingLead->only(['lecturer_id', 'role', 'phase', 'credited_sks']);
                        $existingLead->update([
                            'lecturer_id' => $validated['lecturer_id'],
                            'phase' => $validated['phase'],
                            'credited_sks' => $validated['credited_sks'] ?? $existingLead->credited_sks,
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
                    'credited_sks' => $validated['credited_sks'] ?? null,
                ]);

                $this->audit->log('teaching_assignment.created', $assignment, null, $assignment->toArray());
                $assignedCount++;
            }
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

        $this->audit->log('teaching_assignment.deleted', $assignment, $assignment->only(['course_offering_id', 'lecturer_id', 'role', 'phase']));
        $assignment->delete();

        return back()->with('toast', ['type' => 'success', 'title' => 'Pengajar dihapus dari tim']);
    }
}
