<?php

use App\Models\Academic\ClassGroup;
use App\Models\User\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00'));
    officialVersion();
});

test('session error pada layout admin dirender sebagai toast galat di kanan atas, bukan alert inline', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)
        ->withSession(['error' => 'Mata kuliah masih ditawarkan pada semester.'])
        ->get(route('admin.dashboard'));

    $response->assertOk();

    $html = $response->getContent();
    expect($html)
        ->toContain('data-toast-host')
        ->toContain('popover="manual"')
        ->toContain('Tindakan belum berhasil')
        ->toContain('Mata kuliah masih ditawarkan pada semester.');

    $main = str($html)->between('<main id="main-content"', '</main>')->toString();
    expect($main)->not->toContain('Mata kuliah masih ditawarkan pada semester.');
});

test('session error pada layout keti dirender sebagai toast galat, bukan alert inline', function () {
    $classGroup = ClassGroup::factory()->create(['name' => 'Tekom C']);
    $keti = User::factory()->approvedKeti($classGroup)->create();

    $response = $this->actingAs($keti)
        ->withSession(['error' => 'Aksi tidak dapat diproses saat ini.'])
        ->get(route('keti.search'));

    $response->assertOk();

    $html = $response->getContent();
    expect($html)
        ->toContain('data-toast-host')
        ->toContain('Tindakan belum berhasil')
        ->toContain('Aksi tidak dapat diproses saat ini.');

    $main = str($html)->between('<main id="main-content"', '</main>')->toString();
    expect($main)->not->toContain('Aksi tidak dapat diproses saat ini.');
});

test('halaman verifikasi email tetap menampilkan error kode inline tanpa diduplikasi ke toast', function () {
    $keti = User::factory()->unverified()->pendingKeti()->create();

    $response = $this->actingAs($keti)
        ->withSession([
            'error_code' => 'RESEND_TOO_SOON',
            'error' => 'Tunggu sebentar sebelum mengirim ulang kode.',
        ])
        ->get(route('verification.notice'));

    $response->assertOk();

    $html = $response->getContent();
    $main = str($html)->between('<main', '</main>')->toString();
    expect($main)->toContain('Tunggu sebentar sebelum mengirim ulang kode.');
    expect($html)->toContain('sicmaToast(null)');
});
