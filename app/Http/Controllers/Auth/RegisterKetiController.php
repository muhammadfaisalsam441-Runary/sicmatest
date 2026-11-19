<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterKetiRequest;
use App\Models\Academic\ClassGroup;
use App\Models\User\ClassRepresentative;
use App\Services\Account\KetiApplicationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class RegisterKetiController extends Controller
{
    public function create(): View
    {
        $takenIds = ClassRepresentative::query()
            ->activeOn(now())
            ->pluck('class_group_id')
            ->all();

        $classes = ClassGroup::query()
            ->where('is_active', true)
            ->orderedForSelection()
            ->get();

        return view('auth.register', [
            'classGroups' => ClassGroup::groupedOptions(
                $classes,
                fn (ClassGroup $class): string => in_array($class->id, $takenIds, true) ? $class->name.' (Sudah ada Keti)' : $class->name,
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
