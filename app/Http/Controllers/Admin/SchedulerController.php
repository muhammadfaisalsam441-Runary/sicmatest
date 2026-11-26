<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ScheduleVersionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StartSchedulingRequest;
use App\Models\Academic\ClassGroup;
use App\Models\Academic\Period;
use App\Models\Scheduling\Import;
use App\Models\Scheduling\OptimizationJob;
use App\Models\Scheduling\ScheduleVersion;
use App\Services\Scheduling\SchedulerService;
use App\Services\Scheduling\ScheduleValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Susun Jadwal: Pengaturan Generate → Periksa kesiapan → Proses → Hasil draf (Generate → Tinjau → Tetapkan).
 */
class SchedulerController extends Controller
{
    public function create(Request $request, SchedulerService $scheduler, ScheduleValidator $validator): View
    {
        $period = Period::query()->active()->first();
        $activeDraft = $period?->resolveActiveDraft();

        $versions = ($period?->scheduleVersions()
            ->whereIn('status', [ScheduleVersionStatus::Draft, ScheduleVersionStatus::Ready])
            ->withCount(['sessions', 'issues' => fn ($query) => $query->whereNull('resolved_at')->where('severity', 'error')])
            ->latest('version_no')
            ->get() ?? collect())
            ->sort(fn (ScheduleVersion $a, ScheduleVersion $b): int => [($b->id === $activeDraft?->id ? 1 : 0), $b->version_no] <=> [($a->id === $activeDraft?->id ? 1 : 0), $a->version_no])
            ->values();

        return view('admin.scheduler.create', [
            'period' => $period,
            'base' => $period ? $scheduler->baseVersion($period) : null,
            'official' => $period?->scheduleVersions()->official()->latest('version_no')->first(),
            'versions' => $versions,
            'activeDraft' => $activeDraft,
            'activeDraftId' => $activeDraft?->id,
            'activeReviewStatus' => $activeDraft ? $validator->reviewStatus($activeDraft) : null,
            'classGroups' => ClassGroup::query()->where('is_active', true)->orderBy('study_program')->orderByDesc('cohort_year')->orderBy('name')->get(['id', 'name', 'study_program', 'cohort_year']),
            'readiness' => session('readiness'),
            'lastJob' => OptimizationJob::query()->latest()->first(),
            'recentJobs' => OptimizationJob::query()->with(['creator', 'version'])->latest()->limit(8)->get(),
            'periods' => Period::query()->orderByDesc('start_date')->pluck('name', 'id'),
            'recentImports' => Import::query()->with('scheduleVersion')->latest()->limit(5)->get(),
        ]);
    }

    public function check(StartSchedulingRequest $request, SchedulerService $scheduler): RedirectResponse
    {
        $period = Period::query()->active()->firstOrFail();

        return back()->withInput()->with('readiness', $scheduler->readiness($period, $request->scopeData()));
    }

    public function store(StartSchedulingRequest $request, SchedulerService $scheduler): RedirectResponse
    {
        $job = $scheduler->start(Period::query()->active()->firstOrFail(), $request->scopeData(), (int) config('sicma.scheduler.time_limits.seimbang'), $request->user());

        return to_route('admin.scheduler.show', $job);
    }

    public function show(OptimizationJob $job, SchedulerService $scheduler): View
    {
        $job = $scheduler->poll($job);

        return view('admin.scheduler.show', ['job' => $job->load('creator'), 'canRetry' => $scheduler->canRetry($job)]);
    }

    /**
     * Status proses untuk polling halaman proses.
     */
    public function status(OptimizationJob $job, SchedulerService $scheduler): JsonResponse
    {
        $job = $scheduler->poll($job);
        $draftId = $job->result['draft_version_id'] ?? null;

        return response()->json([
            'status' => $job->status->value,
            'solver_status' => $job->solver_status?->value,
            'elapsed' => $job->started_at ? max(0, (int) $job->started_at->diffInSeconds($job->finished_at ?? now())) : 0,
            'time_limit' => $job->time_limit_seconds,
            'progress' => $job->result['progress'] ?? ['stage' => $job->status->label(), 'total' => $job->scope['in_scope'] ?? 0, 'placed' => $job->result['placed'] ?? 0],
            'draft_version_id' => $draftId,
            'draft_version_no' => $job->result['draft_version_no'] ?? null,
            'inspect_url' => $draftId !== null ? route('admin.drafts.inspect', $draftId) : null,
        ]);
    }

    public function cancel(Request $request, OptimizationJob $job, SchedulerService $scheduler): RedirectResponse
    {
        $scheduler->cancel($job, $request->user());

        return to_route('admin.scheduler.show', $job);
    }

    public function history(): View
    {
        return view('admin.scheduler.history', [
            'jobs' => OptimizationJob::query()->with(['creator', 'version'])->latest()->paginate(20),
        ]);
    }

    public function retry(Request $request, OptimizationJob $job, SchedulerService $scheduler): RedirectResponse
    {
        return to_route('admin.scheduler.show', $scheduler->retry($job, $request->user()));
    }
}
