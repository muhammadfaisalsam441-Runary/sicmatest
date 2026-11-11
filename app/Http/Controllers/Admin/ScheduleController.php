<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ScheduleVersionStatus;
use App\Http\Controllers\Controller;
use App\Models\Academic\ClassGroup;
use App\Models\Academic\Period;
use App\Models\Lecturer\Lecturer;
use App\Models\Room\Room;
use App\Models\Scheduling\ScheduleSession;
use App\Services\Scheduling\ScheduleImportService;
use App\Services\Scheduling\ScheduleValidator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Kelola Jadwal: jadwal resmi yang berlaku, riwayat revisi, draf, dan jalur impor/susun.
 */
class ScheduleController extends Controller
{
    public function index(Request $request, ScheduleValidator $validator): View
    {
        $period = Period::query()->active()->first();
        $versions = $period?->scheduleVersions()->with('publisher')->orderByDesc('version_no')->get() ?? collect();
        $today = now()->toDateString();
        $published = $versions->filter(fn ($version) => in_array($version->status, [ScheduleVersionStatus::Official, ScheduleVersionStatus::Superseded], true));
        $active = $published->first(fn ($version) => $version->effective_from->toDateString() <= $today
            && ($version->effective_to === null || $version->effective_to->toDateString() >= $today));
        $official = $request->integer('versi') ? $published->firstWhere('id', $request->integer('versi')) : $active;
        abort_if($request->integer('versi') && $official === null, 404);
        $upcoming = $published->filter(fn ($version) => $version->effective_from->toDateString() > $today);
        $draft = $versions->first(fn ($version): bool => in_array($version->status, [ScheduleVersionStatus::Draft, ScheduleVersionStatus::Ready], true));
        $candidates = $versions->filter(fn ($version) => in_array($version->status, [ScheduleVersionStatus::Draft, ScheduleVersionStatus::Ready], true));
        $candidate = $request->integer('draf') ? $candidates->firstWhere('id', $request->integer('draf')) : null;
        abort_if($request->integer('draf') && $candidate === null, 404);

        $day = $request->integer('hari') ?: null;
        $prodi = trim((string) $request->query('prodi'));
        $cohortYear = $request->integer('angkatan') ?: null;
        $classGroupId = $request->integer('kelas') ?: null;
        $lecturerId = $request->integer('dosen') ?: null;
        $roomId = $request->integer('ruang') ?: null;
        $keyword = trim((string) $request->query('q'));

        $studyPrograms = ClassGroup::query()
            ->whereNotNull('study_program')
            ->where('study_program', '!=', '')
            ->distinct()
            ->orderBy('study_program')
            ->pluck('study_program', 'study_program')
            ->map(fn (string $name): string => match ($name) {
                'Pendidikan Teknik Informatika dan Komputer' => 'PTIK (Pend. Teknik Informatika dan Komputer)',
                'Teknik Komputer' => 'TEKOM (Teknik Komputer)',
                default => $name,
            })
            ->all();

        $cohortYears = ClassGroup::query()
            ->whereNotNull('cohort_year')
            ->distinct()
            ->orderByDesc('cohort_year')
            ->pluck('cohort_year', 'cohort_year')
            ->map(fn (int $year): string => "Angkatan {$year}")
            ->all();

        $classGroupsQuery = ClassGroup::query()->orderBy('name');
        if ($prodi !== '') {
            $classGroupsQuery->where(fn (Builder $q) => $q
                ->where('study_program', $prodi)
                ->orWhere('study_program', 'like', "%{$prodi}%"));
        }
        if ($cohortYear) {
            $classGroupsQuery->where('cohort_year', $cohortYear);
        }
        $classGroups = $classGroupsQuery->pluck('name', 'id');

        if (($cohortYear || $prodi !== '') && $classGroupId && ! $classGroups->has($classGroupId)) {
            $classGroupId = null;
        }

        $sessions = $official === null ? collect() : ScheduleSession::query()
            ->whereBelongsTo($official, 'version')
            ->with(['room', 'participants', 'lecturers'])
            ->when($day, fn (Builder $query, int $day) => $query->where('day_of_week', $day))
            ->when($prodi !== '', fn (Builder $query) => $query->whereHas('participants', fn (Builder $query) => $query->where(fn (Builder $q) => $q
                ->where('study_program', $prodi)
                ->orWhere('study_program', 'like', "%{$prodi}%"))))
            ->when($cohortYear, fn (Builder $query, int $year) => $query->whereHas('participants', fn (Builder $query) => $query->where('cohort_year', $year)))
            ->when($classGroupId, fn (Builder $query, int $id) => $query->whereHas('participants', fn (Builder $query) => $query->whereKey($id)))
            ->when($lecturerId, fn (Builder $query, int $id) => $query->whereHas('lecturers', fn (Builder $query) => $query->whereKey($id)))
            ->when($roomId, fn (Builder $query, int $id) => $query->where('room_id', $id))
            ->when($keyword !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('title', 'like', "%{$keyword}%")
                ->orWhereHas('room', fn (Builder $query) => $query->where('name', 'like', "%{$keyword}%"))
                ->orWhereHas('participants', fn (Builder $query) => $query->where('name', 'like', "%{$keyword}%"))
                ->orWhereHas('lecturers', fn (Builder $query) => $query->where('name', 'like', "%{$keyword}%"))))
            ->orderByRaw('day_of_week is null')
            ->orderBy('day_of_week')
            ->orderBy('start_minute')
            ->get();

        return view('admin.schedules.index', [
            'period' => $period,
            'versions' => $versions,
            'official' => $official,
            'active' => $active,
            'upcoming' => $upcoming,
            'tab' => $request->query('tab') === 'penetapan' ? 'penetapan' : 'jadwal',
            'candidate' => $candidate,
            'candidates' => $candidates,
            'reviewStatus' => $candidate ? $validator->reviewStatus($candidate) : null,
            'roomId' => $roomId,
            'rooms' => Room::query()->orderBy('name')->pluck('name', 'id'),
            'blockCount' => $official?->roomBlocks()->where('is_active', true)->count() ?? 0,
            'draft' => $draft,
            'sessions' => $sessions,
            'day' => $day,
            'prodi' => $prodi,
            'studyPrograms' => $studyPrograms,
            'cohortYear' => $cohortYear,
            'cohortYears' => $cohortYears,
            'classGroupId' => $classGroupId,
            'lecturerId' => $lecturerId,
            'keyword' => $keyword,
            'classGroups' => $classGroups,
            'lecturers' => Lecturer::query()->orderBy('name')->pluck('name', 'id'),
        ]);
    }

    /**
     * Unduh templat impor jadwal (CSV, dapat dibuka di Excel).
     */
    public function template(ScheduleImportService $imports): StreamedResponse
    {
        return response()->streamDownload(function () use ($imports): void {
            $handle = fopen('php://output', 'w');
            foreach ($imports->templateRows() as $row) {
                fputcsv($handle, $row);
            }
            fclose($handle);
        }, 'templat-jadwal-sicma.csv', ['Content-Type' => 'text/csv']);
    }
}
