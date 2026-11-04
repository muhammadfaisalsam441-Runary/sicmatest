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
use App\Models\Period;
use App\Models\TeachingAssignment;
use App\Services\AuditLogger;
use App\Services\TeachingLoadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Pembagian SKS: tim pengajar (pengampu + mitra) per penawaran dan rekap beban vs target dosen.
 */
class TeachingAssignmentController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(Request $request, TeachingLoadService $loads): View
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
        }

        $offerings = $query->join('class_groups', 'course_offerings.class_group_id', '=', 'class_groups.id')
            ->join('courses', 'course_offerings.course_id', '=', 'courses.id')
            ->select('course_offerings.*')
            ->orderBy('class_groups.name')
            ->orderBy('courses.name')
            ->paginate($perPage)
            ->withQueryString();

        $selectedId = $request->integer('penawaran');
        $selected = $selectedId > 0
            ? CourseOffering::with(['course', 'classGroup', 'teachingAssignments.lecturer'])->find($selectedId)
            : $offerings->first();

        $allLoads = $period ? $loads->summary($period) : collect();

        $lecturers = Lecturer::query()
            ->where('profile_status', LecturerProfileStatus::Verified)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $loadsByLecturerId = $allLoads->keyBy(fn ($item) => $item['lecturer']->id);

        $lecturerOptionsEnriched = $lecturers->map(function ($lecturer) use ($loadsByLecturerId) {
            $load = $loadsByLecturerId->get($lecturer->id);
            $assigned = $load['assigned'] ?? 0.0;
            $max = $load['max'] ?? null;
            $target = $load['target'] ?? null;
            $status = $load['status'] ?? 'Belum ada beban';
            $tone = $load['tone'] ?? 'neutral';

            $sksInfo = $max !== null ? "{$assigned}/{$max} SKS" : "{$assigned} SKS";

            return [
                'id' => $lecturer->id,
                'name' => $lecturer->name,
                'sks_info' => $sksInfo,
                'assigned' => $assigned,
                'target' => $target,
                'max' => $max,
                'status' => $status,
                'tone' => $tone,
            ];
        });

        $studyPrograms = ClassGroup::query()->whereNotNull('study_program')->distinct()->orderBy('study_program')->pluck('study_program');

        $baseCountQuery = CourseOffering::query()->when($period, fn ($q) => $q->whereBelongsTo($period));
        $totalCount = (clone $baseCountQuery)->count();
        $withoutLeadCount = (clone $baseCountQuery)->whereDoesntHave('teachingAssignments', fn ($aq) => $aq->where('role', TeachingRole::Lead))->count();
        $withLeadCount = $totalCount - $withoutLeadCount;

        return view('admin.teaching.index', [
            'period' => $period,
            'offerings' => $offerings,
            'selected' => $selected,
            'credits' => $selected?->teachingAssignments->mapWithKeys(fn (TeachingAssignment $assignment): array => [$assignment->id => $loads->credit($assignment->setRelation('courseOffering', $selected))]) ?? collect(),
            'loads' => $allLoads,
            'withoutLead' => $withoutLeadCount,
            'withLead' => $withLeadCount,
            'totalCount' => $totalCount,
            'keyword' => $keyword,
            'filter' => $filter,
            'prodi' => $prodi,
            'perPage' => $perPage,
            'studyPrograms' => $studyPrograms,
            'lecturerOptions' => $lecturers->pluck('name', 'id'),
            'lecturerOptionsEnriched' => $lecturerOptionsEnriched,
        ]);
    }

    public function store(TeachingAssignmentRequest $request): RedirectResponse
    {
        $assignment = TeachingAssignment::create($request->validated());
        $this->audit->log('teaching_assignment.created', $assignment, null, $request->validated());

        return back()->with('toast', ['type' => 'success', 'title' => 'Pengajar ditambahkan ke tim']);
    }

    public function batchStore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'offering_ids' => ['required', 'array', 'min:1'],
            'offering_ids.*' => ['required', 'exists:course_offerings,id'],
            'lecturer_id' => ['required', 'exists:lecturers,id'],
            'role' => ['required', Rule::enum(TeachingRole::class)],
            'phase' => ['required', Rule::enum(SessionPhase::class)],
            'credited_sks' => ['nullable', 'numeric', 'min:0', 'max:12'],
        ]);

        $lecturer = Lecturer::findOrFail($validated['lecturer_id']);
        $assignedCount = 0;

        foreach ($validated['offering_ids'] as $offeringId) {
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
