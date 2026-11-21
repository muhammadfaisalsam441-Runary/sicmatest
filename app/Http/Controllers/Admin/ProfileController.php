<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateProfileRequest;
use App\Models\User\User;
use App\Services\Account\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
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
        $user->forceFill([
            'password' => $request->validated('password'),
            'remember_token' => Str::random(60),
        ])->save();

        $this->invalidateOtherSessions($user, $request->session()->getId());
        $audit->log('user.password_changed', $user);

        return back()->with('toast', ['type' => 'success', 'title' => 'Kata sandi diperbarui']);
    }

    /**
     * Hapus sesi database lain milik admin tanpa memutus sesi yang sedang aktif.
     */
    private function invalidateOtherSessions(User $user, string $currentSessionId): void
    {
        $table = config('session.table', 'sessions') ?: 'sessions';
        $connection = config('session.connection');

        if (Schema::connection($connection)->hasTable($table)) {
            DB::connection($connection)->table($table)
                ->where('user_id', $user->id)
                ->where('id', '!=', $currentSessionId)
                ->delete();
        }
    }
}
