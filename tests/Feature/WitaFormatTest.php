<?php

use App\Support\Wita;
use Carbon\CarbonImmutable;

test('format waktu mengikuti copy desain', function () {
    $time = CarbonImmutable::parse('2026-10-02 14:15', 'Asia/Makassar');

    expect(Wita::clock($time))->toBe('14:15 WITA')
        ->and(Wita::time($time))->toBe('14.15 WITA')
        ->and(Wita::date($time))->toBe('2 Okt 2026')
        ->and(Wita::longDate($time))->toBe('2 Oktober 2026')
        ->and(Wita::stamp($time))->toBe('2 Okt 2026 • 14.15 WITA')
        ->and(Wita::dayName($time))->toBe('Jumat')
        ->and(Wita::range($time, $time->addMinutes(100)))->toBe('14:15–15:55 WITA');
});

test('waktu dari zona lain ditampilkan dalam WITA', function () {
    expect(Wita::clock(CarbonImmutable::parse('2026-10-02 06:15', 'UTC')))->toBe('14:15 WITA');
});

test('nilai kosong ditampilkan sebagai tanda pisah', function () {
    expect(Wita::clock(null))->toBe('—')
        ->and(Wita::minutes(null))->toBe('—')
        ->and(Wita::minutes(940))->toBe('15:40');
});

test('durasi ditulis dalam jam dan menit', function (int $minutes, string $expected) {
    expect(Wita::duration($minutes))->toBe($expected);
})->with([
    [285, '4 jam 45 menit'],
    [180, '3 jam'],
    [45, '45 menit'],
    [0, '0 menit'],
]);
