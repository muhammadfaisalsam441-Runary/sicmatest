<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StartSchedulingRequest;
use App\Models\Academic\ClassGroup;
use App\Models\Academic\Period;
use App\Models\Scheduling\OptimizationJob;
use App\Services\Scheduling\SchedulerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Susun Jadwal: Pengaturan Generate → Periksa kesiapan → Proses → Hasil draf (Generate → Tinjau → Tetapkan).
 */
class SchedulerController extends Controller
{
    public function create(Request $request, SchedulerService $scheduler): View
    {
        $period = Period::query()->active()->first();

        return view('admin.scheduler.create', [
            'period' => $period,
            'base' => $period ? $scheduler->baseVersion($period) : null,
            'official' => $period?->scheduleVersions()->official()->latest('version_no')->first(),
            'classGroups' => ClassGroup::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'),
            'readiness' => session('readiness'),
            'lastJob' => OptimizationJob::query()->latest()->first(),
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

        return response()->json([
            'status' => $job->status->value,
            'solver_status' => $job->solver_status?->value,
            'elapsed' => $job->started_at ? max(0, (int) $job->started_at->diffInSeconds($job->finished_at ?? now())) : 0,
            'time_limit' => $job->time_limit_seconds,
            'progress' => $job->result['progress'] ?? ['stage' => $job->status->label(), 'total' => $job->scope['in_scope'] ?? 0, 'placed' => $job->result['placed'] ?? 0],
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
