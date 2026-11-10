<?php

namespace App\Http\Controllers\Admin;

use App\Enums\KetiApplicationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DeclineApplicationRequest;
use App\Models\Academic\ClassGroup;
use App\Models\User\KetiApplication;
use App\Models\User\User;
use App\Services\Account\KetiApprovalService;
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

        $latestIds = KetiApplication::query()->selectRaw('MAX(id)')->groupBy('user_id');

        $applications = KetiApplication::query()
            ->whereIn('id', $latestIds)
            ->whereIn('status', self::TABS[$tab])
            ->with(['user', 'classGroup', 'reviewer'])
            ->latest()
            ->get();

        $counts = collect(self::TABS)->map(fn (array $statuses): int => KetiApplication::query()
            ->whereIn('id', $latestIds)
            ->whereIn('status', $statuses)
            ->count());

        return view('admin.keti-accounts.index', [
            'tab' => $tab,
            'applications' => $applications,
            'counts' => $counts,
            'classGroups' => ClassGroup::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'),
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
