<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterKetiRequest;
use App\Models\Academic\ClassGroup;
use App\Services\Account\KetiApplicationService;
use App\Services\Account\KetiApprovalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class RegisterKetiController extends Controller
{
    public function create(KetiApprovalService $approvals): View
    {
        $activeCounts = $approvals->activeKetiCountsByClass();

        $classes = ClassGroup::query()
            ->where('is_active', true)
            ->orderedForSelection()
            ->get();

        return view('auth.register', [
            'classGroups' => ClassGroup::groupedOptions(
                $classes,
                fn (ClassGroup $class): string => $approvals->formatClassOptionLabel($class, $activeCounts[$class->id] ?? 0),
            ),
        ]);
    }

    public function store(RegisterKetiRequest $request, KetiApplicationService $applications): RedirectResponse
    {
        $user = $applications->register($request->validated());

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('verification.notice');
    }
}
