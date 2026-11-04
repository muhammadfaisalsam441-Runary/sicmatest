<?php

namespace App\Http\Controllers\Admin;

use App\Enums\LecturerHomebase;
use App\Enums\LecturerProfileStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ImportLecturersRequest;
use App\Http\Requests\Admin\LecturerRequest;
use App\Models\Lecturer;
use App\Models\LecturerLoadTarget;
use App\Models\Period;
use App\Services\LecturerService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    public function index(Request $request): View
    {
        $filter = array_key_exists((string) $request->query('filter'), self::FILTERS) ? (string) $request->query('filter') : 'semua';
        $keyword = trim((string) $request->query('q'));
        $perPage = in_array((int) $request->query('per_page'), [25, 50, 100], true) ? (int) $request->query('per_page') : 25;
        $period = Period::query()->active()->first();

        $query = Lecturer::query()
            ->with(['leadershipTerms', 'loadTargets' => fn ($query) => $query->where('period_id', $period?->id)])
            ->withCount(['teachingAssignments as course_count' => fn (Builder $query) => $query->whereHas('courseOffering', fn (Builder $query) => $query->where('period_id', $period?->id))])
            ->when($keyword !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query->where('name', 'like', "%{$keyword}%")
                ->orWhere('source_name', 'like', "%{$keyword}%")
                ->orWhere('nidn', 'like', "%{$keyword}%")
            ))
            ->when($filter === 'jtik', fn (Builder $query) => $query->where('homebase', LecturerHomebase::Jtik))
            ->when($filter === 'luar', fn (Builder $query) => $query->where('homebase', LecturerHomebase::External))
            ->when($filter === 'pimpinan', fn (Builder $query) => $query->whereHas('leadershipTerms', fn (Builder $query) => $query->activeOn(now())))
            ->when($filter === 'verifikasi', fn (Builder $query) => $query->where('profile_status', LecturerProfileStatus::Unverified))
            ->orderBy('name');

        $lecturers = $query->paginate($perPage)->withQueryString();

        return view('admin.lecturers.index', [
            'lecturers' => $lecturers,
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

    public function batchUpdate(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'string', 'in:verify,unverify,homebase_jtik,homebase_external,set_target_sks'],
            'lecturer_ids' => ['required', 'array', 'min:1'],
            'lecturer_ids.*' => ['integer', 'exists:lecturers,id'],
            'target_sks' => ['nullable', 'numeric', 'min:0', 'max:60'],
            'max_sks' => ['nullable', 'numeric', 'min:0', 'max:60'],
        ]);

        $ids = $validated['lecturer_ids'];
        $action = $validated['action'];
        $count = count($ids);

        match ($action) {
            'verify' => Lecturer::query()->whereIn('id', $ids)->update(['profile_status' => LecturerProfileStatus::Verified]),
            'unverify' => Lecturer::query()->whereIn('id', $ids)->update(['profile_status' => LecturerProfileStatus::Unverified]),
            'homebase_jtik' => Lecturer::query()->whereIn('id', $ids)->update(['homebase' => LecturerHomebase::Jtik]),
            'homebase_external' => Lecturer::query()->whereIn('id', $ids)->update(['homebase' => LecturerHomebase::External]),
            'set_target_sks' => (function () use ($ids, $validated): void {
                $period = Period::query()->active()->first();
                if ($period !== null) {
                    foreach ($ids as $id) {
                        LecturerLoadTarget::query()->updateOrCreate(
                            ['lecturer_id' => $id, 'period_id' => $period->id],
                            [
                                'target_sks' => $validated['target_sks'] ?? null,
                                'max_sks' => $validated['max_sks'] ?? null,
                            ]
                        );
                    }
                }
            })(),
        };

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
