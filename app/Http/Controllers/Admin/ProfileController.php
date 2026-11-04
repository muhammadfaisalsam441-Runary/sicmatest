<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateProfileRequest;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Profil admin: informasi akun dan ubah kata sandi.
 */
class ProfileController extends Controller
{
    public function show(Request $request): View
    {
        return view('admin.profile', ['user' => $request->user()]);
    }

    public function update(UpdateProfileRequest $request, AuditLogger $audit): RedirectResponse
    {
        $user = $request->user();
        $before = $user->only(['name', 'email']);
        $user->fill($request->validated())->save();
        $audit->log('user.profile_updated', $user, $before, $user->only(['name', 'email']));

        return back()->with('toast', ['type' => 'success', 'title' => 'Perubahan profil tersimpan']);
    }

    public function password(UpdateProfileRequest $request, AuditLogger $audit): RedirectResponse
    {
        $user = $request->user();
        $user->forceFill(['password' => $request->validated('password')])->save();
        $audit->log('user.password_changed', $user);

        return back()->with('toast', ['type' => 'success', 'title' => 'Kata sandi diperbarui']);
    }
}
