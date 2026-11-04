<?php

use App\Models\Room;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/*
 * Regresi: Laravel 13 tidak membongkar objek PHP dari cache (config cache.serializable_classes = false).
 * Data yang disimpan AvailabilityCache harus berupa nilai skalar/array saja agar kunjungan kedua
 * (yang membaca dari cache database/file) tidak gagal dengan __PHP_Incomplete_Class.
 */
beforeEach(function () {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00'));
    officialVersion();

    config([
        'cache.stores.serialized_test' => ['driver' => 'file', 'path' => storage_path('framework/testing/cache-serialized')],
        'cache.default' => 'serialized_test',
        'cache.serializable_classes' => false,
        'sicma.public_portal.cache_seconds' => 60,
    ]);
    Cache::store('serialized_test')->flush();
});

afterEach(function () {
    Cache::store('serialized_test')->flush();
});

test('beranda tetap tampil saat dibaca ulang dari cache yang diserialisasi', function () {
    Room::factory()->count(2)->create();

    $this->get(route('home'))->assertOk()->assertSee('WITA');
    $this->get(route('home'))->assertOk()->assertSee('WITA');
});

test('daftar ruangan tetap tampil saat dibaca ulang dari cache yang diserialisasi', function () {
    Room::factory()->count(2)->create();

    $this->get(route('public.rooms'))->assertOk()->assertSee('WITA');
    $this->get(route('public.rooms'))->assertOk()->assertSee('WITA');
});
