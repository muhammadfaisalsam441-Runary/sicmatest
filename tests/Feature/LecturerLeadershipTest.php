<?php

use App\Models\LeadershipTerm;
use App\Models\Lecturer;
use Carbon\CarbonImmutable;

test('dosen dianggap pimpinan hanya selama masa jabatan', function (string $date, bool $expected) {
    $lecturer = Lecturer::factory()->create();
    LeadershipTerm::factory()->for($lecturer)->create(['valid_from' => '2026-01-01', 'valid_to' => '2026-12-31']);

    expect($lecturer->isLeaderOn(CarbonImmutable::parse($date)))->toBe($expected);
})->with([
    'sebelum menjabat' => ['2025-12-31', false],
    'hari pertama' => ['2026-01-01', true],
    'hari terakhir' => ['2026-12-31', true],
    'setelah masa jabatan' => ['2027-01-01', false],
]);

test('masa jabatan tanpa tanggal akhir tetap berlaku', function () {
    $lecturer = Lecturer::factory()->create();
    LeadershipTerm::factory()->for($lecturer)->create(['valid_from' => '2026-01-01', 'valid_to' => null]);

    expect($lecturer->isLeaderOn(CarbonImmutable::parse('2030-06-01')))->toBeTrue()
        ->and(Lecturer::factory()->create()->isLeaderOn(CarbonImmutable::parse('2026-06-01')))->toBeFalse();
});

test('scope activeOn memilih masa jabatan yang berlaku', function () {
    $lecturer = Lecturer::factory()->create();
    $current = LeadershipTerm::factory()->for($lecturer)->create(['valid_from' => '2026-01-01', 'valid_to' => null]);
    LeadershipTerm::factory()->for($lecturer)->create(['valid_from' => '2020-01-01', 'valid_to' => '2024-12-31']);

    expect(LeadershipTerm::query()->activeOn(CarbonImmutable::parse('2026-11-04'))->pluck('id')->all())->toBe([$current->id]);
});
