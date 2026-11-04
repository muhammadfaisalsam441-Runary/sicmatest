<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ScheduleSource;
use App\Enums\ScheduleVersionStatus;
use App\Enums\SessionMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PublishScheduleRequest;
use App\Http\Requests\Admin\UpdateSessionRequest;
use App\Models\Period;
use App\Models\Room;
use App\Models\ScheduleSession;
use App\Models\ScheduleVersion;
use App\Services\DraftService;
use App\Services\SchedulePublisher;
use App\Services\ScheduleValidator;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Draf jadwal: tinjau per kelas/dosen/ruang, ubah penempatan, periksa, dan tetapkan.
 */
class DraftController extends Controller
{
    /**
     * Buat draf revisi dari jadwal resmi yang berlaku.
     */
    public function store(DraftService $drafts): RedirectResponse
    {
        $period = Period::query()->active()->firstOrFail();
        $existing = $period->scheduleVersions()->whereIn('status', [ScheduleVersionStatus::Draft, ScheduleVersionStatus::Ready])->latest('version_no')->first();

        if ($existing !== null) {
            return to_route('admin.drafts.show', $existing)->with('toast', ['type' => 'info', 'title' => "Draf v{$existing->version_no} masih terbuka", 'message' => 'Lanjutkan draf ini atau tetapkan dulu sebelum membuat revisi baru.']);
        }

        $draft = $drafts->create($period, ScheduleSource::Manual, $period->scheduleVersions()->official()->latest('version_no')->first());

        return to_route('admin.drafts.show', $draft)->with('toast', ['type' => 'success', 'title' => "Draf v{$draft->version_no} dibuat", 'message' => 'Jadwal resmi tetap berlaku sampai draf ditetapkan.']);
    }

    public function show(Request $request, ScheduleVersion $version, DraftService $drafts): View
    {
        $version->load(['period', 'basedOn', 'publisher']);
        $view = in_array($request->query('tinjau'), ['kelas', 'dosen', 'ruang'], true) ? $request->query('tinjau') : 'kelas';

        $sessions = $version->sessions()
            ->with(['room', 'participants', 'lecturers'])
            ->orderByRaw('day_of_week is null')
            ->orderBy('day_of_week')
            ->orderBy('start_minute')
            ->get();

        $issues = $version->issues()->whereNull('resolved_at')->orderBy('severity')->get();
        $problemSessionIds = $issues->where('severity.value', 'error')->flatMap->session_ids->unique();

        $groups = match ($view) {
            'dosen' => $sessions->flatMap(fn (ScheduleSession $session) => $session->lecturers->map(fn ($lecturer) => [$lecturer->name, $session]))
                ->groupBy(0)->map(fn ($rows) => $rows->pluck(1))->sortKeys(),
            'ruang' => $sessions->groupBy(fn (ScheduleSession $session): string => match ($session->mode) {
                SessionMode::Online => 'Online',
                SessionMode::Unscheduled => 'Belum terjadwal',
                default => $session->room?->name ?? 'Tanpa ruang',
            })->sortKeys(SORT_NATURAL),
            default => $sessions->flatMap(fn (ScheduleSession $session) => $session->participants->map(fn ($classGroup) => [$classGroup->name, $session]))
                ->groupBy(0)->map(fn ($rows) => $rows->pluck(1))->sortKeys(SORT_NATURAL),
        };

        $editing = $request->integer('sesi') ? $sessions->firstWhere('id', $request->integer('sesi')) : null;

        return view('admin.drafts.show', [
            'version' => $version,
            'isDraft' => in_array($version->status, [ScheduleVersionStatus::Draft, ScheduleVersionStatus::Ready], true),
            'view' => $view,
            'sessions' => $sessions,
            'groups' => $groups,
            'issues' => $issues,
            'problemSessionIds' => $problemSessionIds,
            'editing' => $editing,
            'alternatives' => $editing ? $drafts->alternativeRooms($editing) : collect(),
            'rooms' => Room::query()->where('is_active', true)->get()->sortBy('name', SORT_NATURAL)->pluck('name', 'id'),
            'counts' => [
                'total' => $sessions->count(),
                'timed' => $sessions->whereNotNull('day_of_week')->where('mode', '!=', SessionMode::Unscheduled)->count(),
                'online' => $sessions->where('mode', SessionMode::Online)->count(),
                'unscheduled' => $sessions->where('mode', SessionMode::Unscheduled)->count(),
                'locked' => $sessions->where('is_locked', true)->count(),
            ],
        ]);
    }

    public function updateSession(UpdateSessionRequest $request, ScheduleVersion $version, ScheduleSession $session, DraftService $drafts): RedirectResponse
    {
        $drafts->updateSession($session, $request->placement(), $request->validated('note'));

        return to_route('admin.drafts.show', [$version, 'tinjau' => $request->query('tinjau', 'kelas')])->with('toast', [
            'type' => 'success',
            'title' => 'Perubahan masuk ke draf v'.$version->version_no,
            'message' => 'Jadwal resmi tidak berubah sampai draf ditetapkan.',
        ]);
    }

    public function toggleLock(ScheduleVersion $version, ScheduleSession $session, DraftService $drafts): RedirectResponse
    {
        $session = $drafts->toggleLock($session);

        return back()->with('toast', ['type' => 'success', 'title' => $session->is_locked ? 'Sesi dikunci' : 'Kunci sesi dibuka']);
    }

    public function validateDraft(ScheduleVersion $version, ScheduleValidator $validator): RedirectResponse
    {
        $issues = $validator->validate($version);
        $blocking = $issues->where('severity.value', 'error')->count();

        return back()->with('toast', [
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
