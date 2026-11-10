<?php

namespace App\Http\Controllers\Admin;

use App\Enums\LecturerHomebase;
use App\Enums\LecturerProfileStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BatchLecturerRequest;
use App\Http\Requests\Admin\ImportLecturersRequest;
use App\Http\Requests\Admin\LecturerRequest;
use App\Models\Academic\Course;
use App\Models\Academic\CourseOffering;
use App\Models\Academic\Period;
use App\Models\Lecturer\Lecturer;
use App\Models\Lecturer\LecturerExpertise;
use App\Models\Lecturer\TeachingAssignment;
use App\Services\Academic\LecturerService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Data Dosen: daftar dari jadwal, verifikasi profil, masa jabatan pimpinan (B11), target beban, impor.
 */
class LecturerController extends Controller
{
    /**
     * @var array<string, string>
     */
    public const FILTERS = ['semua' => 'Semua', 'jtik' => 'Homebase JTIK', 'luar' => 'Luar JTIK', 'pimpinan' => 'Pimpinan', 'verifikasi' => 'Perlu verifikasi'];

    public function index(Request $request, LecturerService $lecturerService): View
    {
        $filter = array_key_exists((string) $request->query('filter'), self::FILTERS) ? (string) $request->query('filter') : 'semua';
        $keyword = trim((string) $request->query('q'));
        $perPage = in_array((int) $request->query('per_page'), [25, 50, 100], true) ? (int) $request->query('per_page') : 25;
        $period = Period::query()->active()->first();

        $query = Lecturer::query()
            ->with([
                'leadershipTerms',
                'loadTargets' => fn ($query) => $query->where('period_id', $period?->id),
                'expertises' => fn ($query) => $query->where('field', LecturerExpertise::ADMIN_TEACHING)->whereNotNull('course_id'),
            ])
            ->when($keyword !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query->where('name', 'like', "%{$keyword}%")
                ->orWhere('source_name', 'like', "%{$keyword}%")
                ->orWhere('nidn', 'like', "%{$keyword}%")
                ->orWhere('nip', 'like', "%{$keyword}%")
            ))
            ->when($filter === 'jtik', fn (Builder $query) => $query->where('homebase', LecturerHomebase::Jtik))
            ->when($filter === 'luar', fn (Builder $query) => $query->where('homebase', LecturerHomebase::External))
            ->when($filter === 'pimpinan', fn (Builder $query) => $query->whereHas('leadershipTerms', fn (Builder $query) => $query->activeOn(now())))
            ->when($filter === 'verifikasi', fn (Builder $query) => $query->where('profile_status', LecturerProfileStatus::Unverified))
            ->orderBy('name');

        $lecturers = $query->paginate($perPage)->withQueryString();
        $courses = Course::query()->orderBy('name')->orderBy('code')->get(['id', 'code', 'name']);
        $courseIds = $courses->pluck('id')->all();
        $sourceCourses = [];

        if ($period !== null && $lecturers->isNotEmpty()) {
            $scheduled = TeachingAssignment::query()
                ->join('course_offerings', 'course_offerings.id', '=', 'teaching_assignments.course_offering_id')
                ->where('course_offerings.period_id', $period->id)
                ->whereIn('teaching_assignments.lecturer_id', $lecturers->pluck('id'))
                ->distinct()
                ->get(['teaching_assignments.lecturer_id', 'course_offerings.course_id']);
            foreach ($scheduled as $assignment) {
                $sourceCourses[$assignment->lecturer_id][] = $assignment->course_id;
            }

            $offeredIds = CourseOffering::query()->whereBelongsTo($period)->distinct()->pluck('course_id')->flip();
            $roster = $lecturerService->rosterInstructorIds($lecturers->getCollection(), [$period->id]);
            foreach ($courses as $course) {
                if (! $offeredIds->has($course->id)) {
                    continue;
                }

                $name = Str::lower(Str::squish($course->name));
                foreach ($roster[$period->id][$name] ?? [] as $lecturerId) {
                    $sourceCourses[$lecturerId][] = $course->id;
                }
            }
        }

        $courseSelections = $lecturers->getCollection()->mapWithKeys(function (Lecturer $lecturer) use ($sourceCourses, $courseIds): array {
            $source = array_values(array_unique(array_intersect($sourceCourses[$lecturer->id] ?? [], $courseIds)));
            $manual = $lecturer->expertises->pluck('course_id')->intersect($courseIds)->unique()->values()->all();

            return [$lecturer->id => ['source' => $source, 'manual' => $manual, 'count' => count(array_unique([...$source, ...$manual]))]];
        });

        return view('admin.lecturers.index', [
            'lecturers' => $lecturers,
            'courses' => $courses,
            'courseSelections' => $courseSelections,
            'filter' => $filter,
            'keyword' => $keyword,
            'period' => $period,
            'perPage' => $perPage,
            'total' => Lecturer::query()->count(),
            'unverified' => Lecturer::query()->where('profile_status', LecturerProfileStatus::Unverified)->count(),
            'jtikCount' => Lecturer::query()->where('homebase', LecturerHomebase::Jtik)->count(),
            'externalCount' => Lecturer::query()->where('homebase', LecturerHomebase::External)->count(),
            'leaderCount' => Lecturer::query()->whereHas('leadershipTerms', fn (Builder $query) => $query->activeOn(now()))->count(),
        ]);
    }

    public function batchUpdate(BatchLecturerRequest $request, LecturerService $lecturers): RedirectResponse
    {
        $count = $lecturers->batchUpdate(
            array_map('intval', $request->validated('lecturer_ids')),
            $request->validated('action'),
            $request->safe()->only(['target_sks', 'max_sks']),
        );

        return back()->with('toast', [
            'type' => 'success',
            'title' => "Berhasil memperbarui {$count} dosen",
        ]);
    }

    public function store(LecturerRequest $request, LecturerService $lecturers): RedirectResponse
    {
        $lecturer = $lecturers->save(null, $request->validated());

        return to_route('admin.lecturers.index')->with('toast', ['type' => 'success', 'title' => "{$lecturer->name} disimpan"]);
    }

    public function update(LecturerRequest $request, Lecturer $lecturer, LecturerService $lecturers): RedirectResponse
    {
        $lecturers->save($lecturer, $request->validated());

        return back()->with('toast', ['type' => 'success', 'title' => "{$lecturer->name} disimpan"]);
    }

    public function import(ImportLecturersRequest $request, LecturerService $lecturers): RedirectResponse
    {
        $result = $lecturers->import($request->file('file'));

        return back()->with('toast', ['type' => 'success', 'title' => "{$result['created']} dosen baru, {$result['updated']} diperbarui", 'message' => 'Dosen tanpa NIDN tetap berstatus Perlu verifikasi.']);
    }
}
