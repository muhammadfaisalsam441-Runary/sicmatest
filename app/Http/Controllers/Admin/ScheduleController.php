<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ScheduleVersionStatus;
use App\Http\Controllers\Controller;
use App\Models\ClassGroup;
use App\Models\Lecturer;
use App\Models\Period;
use App\Models\ScheduleSession;
use App\Services\ScheduleImportService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Kelola Jadwal: jadwal resmi yang berlaku, riwayat revisi, draf, dan jalur impor/susun.
 */
class ScheduleController extends Controller
{
    public function index(Request $request): View
    {
        $period = Period::query()->active()->first();
        $versions = $period?->scheduleVersions()->with('publisher')->orderByDesc('version_no')->get() ?? collect();
        $official = $versions->firstWhere('status', ScheduleVersionStatus::Official);
        $draft = $versions->first(fn ($version): bool => in_array($version->status, [ScheduleVersionStatus::Draft, ScheduleVersionStatus::Ready], true));

        $day = $request->integer('hari') ?: null;
        $classGroupId = $request->integer('kelas') ?: null;
        $lecturerId = $request->integer('dosen') ?: null;
        $keyword = trim((string) $request->query('q'));

        $sessions = $official === null ? collect() : ScheduleSession::query()
            ->whereBelongsTo($official, 'version')
            ->with(['room', 'participants', 'lecturers'])
            ->when($day, fn (Builder $query, int $day) => $query->where('day_of_week', $day))
            ->when($classGroupId, fn (Builder $query, int $id) => $query->whereHas('participants', fn (Builder $query) => $query->whereKey($id)))
            ->when($lecturerId, fn (Builder $query, int $id) => $query->whereHas('lecturers', fn (Builder $query) => $query->whereKey($id)))
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
