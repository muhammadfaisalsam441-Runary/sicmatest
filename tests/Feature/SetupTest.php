<?php

test('aplikasi memakai zona waktu WITA dan bahasa Indonesia', function () {
    expect(config('app.name'))->toBe('SICMA')
        ->and(config('app.timezone'))->toBe('Asia/Makassar')
        ->and(config('app.locale'))->toBe('id')
        ->and(config('sicma.timezone'))->toBe(config('app.timezone'))
        ->and(now()->timezoneName)->toBe('Asia/Makassar');
});

test('konfigurasi kebijakan SICMA tersedia dengan nilai sementara', function () {
    expect(config('sicma.reservation.default_minutes'))->toBe(100)
        ->and(config('sicma.verification'))->toBe([
            'code_ttl_minutes' => 15,
            'max_attempts' => 5,
            'resend_cooldown_seconds' => 60,
        ])
        ->and(config('sicma.scheduler.time_limits'))->toBe([
            'cepat' => 15,
            'seimbang' => 60,
            'mendalam' => 180,
        ])
        ->and(config('sicma.scheduler.fuzzy_enabled'))->toBeFalse();
});

test('halaman awal tampil dengan identitas SICMA', function () {
    $this->withoutVite();

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('SICMA')
        ->assertSee('Temukan ruangan yang tepat untuk kelasmu.')
        ->assertSee('WITA');
});
