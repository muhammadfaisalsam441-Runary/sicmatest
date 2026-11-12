<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ScheduleSource;
use App\Enums\ScheduleVersionStatus;
use App\Enums\SessionMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PublishScheduleRequest;
use App\Http\Requests\Admin\UpdateSessionRequest;
use App\Models\Academic\Period;
use App\Models\Room\Room;
use App\Models\Scheduling\ScheduleSession;
use App\Models\Scheduling\ScheduleVersion;
use App\Services\Scheduling\ScheduleDraftService;
use App\Services\Scheduling\ScheduleExportService;
use App\Services\Scheduling\SchedulePublisher;
use App\Services\Scheduling\ScheduleValidator;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Draf jadwal: tinjau per kelas/dosen/ruang, ubah penempatan, periksa, dan tetapkan.
 */
class DraftController extends Controller
{
    /**
     * Buat draf revisi dari jadwal resmi yang berlaku.
     */
    public function store(ScheduleDraftService $drafts): RedirectResponse
    {
        $period = Period::query()->active()->firstOrFail();
        $existing = $period->scheduleVersions()->whereIn('status', [ScheduleVersionStatus::Draft, ScheduleVersionStatus::Ready])->latest('version_no')->first();

        if ($existing !== null) {
            return to_route('admin.drafts.show', $existing)->with('toast', ['type' => 'info', 'title' => "Draf v{$existing->version_no} masih terbuka", 'message' => 'Lanjutkan draf ini atau tetapkan dulu sebelum membuat revisi baru.']);
        }

        $draft = $drafts->create($period, ScheduleSource::Manual, $period->scheduleVersions()->official()->latest('version_no')->first());

        return to_route('admin.drafts.show', $draft)->with('toast', ['type' => 'success', 'title' => "Draf v{$draft->version_no} dibuat", 'message' => 'Jadwal resmi tetap berlaku sampai draf ditetapkan.']);
    }

    public function inspections(): View
    {
        $period = Period::query()->active()->first();

        return view('admin.drafts.inspections', [
            'period' => $period,
            'versions' => $period?->scheduleVersions()->whereIn('status', [ScheduleVersionStatus::Draft, ScheduleVersionStatus::Ready])
                ->withCount(['sessions', 'issues' => fn ($query) => $query->whereNull('resolved_at')->where('severity', 'error')])
                ->latest('version_no')->get() ?? collect(),
        ]);
    }

    public function show(Request $request, ScheduleVersion $version, ScheduleDraftService $drafts, ScheduleValidator $validator): View
    {
        $version->load(['period', 'basedOn', 'publisher']);
        [$view, $sessions, $groups] = $this->review($request, $version);

        $issues = $version->issues()->whereNull('resolved_at')->orderBy('severity')->get();
        $problemSessionIds = $issues->where('severity.value', 'error')->flatMap->session_ids->unique();

        $editing = $request->integer('sesi') ? $sessions->firstWhere('id', $request->integer('sesi')) : null;

        $inspection = $request->routeIs('admin.drafts.inspect');
        $filteredIssues = $issues
            ->when(in_array($request->query('tingkat'), ['error', 'warning'], true), fn ($rows) => $rows->filter(fn ($issue) => $issue->severity->value === $request->query('tingkat')))
            ->when($request->filled('jenis'), fn ($rows) => $rows->where('code', $request->query('jenis')));

        return view($inspection ? 'admin.drafts.inspection' : 'admin.drafts.show', [
            'version' => $version,
            'isDraft' => in_array($version->status, [ScheduleVersionStatus::Draft, ScheduleVersionStatus::Ready], true),
            'view' => $view,
            'sessions' => $sessions,
            'groups' => $groups,
            'issues' => $issues,
            'filteredIssues' => $filteredIssues,
            'reviewStatus' => $validator->reviewStatus($version),
            'problemSessionIds' => $problemSessionIds,
            'editing' => $editing,
            'alternatives' => $editing ? $drafts->alternativeRooms($editing) : collect(),
            'rooms' => Room::query()->where('is_active', true)->get()->sortBy('name', SORT_NATURAL)->pluck('name', 'id'),
            'counts' => [
                'total' => $sessions->count(),
                'timed' => $sessions->filter(fn ($session) => $session->mode !== SessionMode::Unscheduled && $session->day_of_week !== null && $session->start_minute !== null && $session->end_minute !== null)->count(),
                'online' => $sessions->where('mode', SessionMode::Online)->count(),
                'unscheduled' => $sessions->where('mode', SessionMode::Unscheduled)->count(),
                'locked' => $sessions->where('is_locked', true)->count(),
            ],
        ]);
    }

    public function excel(Request $request, ScheduleVersion $version, ScheduleExportService $exports): StreamedResponse
    {
        [$view, $sessions, $groups] = $this->review($request, $version);
        $version->load('period');

        return response()->streamDownload(
            fn () => $exports->writeExcel($version, $view, $sessions->count(), $exports->rows($groups)),
            $exports->filename($version, $view, 'xlsx'),
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    public function pdf(Request $request, ScheduleVersion $version, ScheduleExportService $exports): StreamedResponse
    {
        [$view, $sessions, $groups] = $this->review($request, $version);
        $version->load('period');

        $html = view('admin.drafts.pdf', [
            'version' => $version,
            'subtitle' => $exports->subtitle($version, $view, $sessions->count()),
            'groups' => $exports->rows($groups),
        ])->render();
        $pdf = $exports->pdf($html);

        return response()->streamDownload(
            static function () use ($pdf): void {
                echo $pdf;
            },
            $exports->filename($version, $view, 'pdf'),
            ['Content-Type' => 'application/pdf'],
        );
    }

    /**
     * @return array{string, Collection<int, ScheduleSession>, Collection<string, Collection<int, ScheduleSession>>}
     */
    private function review(Request $request, ScheduleVersion $version): array
    {
        $view = $request->query('tinjau');
        $view = is_string($view) && isset(ScheduleExportService::GROUP_LABELS[$view]) ? $view : 'kelas';
        $sessions = $version->sessions()
            ->with(['room', 'participants', 'lecturers'])
            ->orderByRaw('day_of_week is null')
            ->orderBy('day_of_week')
            ->orderBy('start_minute')
            ->orderBy('id')
            ->when($request->integer('hari'), fn ($query, $day) => $query->where('day_of_week', $day))
            ->when($request->filled('prodi'), fn ($query) => $query->whereHas('participants', fn ($q) => $q->where(fn ($sq) => $sq
                ->where('study_program', trim((string) $request->query('prodi')))
                ->orWhere('study_program', 'like', '%'.trim((string) $request->query('prodi')).'%'))))
            ->when($request->integer('angkatan'), fn ($query, $year) => $query->whereHas('participants', fn ($q) => $q->where('cohort_year', $year)))
            ->when($request->integer('kelas'), fn ($query, $id) => $query->whereHas('participants', fn ($q) => $q->whereKey($id)))
            ->when($request->integer('dosen'), fn ($query, $id) => $query->whereHas('lecturers', fn ($q) => $q->whereKey($id)))
            ->when($request->integer('ruang'), fn ($query, $id) => $query->where('room_id', $id))
            ->when($request->filled('q'), fn ($query) => $query->where(fn ($q) => $q->where('title', 'like', '%'.trim((string) $request->query('q')).'%')
                ->orWhereHas('participants', fn ($q) => $q->where('name', 'like', '%'.trim((string) $request->query('q')).'%'))
                ->orWhereHas('lecturers', fn ($q) => $q->where('name', 'like', '%'.trim((string) $request->query('q')).'%'))
                ->orWhereHas('room', fn ($q) => $q->where('name', 'like', '%'.trim((string) $request->query('q')).'%'))))
            ->get();

        $groups = match ($view) {
            'dosen' => $sessions->flatMap(fn (ScheduleSession $session) => collect($session->lecturers->pluck('name')->all() ?: ['Tanpa dosen'])->map(fn (string $name) => [$name, $session]))
                ->groupBy(0)->map(fn ($rows) => $rows->pluck(1))->sortKeys(),
            'ruang' => $sessions->groupBy(fn (ScheduleSession $session): string => match ($session->mode) {
                SessionMode::Online => 'Online',
                SessionMode::Unscheduled => 'Belum terjadwal',
                default => $session->room?->name ?? 'Tanpa ruang',
            })->sortKeys(SORT_NATURAL),
            default => $sessions->flatMap(fn (ScheduleSession $session) => collect($session->participants->pluck('name')->all() ?: ['Tanpa kelas'])->map(fn (string $name) => [$name, $session]))
                ->groupBy(0)->map(fn ($rows) => $rows->pluck(1))->sortKeys(SORT_NATURAL),
        };

        return [$view, $sessions, $groups];
    }

    public function updateSession(UpdateSessionRequest $request, ScheduleVersion $version, ScheduleSession $session, ScheduleDraftService $drafts): RedirectResponse
    {
        $drafts->updateSession($session, $request->placement(), $request->validated('note'));

        return to_route('admin.drafts.show', [$version, 'tinjau' => $request->query('tinjau', 'kelas')])->with('toast', [
            'type' => 'success',
            'title' => 'Perubahan masuk ke draf v'.$version->version_no,
            'message' => 'Jadwal resmi tidak berubah sampai draf ditetapkan.',
        ]);
    }

    public function toggleLock(ScheduleVersion $version, ScheduleSession $session, ScheduleDraftService $drafts): RedirectResponse
    {
        $session = $drafts->toggleLock($session);

        return back()->with('toast', ['type' => 'success', 'title' => $session->is_locked ? 'Sesi dikunci' : 'Kunci sesi dibuka']);
    }

    public function validateDraft(ScheduleVersion $version, ScheduleValidator $validator): RedirectResponse
    {
        $today = now()->startOfDay();
        $effectiveFrom = $version->effective_from
            ? CarbonImmutable::parse($version->effective_from->toDateString())->max($today)
            : $today;

        if ($version->period?->start_date) {
            $effectiveFrom = $effectiveFrom->max(CarbonImmutable::parse($version->period->start_date->toDateString()));
        }

        if ($version->effective_from?->toDateString() !== $effectiveFrom->toDateString()) {
            $version->update(['effective_from' => $effectiveFrom->toDateString()]);
        }

        $issues = $validator->validate($version);
        $blocking = $issues->where('severity.value', 'error')->count();

        return to_route('admin.drafts.inspect', $version)->with('toast', [
            'type' => $blocking > 0 ? 'error' : 'success',
            'title' => $blocking > 0 ? "Selesaikan {$blocking} masalah wajib dulu." : 'Tidak ada masalah wajib',
            'message' => $blocking > 0 ? null : 'Draf siap ditetapkan oleh admin yang berwenang.',
        ]);
    }

    public function publish(PublishScheduleRequest $request, ScheduleVersion $version, SchedulePublisher $publisher): RedirectResponse
    {
        $publisher->publish(
            $version,
            $request->user(),
            CarbonImmutable::parse($request->validated('effective_from')),
            (int) $request->validated('lock_version'),
            $request->validated('note'),
        );

        return to_route('admin.drafts.published', $version);
    }

    public function published(ScheduleVersion $version): View
    {
        abort_unless($version->status === ScheduleVersionStatus::Official, 404);

        return view('admin.drafts.published', [
            'version' => $version->load(['period', 'publisher', 'basedOn']),
            'sessionCount' => $version->sessions()->count(),
            'blockCount' => $version->roomBlocks()->where('is_active', true)->count(),
        ]);
    }
}
