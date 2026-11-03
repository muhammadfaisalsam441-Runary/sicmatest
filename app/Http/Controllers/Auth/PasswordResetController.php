<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PasswordResetController extends Controller
{
    public function request(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * Kirim tautan atur ulang ke email akun (identitas boleh email atau NIM).
     * Selalu menampilkan "Periksa emailmu" agar keberadaan akun tidak terungkap.
     */
    public function email(ForgotPasswordRequest $request): RedirectResponse
    {
        $identity = trim($request->validated('identity'));
        $user = User::query()
            ->where(str_contains($identity, '@') ? 'email' : 'nim', str_contains($identity, '@') ? strtolower($identity) : $identity)
            ->where('is_active', true)
            ->first();

        $devResetUrl = null;

        if ($user !== null) {
            Password::sendResetLink(['email' => $user->email], function (User $user, string $token) use (&$devResetUrl): void {
                $user->sendPasswordResetNotification($token);
                $devResetUrl = route('password.reset', ['token' => $token, 'email' => $user->email]);
            });
        }

        return redirect()->route('password.sent')
            ->with('reset_identity', $identity)
            ->with('dev_reset_url', app()->isLocal() ? $devResetUrl : null);
    }

    public function sent(): View|RedirectResponse
    {
        if (! session()->has('reset_identity')) {
            return redirect()->route('password.request');
        }

        session()->keep(['reset_identity', 'dev_reset_url']);

        return view('auth.forgot-password-sent', [
            'identity' => session('reset_identity'),
            'devResetUrl' => session('dev_reset_url'),
        ]);
    }

    public function edit(string $token): View
    {
        return view('auth.reset-password', ['token' => $token, 'email' => request()->string('email')->toString()]);
    }

    public function update(ResetPasswordRequest $request): RedirectResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password): void {
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            return back()->withInput($request->only('email'))
                ->withErrors(['password' => 'Tautan atur ulang tidak valid atau sudah kedaluwarsa. Minta tautan baru.']);
        }

        return redirect()->route('password.done');
    }

    public function done(): View
    {
        return view('auth.password-updated');
    }
}
