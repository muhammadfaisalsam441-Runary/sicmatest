<?php

use App\Mail\VerificationCodeMail;
use App\Models\AppNotification;
use App\Models\User;
use App\Services\EmailVerificationService;
use Illuminate\Support\Facades\Mail;

function sendCodeTo(User $user): string
{
    $code = null;
    Mail::fake();
    app(EmailVerificationService::class)->send($user, ignoreCooldown: true);
    Mail::assertSent(VerificationCodeMail::class, function (VerificationCodeMail $mail) use (&$code) {
        $code = $mail->code;

        return true;
    });

    return $code;
}

test('kode yang benar memverifikasi email dan memberi tahu admin', function () {
    $admin = User::factory()->admin()->create();
    $keti = User::factory()->unverified()->pendingKeti()->create();
    $code = sendCodeTo($keti);

    $this->actingAs($keti)
        ->post(route('verification.verify'), ['code' => $code])
        ->assertRedirect(route('keti.application'));

    expect($keti->fresh()->hasVerifiedEmail())->toBeTrue()
        ->and($keti->verificationCodes()->count())->toBe(0)
        ->and(AppNotification::query()->whereBelongsTo($admin)->where('type', 'keti_application.submitted')->exists())->toBeTrue();
});

test('kode salah menampilkan "Kode belum sesuai"', function () {
    $keti = User::factory()->unverified()->pendingKeti()->create();
    $code = sendCodeTo($keti);
    $wrong = $code === '000000' ? '111111' : '000000';

    $this->actingAs($keti)
        ->from(route('verification.notice'))
        ->post(route('verification.verify'), ['code' => $wrong])
        ->assertRedirect(route('verification.notice'))
        ->assertSessionHas('error_code', 'VERIFICATION_CODE_INVALID');

    $this->get(route('verification.notice'))->assertSee('Kode belum sesuai');
    expect($keti->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('kode dikunci setelah 5 kali salah dan harus dikirim ulang', function () {
    $keti = User::factory()->unverified()->pendingKeti()->create();
    $code = sendCodeTo($keti);
    $wrong = $code === '000000' ? '111111' : '000000';

    foreach (range(1, 5) as $attempt) {
        $this->actingAs($keti)->post(route('verification.verify'), ['code' => $wrong]);
    }

    $this->actingAs($keti)
        ->post(route('verification.verify'), ['code' => $code])
        ->assertSessionHas('error_code', 'VERIFICATION_LOCKED');
    expect($keti->fresh()->hasVerifiedEmail())->toBeFalse();

    $this->travel(61)->seconds();
    $newCode = sendCodeTo($keti);
    $this->actingAs($keti)->post(route('verification.verify'), ['code' => $newCode]);

    expect($keti->fresh()->hasVerifiedEmail())->toBeTrue();
});

test('kode kedaluwarsa setelah 15 menit', function () {
    $keti = User::factory()->unverified()->pendingKeti()->create();
    $code = sendCodeTo($keti);

    $this->travel(16)->minutes();

    $this->actingAs($keti)
        ->post(route('verification.verify'), ['code' => $code])
        ->assertSessionHas('error_code', 'VERIFICATION_CODE_EXPIRED');
});

test('kirim ulang kode harus menunggu 60 detik', function () {
    $keti = User::factory()->unverified()->pendingKeti()->create();
    sendCodeTo($keti);

    $this->actingAs($keti)
        ->post(route('verification.send'))
        ->assertSessionHas('error_code', 'RESEND_TOO_SOON');

    $this->travel(61)->seconds();

    $this->actingAs($keti)
        ->post(route('verification.send'))
        ->assertSessionMissing('error_code');
});

test('email pendaftaran bisa diubah dan kode baru dikirim', function () {
    Mail::fake();
    $keti = User::factory()->unverified()->pendingKeti()->create();

    $this->actingAs($keti)
        ->put(route('verification.email.update'), ['email' => 'baru@sicma.test'])
        ->assertRedirect(route('verification.notice'));

    expect($keti->fresh()->email)->toBe('baru@sicma.test');
    Mail::assertSent(VerificationCodeMail::class, fn (VerificationCodeMail $mail) => $mail->hasTo('baru@sicma.test'));
});

test('Keti yang emailnya belum terverifikasi diarahkan ke halaman verifikasi', function () {
    $this->actingAs(User::factory()->unverified()->approvedKeti()->create())
        ->get(route('keti.search'))
        ->assertRedirect(route('verification.notice'));
});
