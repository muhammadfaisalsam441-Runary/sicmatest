<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ResubmitApplicationRequest;
use App\Models\Academic\ClassGroup;
use App\Services\Account\KetiApplicationService;
use App\Services\Account\KetiApprovalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ApplicationStatusController extends Controller
{
    public function show(Request $request, KetiApprovalService $approvals): View|RedirectResponse
    {
        $user = $request->user();

        if ($user->isAdmin()) {
            return redirect()->route('admin.dashboard');
        }

        if (! $user->hasVerifiedEmail()) {
            return redirect()->route('verification.notice');
        }

        $activeCounts = $approvals->activeKetiCountsByClass();

        return view('auth.application-status', [
            'user' => $user,
            'application' => $user->latestKetiApplication()->with('classGroup')->first(),
            'activeClassGroup' => $user->activeClassGroup(),
            'isApproved' => $user->isApprovedKeti(),
            'classGroups' => ClassGroup::groupedOptions(
                ClassGroup::query()->where('is_active', true)->orderedForSelection()->get(),
                fn (ClassGroup $class): string => $approvals->formatClassOptionLabel($class, $activeCounts[$class->id] ?? 0),
            ),
        ]);
    }

    public function resubmit(ResubmitApplicationRequest $request, KetiApplicationService $applications): RedirectResponse
    {
        $data = $request->validated();
        $applications->resubmit(
            $request->user(),
            ClassGroup::query()->findOrFail($data['class_group_id']),
            $data['name'] ?? null,
            $data['nim'] ?? null,
        );

        return redirect()->route('keti.application')
            ->with('toast', ['type' => 'success', 'title' => 'Pengajuan dikirim ulang', 'message' => 'Admin akan memeriksa pengajuanmu.']);
    }
}
