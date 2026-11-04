<?php

namespace App\Http\Controllers\Admin;

use App\Enums\LecturerProfileStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TeachingAssignmentRequest;
use App\Models\CourseOffering;
use App\Models\Lecturer;
use App\Models\Period;
use App\Models\TeachingAssignment;
use App\Services\AuditLogger;
use App\Services\TeachingLoadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        $offerings = $period === null ? collect() : CourseOffering::query()
            ->whereBelongsTo($period)
            ->with(['course', 'classGroup', 'teachingAssignments.lecturer'])
            ->get()
            ->sortBy(['classGroup.name', 'course.name'])
            ->values();

        $selected = $offerings->firstWhere('id', $request->integer('penawaran')) ?? $offerings->first();

        return view('admin.teaching.index', [
            'period' => $period,
            'offerings' => $offerings,
            'selected' => $selected,
            'credits' => $selected?->teachingAssignments->mapWithKeys(fn (TeachingAssignment $assignment): array => [$assignment->id => $loads->credit($assignment->setRelation('courseOffering', $selected))]) ?? collect(),
            'loads' => $period ? $loads->summary($period) : collect(),
            'withoutLead' => $offerings->filter(fn (CourseOffering $offering): bool => ! $offering->teachingAssignments->contains('role.value', 'lead'))->count(),
            'lecturerOptions' => Lecturer::query()->where('profile_status', LecturerProfileStatus::Verified)->where('is_active', true)->orderBy('name')->pluck('name', 'id'),
        ]);
    }

    public function store(TeachingAssignmentRequest $request): RedirectResponse
    {
        $assignment = TeachingAssignment::create($request->validated());
        $this->audit->log('teaching_assignment.created', $assignment, null, $request->validated());

        return back()->with('toast', ['type' => 'success', 'title' => 'Pengajar ditambahkan ke tim']);
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
