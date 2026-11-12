<?php

use App\Models\Lecturer\LeadershipTerm;
use App\Models\Lecturer\Lecturer;
use App\Services\Academic\LecturerService;
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

test('mengubah jabatan menonaktifkan jabatan lama dan mengosongkan jabatan tidak menghapus riwayat', function () {
    $service = app(LecturerService::class);
    $lecturer = Lecturer::factory()->create();

    // 1. Berikan jabatan awal
    $service->save($lecturer, [
        'name' => $lecturer->name,
        'profile_status' => 'verified',
        'leadership_position' => 'Sekretaris Jurusan',
        'leadership_from' => '2026-01-01',
    ]);

    $term1 = $lecturer->leadershipTerms()->sole();
    expect($term1->position)->toBe('Sekretaris Jurusan')
        ->and($term1->valid_to)->toBeNull();

    // 2. Ganti jabatan ke Ketua Jurusan
    $service->save($lecturer, [
        'name' => $lecturer->name,
        'profile_status' => 'verified',
        'leadership_position' => 'Ketua Jurusan',
        'leadership_from' => '2026-11-01',
    ]);

    expect($lecturer->leadershipTerms()->count())->toBe(2);
    $term1->refresh();
    expect($term1->valid_to)->not->toBeNull(); // Jabatan lama dinonaktifkan
    $term2 = $lecturer->leadershipTerms()->where('position', 'Ketua Jurusan')->first();
    expect($term2->valid_to)->toBeNull(); // Jabatan baru aktif

    // 3. Kosongkan jabatan
    $service->save($lecturer, [
        'name' => $lecturer->name,
        'profile_status' => 'verified',
        'leadership_position' => '',
    ]);

    // Riwayat tetap 2 record, tidak dihapus
    expect($lecturer->leadershipTerms()->count())->toBe(2);
    $term2->refresh();
    expect($term2->valid_to)->not->toBeNull(); // Jabatan kedua juga dinonaktifkan
});
