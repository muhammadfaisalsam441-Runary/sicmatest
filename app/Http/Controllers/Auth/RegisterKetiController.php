<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterKetiRequest;
use App\Models\ClassGroup;
use App\Services\KetiApplicationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class RegisterKetiController extends Controller
{
    public function create(): View
    {
        return view('auth.register', [
            'classGroups' => ClassGroup::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'),
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
