<?php

use App\Models\User\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

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

test('percobaan atur ulang kata sandi dibatasi 5 kali per menit', function () {
    foreach (range(1, 5) as $attempt) {
        $this->post(route('password.update'), [
            'token' => 'token-salah',
            'email' => 'user@sicma.test',
            'password' => 'sandi-baru-panjang',
            'password_confirmation' => 'sandi-baru-panjang',
        ]);
    }

    $this->post(route('password.update'), [
        'token' => 'token-salah',
        'email' => 'user@sicma.test',
        'password' => 'sandi-baru-panjang',
        'password_confirmation' => 'sandi-baru-panjang',
    ])->assertTooManyRequests();
});

test('atur ulang kata sandi memutar remember_token dan menghapus sesi aktif pengguna tersebut', function () {
    Notification::fake();
    $user = User::factory()->create(['remember_token' => 'token-lama']);
    $otherUser = User::factory()->create();

    DB::table('sessions')->insert([
        [
            'id' => 'sess-user-1',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Browser A',
            'payload' => base64_encode('payload'),
            'last_activity' => now()->timestamp,
        ],
        [
            'id' => 'sess-user-2',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.2',
            'user_agent' => 'Browser B',
            'payload' => base64_encode('payload'),
            'last_activity' => now()->timestamp,
        ],
        [
            'id' => 'sess-other-1',
            'user_id' => $otherUser->id,
            'ip_address' => '127.0.0.3',
            'user_agent' => 'Browser C',
            'payload' => base64_encode('payload'),
            'last_activity' => now()->timestamp,
        ],
    ]);

    $this->post(route('password.email'), ['identity' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
        $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'sandi-baru-panjang',
            'password_confirmation' => 'sandi-baru-panjang',
        ])->assertRedirect(route('password.done'));

        return true;
    });

    expect($user->fresh()->remember_token)->not->toBe('token-lama')
        ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0)
        ->and(DB::table('sessions')->where('user_id', $otherUser->id)->count())->toBe(1);
});

test('akun nonaktif tidak dapat mengatur ulang kata sandi meskipun memiliki token', function () {
    $user = User::factory()->create(['is_active' => false]);
    $token = Password::createToken($user);

    $this->from(route('password.reset', ['token' => $token, 'email' => $user->email]))
        ->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'sandi-baru-panjang',
            'password_confirmation' => 'sandi-baru-panjang',
        ])
        ->assertRedirect(route('password.reset', ['token' => $token, 'email' => $user->email]))
        ->assertSessionHasErrors('password');

    expect(Hash::check('sandi-baru-panjang', $user->fresh()->password))->toBeFalse();
});
