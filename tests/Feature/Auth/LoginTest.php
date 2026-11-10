<?php

use App\Models\User\User;

test('halaman masuk tampil untuk tamu', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('Masuk ke SICMA')
        ->assertSee('Email atau NIM');
});

test('Keti yang disetujui bisa masuk dengan NIM atau email', function (string $field) {
    $keti = User::factory()->approvedKeti()->create(['nim' => '2502040001', 'email' => 'dimas@sicma.test']);

    $this->post(route('login.store'), ['identity' => $keti->{$field}, 'password' => 'password'])
        ->assertRedirect(route('keti.search'));

    $this->assertAuthenticatedAs($keti);
    expect($keti->fresh()->last_login_at)->not->toBeNull();
})->with(['nim', 'email']);

test('admin masuk dari halaman yang sama dan diarahkan ke panel admin', function () {
    $admin = User::factory()->admin()->create();

    $this->post(route('login.store'), ['identity' => $admin->email, 'password' => 'password'])
        ->assertRedirect(route('admin.dashboard'));
});

test('kredensial salah atau akun nonaktif mendapat pesan yang sama', function (bool $isActive, string $password) {
    $user = User::factory()->create(['is_active' => $isActive]);

    $this->from(route('login'))
        ->post(route('login.store'), ['identity' => $user->email, 'password' => $password])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['identity' => 'Email, NIM, atau kata sandi belum sesuai. Silakan coba lagi.']);

    $this->assertGuest();
})->with([
    'kata sandi salah' => [true, 'salah-sekali'],
    'akun nonaktif' => [false, 'password'],
]);

test('percobaan masuk dibatasi 5 kali per menit', function () {
    $user = User::factory()->create();

    foreach (range(1, 5) as $attempt) {
        $this->post(route('login.store'), ['identity' => $user->email, 'password' => 'salah']);
    }

    $this->post(route('login.store'), ['identity' => $user->email, 'password' => 'password'])
        ->assertTooManyRequests();
});

test('Keti yang belum disetujui diarahkan ke status pengajuan setelah masuk', function () {
    $keti = User::factory()->pendingKeti()->create();

    $this->post(route('login.store'), ['identity' => $keti->email, 'password' => 'password'])
        ->assertRedirect(route('keti.application'));
});

test('pengguna bisa keluar', function () {
    $this->actingAs(User::factory()->approvedKeti()->create())
        ->post(route('logout'))
        ->assertRedirect(route('home'));

    $this->assertGuest();
});
