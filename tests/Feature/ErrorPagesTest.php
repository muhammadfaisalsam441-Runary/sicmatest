<?php

use App\Models\User;

beforeEach(function () {
    $this->withoutVite();
});

test('halaman tidak ditemukan berbahasa Indonesia', function () {
    $this->get('/halaman-yang-tidak-ada')->assertNotFound()->assertSee('Halaman tidak ditemukan')->assertSee('Kembali ke beranda');
});

test('akses ditolak menampilkan halaman 403 bergaya SICMA', function () {
    $this->actingAs(User::factory()->approvedKeti()->create())
        ->get(route('admin.dashboard'))
        ->assertForbidden()
        ->assertSee('Akses ditolak');
});

test('halaman galat lain tersedia dalam bahasa Indonesia', function (int $status, string $title) {
    expect(view("errors.{$status}")->render())->toContain($title)->toContain('Kode '.$status);
})->with([
    [419, 'Sesi sudah kedaluwarsa'],
    [429, 'Terlalu banyak percobaan'],
    [500, 'Terjadi galat di server'],
    [503, 'Sedang pemeliharaan'],
]);
