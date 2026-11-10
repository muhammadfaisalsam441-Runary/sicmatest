<?php

use App\Models\User\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

test('tautan atur ulang dikirim ke email akun meskipun memakai NIM', function () {
    Notification::fake();
    $user = User::factory()->create(['nim' => '2502040001']);

    $this->post(route('password.email'), ['identity' => '2502040001'])
        ->assertRedirect(route('password.sent'));

    Notification::assertSentTo($user, ResetPassword::class);
    $this->get(route('password.sent'))->assertSee('Periksa emailmu');
});

test('identitas yang tidak terdaftar tetap melihat "Periksa emailmu"', function () {
    Notification::fake();

    $this->post(route('password.email'), ['identity' => 'tidak-ada@sicma.test'])
        ->assertRedirect(route('password.sent'));

    Notification::assertNothingSent();
});

test('kata sandi baru disimpan lewat tautan atur ulang', function () {
    Notification::fake();
    $user = User::factory()->create();
    $this->post(route('password.email'), ['identity' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
        $this->get(route('password.reset', ['token' => $notification->token, 'email' => $user->email]))
            ->assertSee('Buat kata sandi baru');

        $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'sandi-baru-panjang',
            'password_confirmation' => 'sandi-baru-panjang',
        ])->assertRedirect(route('password.done'));

        return true;
    });

    expect(Hash::check('sandi-baru-panjang', $user->fresh()->password))->toBeTrue();
});

test('konfirmasi berbeda menampilkan "Kata sandi belum cocok"', function () {
    $this->from(route('password.reset', ['token' => 'abc']))
        ->post(route('password.update'), [
            'token' => 'abc',
            'email' => 'siapa@sicma.test',
            'password' => 'sandi-baru-panjang',
            'password_confirmation' => 'berbeda-sekali',
        ])
        ->assertSessionHasErrors(['password' => 'Kata sandi baru dan konfirmasinya belum sama.']);

    $this->withViewErrors(['password' => 'Kata sandi baru dan konfirmasinya belum sama.'])
        ->view('auth.reset-password', ['token' => 'abc', 'email' => 'siapa@sicma.test'])
        ->assertSee('Kata sandi belum cocok');
});
