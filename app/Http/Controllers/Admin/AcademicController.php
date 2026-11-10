<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CalendarExceptionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CalendarExceptionRequest;
use App\Http\Requests\Admin\ClassGroupRequest;
use App\Http\Requests\Admin\CourseRequest;
use App\Http\Requests\Admin\OfferingRequest;
use App\Http\Requests\Admin\PeriodRequest;
use App\Http\Requests\Admin\TimeSlotsRequest;
use App\Models\Academic\CalendarException;
use App\Models\Academic\ClassGroup;
use App\Models\Academic\Course;
use App\Models\Academic\CourseOffering;
use App\Models\Academic\Period;
use App\Services\Account\AuditLogger;
use App\Support\AvailabilityCache;
use Carbon\CarbonPeriod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Data Akademik: Semester & Kalender, Mata Kuliah, Kelas, Penawaran Kuliah, dan Aturan (slot jam).
 */
class AcademicController extends Controller
{
    /**
     * @var array<string, string>
     */
    public const TABS = ['semester' => 'Semester & kalender', 'kelas' => 'Kelas', 'mata-kuliah' => 'Mata kuliah', 'penawaran' => 'Penawaran perkuliahan', 'aturan' => 'Aturan penjadwalan'];

    public function __construct(private AuditLogger $audit) {}

    public function index(Request $request): View
    {
        $tab = array_key_exists((string) $request->query('tab'), self::TABS) ? (string) $request->query('tab') : 'semester';
        $period = Period::query()->active()->with(['calendarExceptions' => fn ($query) => $query->orderBy('date'), 'timeSlots' => fn ($query) => $query->orderBy('start_minute')])->first();

        $classGroups = $tab === 'kelas' ? ClassGroup::query()->withCount('scheduleSessions')->orderBy('cohort_year', 'desc')->orderBy('name')->get() : collect();
        $courses = $tab === 'mata-kuliah' ? Course::query()->withCount('offerings')->orderBy('code')->get() : collect();
        $offerings = $tab === 'penawaran' && $period
            ? $period->courseOfferings()
                ->with(['course', 'classGroup', 'teachingAssignments.lecturer', 'scheduleSessions'])
                ->get()
                ->sortBy([
                    ['classGroup.cohort_year', 'desc'],
                    ['classGroup.name', 'asc'],
                    ['course.name', 'asc'],
                ])
                ->values()
            : collect();

        $offeringStats = [
            'total' => $offerings->count(),
            'total_sks' => (int) $offerings->sum(fn ($o) => (int) ($o->course->sks ?? 0)),
            'assigned' => $offerings->filter(fn ($o) => $o->teachingAssignments->isNotEmpty())->count(),
            'unassigned' => $offerings->filter(fn ($o) => $o->teachingAssignments->isEmpty())->count(),
            'scheduled' => $offerings->filter(fn ($o) => $o->scheduleSessions->isNotEmpty())->count(),
        ];
        $offeringPrograms = $offerings->pluck('classGroup.study_program')->filter()->unique()->sort()->values();
        $offeringCohorts = $offerings->pluck('classGroup.cohort_year')->filter()->unique()->sortDesc()->values();
        $offeringClasses = $offerings->pluck('classGroup')->unique('id')->sortBy('name')->values();
        $offeringSksList = $offerings->pluck('course.sks')->filter()->unique()->sort()->values();

        $classStats = [
            'total' => $classGroups->count(),
            'needs_attention' => $classGroups->whereNull('student_count')->count(),
            'complete' => $classGroups->whereNotNull('student_count')->count(),
            'inactive' => $classGroups->where('is_active', false)->count(),
        ];
        $distinctPrograms = $classGroups->pluck('study_program')->filter()->unique()->sort()->values();
        $distinctCohorts = $classGroups->pluck('cohort_year')->filter()->unique()->sortDesc()->values();
        $classesPayload = $classGroups->mapWithKeys(fn (ClassGroup $c) => [$c->id => [
            'id' => $c->id,
            'name' => $c->name,
            'code' => $c->code,
            'study_program' => $c->study_program,
            'cohort_year' => $c->cohort_year,
            'student_count' => $c->student_count,
            'is_active' => (bool) $c->is_active,
        ]])->all();

        return view('admin.academic.index', [
            'tab' => $tab,
            'period' => $period,
            'classGroups' => $classGroups,
            'classStats' => $classStats,
            'distinctPrograms' => $distinctPrograms,
            'distinctCohorts' => $distinctCohorts,
            'classesPayload' => $classesPayload,
            'courses' => $courses,
            'offerings' => $offerings,
            'offeringStats' => $offeringStats,
            'offeringPrograms' => $offeringPrograms,
            'offeringCohorts' => $offeringCohorts,
            'offeringClasses' => $offeringClasses,
            'offeringSksList' => $offeringSksList,
            'courseOptions' => Course::query()->orderBy('name')->get()->mapWithKeys(fn (Course $course) => [$course->id => "{$course->code} • {$course->name}"]),
            'classOptions' => ClassGroup::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'),
        ]);
    }

    public function updatePeriod(PeriodRequest $request, Period $period): RedirectResponse
    {
        $before = $period->only(array_keys($request->validated()));
        $period->update($request->validated());

        if ($period->start_date !== null && $period->end_date !== null) {
            $alignedEffectiveFrom = now()->startOfDay()->max($period->start_date)->min($period->end_date)->toDateString();

            $period->scheduleVersions()
                ->drafts()
                ->where(fn ($query) => $query
                    ->whereDate('effective_from', '<', $period->start_date->toDateString())
                    ->orWhereDate('effective_from', '>', $period->end_date->toDateString()))
                ->update(['effective_from' => $alignedEffectiveFrom]);
        }

        $this->audit->log('period.updated', $period, $before, $period->only(array_keys($request->validated())));

        return back()->with('toast', ['type' => 'success', 'title' => 'Kalender semester disimpan']);
    }

    public function storeException(CalendarExceptionRequest $request, Period $period): RedirectResponse
    {
        $dates = CarbonPeriod::create($request->validated('date'), $request->validated('until') ?? $request->validated('date'));
        $added = 0;

        foreach ($dates as $date) {
            $exception = $period->calendarExceptions()->firstOrCreate(
                ['date' => $date->toDateString()],
                ['type' => CalendarExceptionType::from($request->validated('type')), 'note' => $request->validated('note')],
            );
            $added += (int) $exception->wasRecentlyCreated;
        }

        $this->audit->log('calendar_exception.created', $period, null, $request->validated());
        AvailabilityCache::bust();

        return back()->with('toast', ['type' => 'success', 'title' => "{$added} tanggal ditambahkan", 'message' => 'Jadwal resmi yang sudah ditetapkan tidak berubah sampai kalender ditetapkan ulang lewat draf.']);
    }

    public function destroyException(CalendarException $exception): RedirectResponse
    {
        $this->audit->log('calendar_exception.deleted', $exception, $exception->only(['date', 'type', 'note']));
        $exception->delete();

        return back()->with('toast', ['type' => 'success', 'title' => 'Hari libur dihapus']);
    }

    public function updateSlots(TimeSlotsRequest $request, Period $period): RedirectResponse
    {
        foreach ($request->validated('slots') as $id => $slot) {
            [$startHour, $startMinute] = array_map('intval', explode(':', $slot['start']));
            [$endHour, $endMinute] = array_map('intval', explode(':', $slot['end']));

            $period->timeSlots()->whereKey($id)->update([
                'start_minute' => $startHour * 60 + $startMinute,
                'end_minute' => $endHour * 60 + $endMinute,
            ]);
        }

        $this->audit->log('time_slots.updated', $period, null, $request->validated('slots'));

        return back()->with('toast', ['type' => 'success', 'title' => 'Slot jam disimpan', 'message' => 'Slot hanya acuan; jam pada jadwal tidak diubah otomatis.']);
    }

    public function storeCourse(CourseRequest $request): RedirectResponse
    {
        $course = Course::create($request->validated());
        $this->audit->log('course.created', $course, null, $request->validated());

        return to_route('admin.academic.index', ['tab' => 'mata-kuliah'])->with('toast', ['type' => 'success', 'title' => "Mata kuliah {$course->name} disimpan"]);
    }

    public function updateCourse(CourseRequest $request, Course $course): RedirectResponse
    {
        $before = $course->only(['code', 'name', 'sks', 'curriculum']);
        $course->update($request->validated());
        $this->audit->log('course.updated', $course, $before, $request->validated());

        return to_route('admin.academic.index', ['tab' => 'mata-kuliah'])->with('toast', ['type' => 'success', 'title' => "Mata kuliah {$course->name} disimpan"]);
    }

    public function destroyCourse(Course $course): RedirectResponse
    {
        if ($course->offerings()->exists()) {
            return back()->with('error', "{$course->name} masih ditawarkan pada semester. Hapus penawarannya dulu.");
        }

        $this->audit->log('course.deleted', $course, $course->only(['code', 'name', 'sks']));
        $course->delete();

        return back()->with('toast', ['type' => 'success', 'title' => 'Mata kuliah dihapus']);
    }

    public function storeClassGroup(ClassGroupRequest $request): RedirectResponse
    {
        $classGroup = ClassGroup::create($request->validated());
        $this->audit->log('class_group.created', $classGroup, null, $request->validated());

        return to_route('admin.academic.index', ['tab' => 'kelas'])->with('toast', ['type' => 'success', 'title' => "Kelas {$classGroup->name} disimpan"]);
    }

    public function updateClassGroup(ClassGroupRequest $request, ClassGroup $classGroup): RedirectResponse
    {
        $before = $classGroup->only(['name', 'code', 'study_program', 'cohort_year', 'student_count', 'is_active']);
        $classGroup->update($request->validated());
        $this->audit->log('class_group.updated', $classGroup, $before, $request->validated());

        return to_route('admin.academic.index', ['tab' => 'kelas'])->with('toast', ['type' => 'success', 'title' => "Kelas {$classGroup->name} disimpan"]);
    }

    public function destroyClassGroup(ClassGroup $classGroup): RedirectResponse
    {
        if ($classGroup->courseOfferings()->exists() || $classGroup->representatives()->activeOn(now())->exists()) {
            return back()->with('error', "{$classGroup->name} masih punya penawaran kuliah atau Keti aktif. Arsipkan (nonaktifkan) kelas ini sebagai gantinya.");
        }

        $this->audit->log('class_group.deleted', $classGroup, $classGroup->only(['name', 'code']));
        $classGroup->delete();

        return back()->with('toast', ['type' => 'success', 'title' => 'Kelas dihapus']);
    }

    public function batchUpdateClassGroups(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'string', 'in:set_student_count,set_cohort_year,set_study_program,activate,archive'],
            'class_ids' => ['required', 'array', 'min:1'],
            'class_ids.*' => ['integer', 'exists:class_groups,id'],
            'student_count' => ['nullable', 'integer', 'min:1', 'max:500'],
            'cohort_year' => ['nullable', 'integer', 'between:2000,2100'],
            'study_program' => ['nullable', 'string', 'max:120'],
        ]);

        $ids = $validated['class_ids'];
        $action = $validated['action'];
        $count = count($ids);

        match ($action) {
            'set_student_count' => ClassGroup::query()->whereIn('id', $ids)->update(['student_count' => $validated['student_count'] ?? null]),
            'set_cohort_year' => (function () use ($ids, $validated): void {
                if (! empty($validated['cohort_year'])) {
                    foreach (ClassGroup::query()->whereIn('id', $ids)->get() as $class) {
                        $code = Str::upper(Str::slug($class->name.' '.$validated['cohort_year']));
                        if (ClassGroup::query()->where('code', $code)->where('id', '!=', $class->id)->exists()) {
                            $code = $code.'-'.$class->id;
                        }
                        $class->update(['cohort_year' => $validated['cohort_year'], 'code' => $code]);
                    }
                }
            })(),
            'set_study_program' => (function () use ($ids, $validated): void {
                $prodi = trim((string) ($validated['study_program'] ?? ''));
                if ($prodi !== '') {
                    ClassGroup::query()->whereIn('id', $ids)->update(['study_program' => $prodi]);
                }
            })(),
            'activate' => ClassGroup::query()->whereIn('id', $ids)->update(['is_active' => true]),
            'archive' => ClassGroup::query()->whereIn('id', $ids)->update(['is_active' => false]),
        };

        if ($count > 0 && ($firstClass = ClassGroup::find($ids[0]))) {
            $this->audit->log('class_group.batch_updated', $firstClass, null, $validated);
        }

        return to_route('admin.academic.index', ['tab' => 'kelas'])->with('toast', [
            'type' => 'success',
            'title' => "Berhasil memperbarui {$count} kelas",
        ]);
    }

    public function bulkUpdateClasses(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'classes' => ['required', 'array', 'min:1'],
            'classes.*.id' => ['required', 'integer', 'exists:class_groups,id'],
            'classes.*.name' => ['required', 'string', 'max:60'],
            'classes.*.study_program' => ['required', 'string', 'max:120'],
            'classes.*.cohort_year' => ['required', 'integer', 'between:2000,2100'],
            'classes.*.student_count' => ['nullable'],
            'classes.*.is_active' => ['nullable'],
        ]);

        $updatedCount = 0;
        foreach ($validated['classes'] as $item) {
            $classGroup = ClassGroup::find($item['id']);
            if (! $classGroup) {
                continue;
            }

            $isActive = ! empty($item['is_active']);
            $cohortYear = (int) $item['cohort_year'];
            $name = trim($item['name']);
            $studentCount = is_numeric($item['student_count'] ?? null) && (int) $item['student_count'] > 0
                ? (int) $item['student_count']
                : null;
            $studyProgram = trim($item['study_program']);

            $code = Str::upper(Str::slug($name.' '.$cohortYear));
            if (ClassGroup::query()->where('code', $code)->where('id', '!=', $classGroup->id)->exists()) {
                $code = $code.'-'.$classGroup->id;
            }

            $classGroup->update([
                'name' => $name,
                'code' => $code,
                'study_program' => $studyProgram,
                'cohort_year' => $cohortYear,
                'student_count' => $studentCount,
                'is_active' => $isActive,
            ]);
            $updatedCount++;
        }

        if (! empty($validated['classes']) && ($firstClass = ClassGroup::find($validated['classes'][0]['id']))) {
            $this->audit->log('class_group.bulk_updated', $firstClass, null, ['count' => $updatedCount]);
        }

        return to_route('admin.academic.index', ['tab' => 'kelas'])->with('toast', [
            'type' => 'success',
            'title' => "Berhasil menyimpan {$updatedCount} kelas",
        ]);
    }

    public function storeOffering(OfferingRequest $request): RedirectResponse
    {
        $offering = CourseOffering::create($request->validated());
        $this->audit->log('course_offering.created', $offering, null, $request->validated());

        return to_route('admin.academic.index', ['tab' => 'penawaran'])->with('toast', ['type' => 'success', 'title' => 'Penawaran kuliah ditambahkan', 'message' => 'Atur tim pengajar di Pembagian SKS.']);
    }

    public function destroyOffering(CourseOffering $offering): RedirectResponse
    {
        if ($offering->scheduleSessions()->exists()) {
            return back()->with('error', 'Penawaran ini sudah punya sesi jadwal. Hapus sesinya lewat draf jadwal dulu.');
        }

        $this->audit->log('course_offering.deleted', $offering, $offering->only(['period_id', 'course_id', 'class_group_id']));
        $offering->teachingAssignments()->delete();
        $offering->delete();

        return back()->with('toast', ['type' => 'success', 'title' => 'Penawaran kuliah dihapus']);
    }

    public function batchDeleteOfferings(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'offering_ids' => ['required', 'array', 'min:1'],
            'offering_ids.*' => ['integer', 'exists:course_offerings,id'],
        ]);

        $ids = $validated['offering_ids'];
        $withSessions = CourseOffering::query()->whereIn('id', $ids)->whereHas('scheduleSessions')->count();
        if ($withSessions > 0) {
            return back()->with('error', "{$withSessions} penawaran tidak dapat dihapus karena sudah memiliki sesi jadwal di draf.");
        }

        $offerings = CourseOffering::query()->whereIn('id', $ids)->get();
        $count = $offerings->count();

        foreach ($offerings as $offering) {
            $this->audit->log('course_offering.deleted', $offering, $offering->only(['period_id', 'course_id', 'class_group_id']));
            $offering->teachingAssignments()->delete();
            $offering->delete();
        }

        return to_route('admin.academic.index', ['tab' => 'penawaran'])->with('toast', [
            'type' => 'success',
            'title' => "Berhasil menghapus {$count} penawaran kuliah",
        ]);
    }
}
