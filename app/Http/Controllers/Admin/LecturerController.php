<?php

namespace App\Http\Controllers\Admin;

use App\Enums\LecturerHomebase;
use App\Enums\LecturerProfileStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ImportLecturersRequest;
use App\Http\Requests\Admin\LecturerRequest;
use App\Models\Lecturer;
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
        $period = Period::query()->active()->first();

        $lecturers = Lecturer::query()
            ->with(['leadershipTerms', 'loadTargets' => fn ($query) => $query->where('period_id', $period?->id)])
            ->withCount(['teachingAssignments as course_count' => fn (Builder $query) => $query->whereHas('courseOffering', fn (Builder $query) => $query->where('period_id', $period?->id))])
            ->when($keyword !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query->where('name', 'like', "%{$keyword}%")->orWhere('source_name', 'like', "%{$keyword}%")))
            ->when($filter === 'jtik', fn (Builder $query) => $query->where('homebase', LecturerHomebase::Jtik))
            ->when($filter === 'luar', fn (Builder $query) => $query->where('homebase', LecturerHomebase::External))
            ->when($filter === 'pimpinan', fn (Builder $query) => $query->whereHas('leadershipTerms', fn (Builder $query) => $query->activeOn(now())))
            ->when($filter === 'verifikasi', fn (Builder $query) => $query->where('profile_status', LecturerProfileStatus::Unverified))
            ->orderBy('name')
            ->get();

        return view('admin.lecturers.index', [
            'lecturers' => $lecturers,
            'filter' => $filter,
            'keyword' => $keyword,
            'period' => $period,
            'total' => Lecturer::query()->count(),
            'unverified' => Lecturer::query()->where('profile_status', LecturerProfileStatus::Unverified)->count(),
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
