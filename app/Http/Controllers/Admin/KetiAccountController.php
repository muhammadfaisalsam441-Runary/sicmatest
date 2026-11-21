<?php

namespace App\Http\Controllers\Admin;

use App\Enums\KetiApplicationStatus;
use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DeclineApplicationRequest;
use App\Models\Academic\ClassGroup;
use App\Models\User\KetiApplication;
use App\Models\User\User;
use App\Services\Account\KetiApprovalService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Akun Keti: pemeriksaan pengajuan (setujui, minta perbaikan, tolak) dan penonaktifan akun.
 */
class KetiAccountController extends Controller
{
    /**
     * Tab URL → status pengajuan. "Ditolak" juga memuat pengajuan yang diminta diperbaiki.
     *
     * @var array<string, list<KetiApplicationStatus>>
     */
    private const TABS = [
        'pending' => [KetiApplicationStatus::Pending],
        'approved' => [KetiApplicationStatus::Approved],
        'rejected' => [KetiApplicationStatus::Rejected, KetiApplicationStatus::NeedsRevision],
    ];

    public function index(Request $request): View
    {
        $tab = array_key_exists((string) $request->query('status'), self::TABS) ? (string) $request->query('status') : 'pending';
        $keyword = trim((string) $request->query('q'));
        $classGroupFilter = $request->integer('kelas') ?: null;
        $perPage = in_array((int) $request->query('per_page'), [25, 50, 100], true) ? (int) $request->query('per_page') : 25;

        $latestIds = KetiApplication::query()->selectRaw('MAX(id)')->groupBy('user_id');

        $applications = KetiApplication::query()
            ->whereIn('id', $latestIds)
            ->whereIn('status', self::TABS[$tab])
            ->when($keyword !== '', fn (Builder $query) => $query->where(fn (Builder $q) => $q
                ->whereHas('user', fn (Builder $uq) => $uq
                    ->where('name', 'like', "%{$keyword}%")
                    ->orWhere('nim', 'like', "%{$keyword}%")
                    ->orWhere('email', 'like', "%{$keyword}%"))
                ->orWhereHas('classGroup', fn (Builder $cq) => $cq->where('name', 'like', "%{$keyword}%"))
            ))
            ->when($classGroupFilter !== null, fn (Builder $query) => $query->where('class_group_id', $classGroupFilter))
            ->with(['user', 'classGroup', 'reviewer'])
            ->latest()
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        $counts = collect(self::TABS)->map(fn (array $statuses): int => KetiApplication::query()
            ->whereIn('id', $latestIds)
            ->whereIn('status', $statuses)
            ->count());

        return view('admin.keti-accounts.index', [
            'tab' => $tab,
            'applications' => $applications,
            'counts' => $counts,
            'keyword' => $keyword,
            'classGroupFilter' => $classGroupFilter,
            'classGroups' => ClassGroup::query()->where('is_active', true)->orderedForSelection()->pluck('name', 'id'),
            'perPage' => $perPage,
        ]);
    }

    public function batchApprove(Request $request, KetiApprovalService $approvals): RedirectResponse
    {
        $validated = $request->validate([
            'application_ids' => ['required', 'array', 'min:1'],
            'application_ids.*' => ['integer', Rule::exists('keti_applications', 'id')],
        ]);

        $applications = KetiApplication::query()
            ->whereIn('id', $validated['application_ids'])
            ->with(['user', 'classGroup'])
            ->get();

        $approvedCount = 0;
        $failures = [];

        foreach ($applications as $application) {
            try {
                $approvals->approve($application, $request->user());
                $approvedCount++;
            } catch (DomainException $e) {
                $failures[] = "{$application->user->name}: {$e->getMessage()}";
            }
        }

        if ($approvedCount === 0) {
            return back()->with('error', $failures[0] ?? 'Tidak ada pengajuan yang dapat disetujui.');
        }

        if ($failures !== []) {
            return to_route('admin.keti-accounts.index', ['status' => 'approved'])->with('toast', [
                'type' => 'warning',
                'title' => "{$approvedCount} akun disetujui, ".count($failures).' dilewati',
                'message' => implode(' ', array_slice($failures, 0, 2)),
            ]);
        }

        return to_route('admin.keti-accounts.index', ['status' => 'approved'])->with('toast', [
            'type' => 'success',
            'title' => "{$approvedCount} akun Keti disetujui sekaligus",
        ]);
    }

    public function approve(Request $request, KetiApplication $application, KetiApprovalService $approvals): RedirectResponse
    {
        $validated = $request->validate([
            'class_group_id' => ['nullable', Rule::exists('class_groups', 'id')->where('is_active', true)->whereNull('deleted_at')],
        ]);

        $classGroup = isset($validated['class_group_id']) ? ClassGroup::query()->find($validated['class_group_id']) : null;
        $application = $approvals->approve($application, $request->user(), $classGroup);

        return to_route('admin.keti-accounts.index', ['status' => 'approved'])->with('toast', [
            'type' => 'success',
            'title' => 'Akun Keti aktif',
            'message' => "{$application->user->name} kini dapat memesan ruang untuk {$application->classGroup->name}.",
        ]);
    }

    public function requestRevision(DeclineApplicationRequest $request, KetiApplication $application, KetiApprovalService $approvals): RedirectResponse
    {
        $approvals->decline($application, $request->user(), $request->validated('reason'));

        return to_route('admin.keti-accounts.index', ['status' => 'rejected'])->with('toast', ['type' => 'success', 'title' => 'Permintaan perbaikan terkirim']);
    }

    public function reject(DeclineApplicationRequest $request, KetiApplication $application, KetiApprovalService $approvals): RedirectResponse
    {
        $approvals->decline($application, $request->user(), $request->validated('reason'), final: true);

        return to_route('admin.keti-accounts.index', ['status' => 'rejected'])->with('toast', ['type' => 'success', 'title' => 'Pengajuan ditolak', 'message' => 'Alasan penolakan dapat dibaca pendaftar.']);
    }

    public function deactivate(Request $request, User $user, KetiApprovalService $approvals): RedirectResponse
    {
        abort_unless($user->isKeti(), 404);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $approvals->deactivate($user, $request->user(), $validated['reason']);

        return back()->with('toast', ['type' => 'success', 'title' => "Akun {$user->name} dinonaktifkan", 'message' => 'Hak pemesanan dicabut. Riwayat tetap tersimpan.']);
    }
}
