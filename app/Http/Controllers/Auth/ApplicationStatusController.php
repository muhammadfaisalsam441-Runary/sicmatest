<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ResubmitApplicationRequest;
use App\Models\Academic\ClassGroup;
use App\Services\Account\KetiApplicationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ApplicationStatusController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if ($user->isAdmin()) {
            return redirect()->route('admin.dashboard');
        }

        if (! $user->hasVerifiedEmail()) {
            return redirect()->route('verification.notice');
        }

        return view('auth.application-status', [
            'user' => $user,
            'application' => $user->latestKetiApplication()->with('classGroup')->first(),
            'activeClassGroup' => $user->activeClassGroup(),
            'isApproved' => $user->isApprovedKeti(),
            'classGroups' => ClassGroup::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'),
        ]);
    }

    public function resubmit(ResubmitApplicationRequest $request, KetiApplicationService $applications): RedirectResponse
    {
        $applications->resubmit($request->user(), ClassGroup::query()->findOrFail($request->validated('class_group_id')));

        return redirect()->route('keti.application')
            ->with('toast', ['type' => 'success', 'title' => 'Pengajuan dikirim ulang', 'message' => 'Admin akan memeriksa pengajuanmu.']);
    }
}
