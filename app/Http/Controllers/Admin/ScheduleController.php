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
use App\Support\CsvStream;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
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
            ->map(ClassGroup::studyProgramLabel(...))
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
            $classGroupsQuery->whereStudyProgramMatches($prodi);
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
            ->when($prodi !== '', fn (Builder $query) => $query->whereHas('participants', fn (Builder $query) => $query->whereStudyProgramMatches($prodi)))
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

        [$sessions, $cohortBlockAlternate] = $this->orderSessionsByCohortBlock($sessions);

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
            'cohortBlockAlternate' => $cohortBlockAlternate,
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
     * Unduh templat impor jadwal (Excel .xlsx default dengan styling & sheet referensi, atau CSV).
     */
    public function template(Request $request, ScheduleImportService $imports): StreamedResponse
    {
        if ($request->query('format') === 'csv') {
            return CsvStream::download('templat-jadwal-sicma.csv', [], $imports->templateRows(), ';');
        }

        return response()->streamDownload(function () use ($imports): void {
            $spreadsheet = $imports->templateSpreadsheet();
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, 'templat-jadwal-sicma.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * Urutkan sesi per blok angkatan (terbaru ke terlama) lalu abjad kelas, dan tandai warna selang-seling per kelas.
     *
     * @param  Collection<int, ScheduleSession>  $sessions
     * @return array{0: Collection<int, ScheduleSession>, 1: array<int, bool>}
     */
    private function orderSessionsByCohortBlock(Collection $sessions): array
    {
        if ($sessions->isEmpty()) {
            return [$sessions, []];
        }

        $cohortYearsBySession = [];
        $classNamesBySession = [];
        foreach ($sessions as $session) {
            $cohortYearsBySession[$session->id] = $this->sessionCohortYear($session);
            $classNamesBySession[$session->id] = $session->participants->pluck('name')->sort()->implode(' + ');
        }

        $sorted = $sessions->sort(function (ScheduleSession $a, ScheduleSession $b) use ($cohortYearsBySession, $classNamesBySession): int {
            $cohortA = $cohortYearsBySession[$a->id];
            $cohortB = $cohortYearsBySession[$b->id];

            if ($cohortA !== $cohortB) {
                if ($cohortA === null) {
                    return 1;
                }
                if ($cohortB === null) {
                    return -1;
                }

                return $cohortB <=> $cohortA;
            }

            $classA = $classNamesBySession[$a->id];
            $classB = $classNamesBySession[$b->id];
            if ($classA !== $classB) {
                if ($classA === '') {
                    return 1;
                }
                if ($classB === '') {
                    return -1;
                }

                $cmp = strnatcasecmp($classA, $classB);
                if ($cmp !== 0) {
                    return $cmp;
                }

                return $classA <=> $classB;
            }

            $dayA = $a->day_of_week ?? PHP_INT_MAX;
            $dayB = $b->day_of_week ?? PHP_INT_MAX;
            if ($dayA !== $dayB) {
                return $dayA <=> $dayB;
            }

            $startA = $a->start_minute ?? PHP_INT_MAX;
            $startB = $b->start_minute ?? PHP_INT_MAX;
            if ($startA !== $startB) {
                return $startA <=> $startB;
            }

            $endA = $a->end_minute ?? PHP_INT_MAX;
            $endB = $b->end_minute ?? PHP_INT_MAX;
            if ($endA !== $endB) {
                return $endA <=> $endB;
            }

            return strnatcasecmp($a->title, $b->title);
        })->values();

        $alternateBySession = [];
        $blockIndex = -1;
        $previousBlock = '__init__';

        foreach ($sorted as $session) {
            $className = $classNamesBySession[$session->id];
            $blockKey = ($cohortYearsBySession[$session->id] ?? 'none').'|'.($className !== '' ? mb_strtolower($className) : 'none');
            if ($blockKey !== $previousBlock) {
                $blockIndex++;
                $previousBlock = $blockKey;
            }

            $alternateBySession[$session->id] = $blockIndex % 2 === 1;
        }

        return [$sorted, $alternateBySession];
    }

    /**
     * Ambil tahun angkatan representatif dari peserta sesi.
     */
    private function sessionCohortYear(ScheduleSession $session): ?int
    {
        $year = $session->participants->pluck('cohort_year')->filter(fn (mixed $value): bool => $value !== null)->max();

        return $year === null ? null : (int) $year;
    }
}
