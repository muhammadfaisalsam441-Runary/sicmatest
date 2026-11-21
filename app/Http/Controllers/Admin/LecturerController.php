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
use App\Models\Lecturer\LecturerLoadTarget;
use App\Models\Lecturer\TeachingAssignment;
use App\Services\Academic\LecturerService;
use App\Support\CsvStream;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Data Dosen: daftar dari jadwal, verifikasi profil, masa jabatan pimpinan (B11), target beban, impor.
 */
class LecturerController extends Controller
{
    /**
     * @var array<string, string>
     */
    public const FILTERS = ['semua' => 'Semua', 'jtik' => 'Homebase JTIK', 'luar' => 'Luar JTIK', 'pimpinan' => 'Pimpinan', 'verifikasi' => 'Perlu verifikasi'];

    /**
     * @var list<string>
     */
    private const SORTS = ['name', 'homebase', 'status', 'target_sks'];

    public function index(Request $request, LecturerService $lecturerService): View
    {
        $filter = array_key_exists((string) $request->query('filter'), self::FILTERS) ? (string) $request->query('filter') : 'semua';
        $keyword = trim((string) $request->query('q'));
        $perPage = in_array((int) $request->query('per_page'), [25, 50, 100], true) ? (int) $request->query('per_page') : 25;
        $sort = in_array((string) $request->query('sort'), self::SORTS, true) ? (string) $request->query('sort') : 'name';
        $dir = $request->query('dir') === 'desc' ? 'desc' : 'asc';
        $period = Period::query()->active()->first();

        $lecturers = $this->filteredQuery($period, $filter, $keyword, $sort, $dir)
            ->paginate($perPage)
            ->withQueryString();
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

        $lecturersPayload = $lecturers->getCollection()->mapWithKeys(function (Lecturer $lecturer): array {
            $target = $lecturer->loadTargets->first();

            return [$lecturer->id => [
                'id' => $lecturer->id,
                'name' => $lecturer->name,
                'nip' => $lecturer->nip ?? '',
                'nidn' => $lecturer->nidn ?? '',
                'homebase' => $lecturer->homebase?->value ?? '',
                'profile_status' => $lecturer->profile_status->value,
                'target_sks' => $target?->target_sks !== null ? (string) $target->target_sks : '',
                'max_sks' => $target?->max_sks !== null ? (string) $target->max_sks : '',
            ]];
        })->all();

        return view('admin.lecturers.index', [
            'lecturers' => $lecturers,
            'lecturersPayload' => $lecturersPayload,
            'courses' => $courses,
            'courseSelections' => $courseSelections,
            'filter' => $filter,
            'keyword' => $keyword,
            'period' => $period,
            'perPage' => $perPage,
            'sort' => $sort,
            'dir' => $dir,
            'total' => Lecturer::query()->count(),
            'unverified' => Lecturer::query()->where('profile_status', LecturerProfileStatus::Unverified)->count(),
            'missingNidn' => Lecturer::query()->where(fn (Builder $query) => $query->whereNull('nidn')->orWhere('nidn', ''))->count(),
            'jtikCount' => Lecturer::query()->where('homebase', LecturerHomebase::Jtik)->count(),
            'externalCount' => Lecturer::query()->where('homebase', LecturerHomebase::External)->count(),
            'leaderCount' => Lecturer::query()->whereHas('leadershipTerms', fn (Builder $query) => $query->activeOn(now()))->count(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filter = array_key_exists((string) $request->query('filter'), self::FILTERS) ? (string) $request->query('filter') : 'semua';
        $keyword = trim((string) $request->query('q'));
        $sort = in_array((string) $request->query('sort'), self::SORTS, true) ? (string) $request->query('sort') : 'name';
        $dir = $request->query('dir') === 'desc' ? 'desc' : 'asc';
        $period = Period::query()->active()->first();

        $lecturers = $this->filteredQuery($period, $filter, $keyword, $sort, $dir)->get();

        $rows = $lecturers->map(function (Lecturer $lecturer): array {
            $term = $lecturer->leadershipTerms->first(fn ($t) => $t->isActiveOn(now()));
            $target = $lecturer->loadTargets->first();

            return [
                $lecturer->name,
                $lecturer->nip ?? '',
                $lecturer->nidn ?? '',
                $lecturer->homebase?->label() ?? 'Belum diatur',
                $lecturer->functional_position ?? '',
                $lecturer->rank ?? '',
                $lecturer->grade ?? '',
                $lecturer->profile_status->label(),
                $term?->position ?? '',
                $target?->target_sks ?? '',
                $target?->max_sks ?? '',
            ];
        });

        return CsvStream::download(
            'data-dosen-'.now()->toDateString().'.csv',
            [
                'Nama',
                'NIP',
                'NIDN',
                'Homebase',
                'Jabatan Fungsional',
                'Pangkat',
                'Golongan',
                'Status Profil',
                'Jabatan Pimpinan',
                'Target SKS',
                'Maks SKS',
            ],
            $rows,
        );
    }

    public function bulkUpdate(Request $request, LecturerService $lecturers): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'lecturers' => ['required', 'array', 'min:1'],
            'lecturers.*.id' => ['required', 'integer', 'distinct', Rule::exists('lecturers', 'id')->whereNull('deleted_at')],
            'lecturers.*.name' => ['required', 'string', 'max:150'],
            'lecturers.*.nip' => ['nullable', 'regex:/^\d{18}$/', 'distinct'],
            'lecturers.*.nidn' => ['nullable', 'string', 'max:20', 'distinct'],
            'lecturers.*.homebase' => ['nullable', Rule::enum(LecturerHomebase::class)],
            'lecturers.*.profile_status' => ['required', Rule::enum(LecturerProfileStatus::class)],
            'lecturers.*.target_sks' => ['nullable', 'integer', 'min:0', 'max:60'],
            'lecturers.*.max_sks' => ['nullable', 'integer', 'min:0', 'max:60'],
        ], [
            'lecturers.*.nip.regex' => 'NIP harus terdiri atas 18 angka.',
            'lecturers.*.nip.distinct' => 'NIP tidak boleh duplikat pada daftar.',
            'lecturers.*.nidn.distinct' => 'NIDN tidak boleh duplikat pada daftar.',
        ]);

        $validator->after(function (\Illuminate\Validation\Validator $validator) use ($request): void {
            $items = array_filter((array) $request->input('lecturers', []), 'is_array');
            $nips = collect($items)->map(fn (array $item): string => trim((string) ($item['nip'] ?? '')))->filter()->unique()->values()->all();
            $nidns = collect($items)->map(fn (array $item): string => trim((string) ($item['nidn'] ?? '')))->filter()->unique()->values()->all();

            $ownersByNip = $nips !== []
                ? Lecturer::query()->withTrashed()->whereIn('nip', $nips)->pluck('id', 'nip')->all()
                : [];
            $ownersByNidn = $nidns !== []
                ? Lecturer::query()->withTrashed()->whereIn('nidn', $nidns)->pluck('id', 'nidn')->all()
                : [];

            foreach ($items as $index => $item) {
                $rowId = is_numeric($item['id'] ?? null) ? (int) $item['id'] : null;
                $nip = trim((string) ($item['nip'] ?? ''));
                $nidn = trim((string) ($item['nidn'] ?? ''));

                if ($nip !== '' && ! $validator->errors()->has("lecturers.{$index}.nip")) {
                    $ownerId = $ownersByNip[$nip] ?? null;
                    if ($ownerId !== null && $ownerId !== $rowId) {
                        $validator->errors()->add("lecturers.{$index}.nip", 'NIP sudah terdaftar pada dosen lain.');
                    }
                }

                if ($nidn !== '' && ! $validator->errors()->has("lecturers.{$index}.nidn")) {
                    $ownerId = $ownersByNidn[$nidn] ?? null;
                    if ($ownerId !== null && $ownerId !== $rowId) {
                        $validator->errors()->add("lecturers.{$index}.nidn", 'NIDN sudah terdaftar pada dosen lain.');
                    }
                }

                if (isset($item['target_sks'], $item['max_sks']) && $item['target_sks'] !== '' && $item['max_sks'] !== '' && (int) $item['max_sks'] < (int) $item['target_sks']) {
                    $validator->errors()->add("lecturers.{$index}.max_sks", 'Maksimum SKS harus lebih besar atau sama dengan target SKS.');
                }
            }
        });

        $validated = $validator->validate();
        $count = $lecturers->bulkUpdate($validated['lecturers']);

        return back()->with('toast', [
            'type' => 'success',
            'title' => "Berhasil menyimpan {$count} dosen",
        ]);
    }

    /**
     * @return Builder<Lecturer>
     */
    private function filteredQuery(?Period $period, string $filter, string $keyword, string $sort, string $dir): Builder
    {
        return Lecturer::query()
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
            ->when($sort === 'homebase', fn (Builder $query) => $query->orderBy('homebase', $dir)->orderBy('name'))
            ->when($sort === 'status', fn (Builder $query) => $query->orderBy('profile_status', $dir)->orderBy('name'))
            ->when($sort === 'target_sks' && $period !== null, fn (Builder $query) => $query->orderBy(
                LecturerLoadTarget::query()
                    ->select('target_sks')
                    ->whereColumn('lecturer_id', 'lecturers.id')
                    ->where('period_id', $period->id)
                    ->limit(1),
                $dir
            )->orderBy('name'))
            ->when($sort === 'name' || ($sort === 'target_sks' && $period === null), fn (Builder $query) => $query->orderBy('name', $dir));
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
