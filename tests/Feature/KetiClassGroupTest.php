<?php

use App\Models\ClassGroup;
use App\Models\ClassRepresentative;
use App\Models\User;

test('Keti terhubung ke kelas aktif yang diwakilinya hari ini', function () {
    $keti = User::factory()->create();
    $classGroup = ClassGroup::factory()->create();
    ClassRepresentative::factory()->for($keti)->for($classGroup)->create(['valid_from' => now()->subMonth()]);

    expect($keti->activeClassGroup()?->is($classGroup))->toBeTrue();
});

test('Keti tidak punya kelas aktif bila masa perwakilan sudah berakhir atau kelas nonaktif', function (string $case) {
    $keti = User::factory()->create();

    match ($case) {
        'berakhir' => ClassRepresentative::factory()->for($keti)->create([
            'valid_from' => now()->subMonths(6),
            'valid_to' => now()->subDay(),
        ]),
        'kelas nonaktif' => ClassRepresentative::factory()->for($keti)->for(ClassGroup::factory()->inactive())->create(),
        'belum terhubung' => null,
    };

    expect($keti->activeClassGroup())->toBeNull();
})->with(['berakhir', 'kelas nonaktif', 'belum terhubung']);

test('inisial diambil dari dua kata pertama nama', function () {
    expect(User::factory()->make(['name' => 'Dimas Pratama Putra'])->initials())->toBe('DP');
});
