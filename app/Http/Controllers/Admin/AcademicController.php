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
use App\Models\CalendarException;
use App\Models\ClassGroup;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\Period;
use App\Services\AuditLogger;
use App\Support\AvailabilityCache;
use Carbon\CarbonPeriod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        return view('admin.academic.index', [
            'tab' => $tab,
            'period' => $period,
            'classGroups' => $tab === 'kelas' ? ClassGroup::query()->withCount('scheduleSessions')->orderBy('cohort_year', 'desc')->orderBy('name')->get() : collect(),
            'courses' => $tab === 'mata-kuliah' ? Course::query()->withCount('offerings')->orderBy('code')->get() : collect(),
            'offerings' => $tab === 'penawaran' && $period ? $period->courseOfferings()->with(['course', 'classGroup', 'teachingAssignments.lecturer'])->get()->sortBy(['classGroup.name', 'course.name']) : collect(),
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
}
