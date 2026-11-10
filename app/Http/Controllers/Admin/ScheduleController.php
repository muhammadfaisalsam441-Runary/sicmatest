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
        $classGroupId = $request->integer('kelas') ?: null;
        $lecturerId = $request->integer('dosen') ?: null;
        $roomId = $request->integer('ruang') ?: null;
        $keyword = trim((string) $request->query('q'));

        $sessions = $official === null ? collect() : ScheduleSession::query()
            ->whereBelongsTo($official, 'version')
            ->with(['room', 'participants', 'lecturers'])
            ->when($day, fn (Builder $query, int $day) => $query->where('day_of_week', $day))
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
            'classGroupId' => $classGroupId,
            'lecturerId' => $lecturerId,
            'keyword' => $keyword,
            'classGroups' => ClassGroup::query()->orderBy('name')->pluck('name', 'id'),
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
