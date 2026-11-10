<?php

namespace App\Http\Controllers\Auth;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangeEmailRequest;
use App\Http\Requests\Auth\VerifyEmailCodeRequest;
use App\Services\Account\EmailVerificationService;
use App\Services\Account\KetiApplicationService;
use App\Support\HomeRoute;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EmailVerificationController extends Controller
{
    public function __construct(private EmailVerificationService $verification) {}

    public function show(Request $request): View|RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect(HomeRoute::for($request->user()));
        }

        return view('auth.verify-email', ['email' => $request->user()->email]);
    }

    public function verify(VerifyEmailCodeRequest $request, KetiApplicationService $applications): RedirectResponse
    {
        try {
            $this->verification->verify($request->user(), $request->validated('code'));
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage())->with('error_code', $exception->errorCode);
        }

        $applications->announceToAdmins($request->user());

        return redirect(HomeRoute::for($request->user()))
            ->with('toast', ['type' => 'success', 'title' => 'Email terverifikasi', 'message' => 'Pengajuanmu diteruskan ke admin.']);
    }

    public function resend(Request $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect(HomeRoute::for($request->user()));
        }

        try {
            $this->verification->send($request->user());
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage())->with('error_code', $exception->errorCode);
        }

        return back()
            ->with('toast', ['type' => 'success', 'title' => 'Kode baru dikirim', 'message' => 'Periksa email pendaftaranmu.']);
    }

    public function editEmail(Request $request): View|RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect(HomeRoute::for($request->user()));
        }

        return view('auth.change-email', ['email' => $request->user()->email]);
    }

    public function updateEmail(ChangeEmailRequest $request): RedirectResponse
    {
        $this->verification->changeEmail($request->user(), $request->validated('email'));

        return redirect()->route('verification.notice')
            ->with('toast', ['type' => 'success', 'title' => 'Email pendaftaran diperbarui', 'message' => 'Kode verifikasi baru sudah dikirim.']);
    }
}
