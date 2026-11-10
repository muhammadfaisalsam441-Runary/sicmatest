<?php

namespace App\Services\Account;

use App\Exceptions\DomainException;
use App\Mail\VerificationCodeMail;
use App\Models\User\EmailVerificationCode;
use App\Models\User\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

/**
 * Kode verifikasi email 6 digit: disimpan sebagai hash, kedaluwarsa, dibatasi percobaan dan jeda kirim ulang.
 */
class EmailVerificationService
{
    /**
     * Kirim kode baru dan hapus kode lama. Mengembalikan kode asli (dipakai hanya untuk email).
     *
     * @throws DomainException RESEND_TOO_SOON bila jeda kirim ulang belum lewat.
     */
    public function send(User $user, bool $ignoreCooldown = false): string
    {
        $latest = $this->latestCode($user);
        $cooldown = (int) config('sicma.verification.resend_cooldown_seconds');

        if (! $ignoreCooldown && $latest !== null && $latest->sent_at->diffInSeconds(now()) < $cooldown) {
            $wait = $cooldown - (int) $latest->sent_at->diffInSeconds(now());

            throw new DomainException('RESEND_TOO_SOON', "Tunggu {$wait} detik sebelum mengirim ulang kode.", ['retry_after' => $wait]);
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $ttl = (int) config('sicma.verification.code_ttl_minutes');

        DB::transaction(function () use ($user, $code, $ttl): void {
            $user->verificationCodes()->delete();
            $user->verificationCodes()->create([
                'code_hash' => Hash::make($code),
                'expires_at' => now()->addMinutes($ttl),
                'attempts' => 0,
                'sent_at' => now(),
            ]);
        });

        Mail::to($user)->send(new VerificationCodeMail($code, $ttl));

        if (app()->isLocal() && app()->bound('session.store')) {
            // Mode pengembangan: tampilkan kode di halaman verifikasi karena email hanya ditulis ke log.
            session()->flash('dev_verification_code', $code);
        }

        return $code;
    }

    /**
     * Cocokkan kode. Berhasil → email terverifikasi dan kode dihapus.
     *
     * @throws DomainException VERIFICATION_CODE_INVALID | VERIFICATION_CODE_EXPIRED | VERIFICATION_LOCKED
     */
    public function verify(User $user, string $code): void
    {
        if ($user->hasVerifiedEmail()) {
            return;
        }

        $record = $this->latestCode($user);
        $maxAttempts = (int) config('sicma.verification.max_attempts');

        if ($record === null) {
            throw new DomainException('VERIFICATION_CODE_INVALID', 'Kode belum sesuai. Kirim ulang kode verifikasi.');
        }

        if ($record->attempts >= $maxAttempts) {
            throw new DomainException('VERIFICATION_LOCKED', 'Kode salah terlalu sering. Kirim ulang kode verifikasi.');
        }

        if ($record->expires_at->isPast()) {
            throw new DomainException('VERIFICATION_CODE_EXPIRED', 'Kode sudah kedaluwarsa. Kirim ulang kode verifikasi.');
        }

        if (! Hash::check($code, $record->code_hash)) {
            $record->increment('attempts');

            throw $record->attempts >= $maxAttempts
                ? new DomainException('VERIFICATION_LOCKED', 'Kode salah terlalu sering. Kirim ulang kode verifikasi.')
                : new DomainException('VERIFICATION_CODE_INVALID', 'Masukkan kode terbaru atau kirim ulang kode verifikasi.', [
                    'remaining_attempts' => $maxAttempts - $record->attempts,
                ]);
        }

        DB::transaction(function () use ($user): void {
            $user->markEmailAsVerified();
            $user->verificationCodes()->delete();
        });
    }

    /**
     * Ganti email pendaftaran yang belum terverifikasi lalu kirim kode baru.
     */
    public function changeEmail(User $user, string $email): void
    {
        $user->forceFill(['email' => $email, 'email_verified_at' => null])->save();

        $this->send($user, ignoreCooldown: true);
    }

    private function latestCode(User $user): ?EmailVerificationCode
    {
        return $user->verificationCodes()->latest('id')->first();
    }
}
