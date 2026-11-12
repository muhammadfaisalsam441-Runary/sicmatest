<?php

namespace App\Http\Controllers\Auth;

use App\Enums\KetiStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterKetiRequest;
use App\Models\Academic\ClassGroup;
use App\Models\User\User;
use App\Services\Account\KetiApplicationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class RegisterKetiController extends Controller
{
    public function create(): View
    {
        $takenIds = User::query()
            ->whereNotNull('class_group_id')
            ->where('role', UserRole::Keti)
            ->where('keti_status', KetiStatus::Approved)
            ->pluck('class_group_id')
            ->all();

        return view('auth.register', [
            'classGroups' => ClassGroup::query()->where('is_active', true)->orderBy('name')->get()
                ->mapWithKeys(fn (ClassGroup $class): array => [
                    $class->id => in_array($class->id, $takenIds, true) ? $class->name.' (Sudah ada Keti)' : $class->name,
                ]),
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
