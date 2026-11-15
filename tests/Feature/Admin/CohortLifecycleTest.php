<?php

use App\Models\Academic\ClassGroup;
use App\Models\Academic\CourseOffering;
use App\Models\Academic\Period;
use App\Models\User\AuditLog;
use App\Models\User\ClassRepresentative;
use App\Models\User\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00:00'));
    $this->admin = User::factory()->admin()->create(['name' => 'Admin JTIK']);
});

/*
|--------------------------------------------------------------------------
| 1. Bulk Archiving an Entire Cohort
|--------------------------------------------------------------------------
*/

test('admin dapat melihat preview pengarsipan angkatan', function () {
    $period = Period::factory()->create(['is_active' => true]);

    $class2022A = ClassGroup::factory()->create([
        'name' => 'Tekom A 2022',
        'code' => 'TEKOM-A-2022',
        'cohort_year' => 2022,
        'study_program' => 'Teknik Komputer',
        'student_count' => 30,
        'is_active' => true,
    ]);

    $class2022B = ClassGroup::factory()->create([
        'name' => 'Tekom B 2022',
        'code' => 'TEKOM-B-2022',
        'cohort_year' => 2022,
        'study_program' => 'Teknik Komputer',
        'student_count' => 32,
        'is_active' => true,
    ]);

    $ketiUser = User::factory()->create(['name' => 'Keti Angkatan 2022', 'is_active' => true]);
    ClassRepresentative::factory()->create([
        'user_id' => $ketiUser->id,
        'class_group_id' => $class2022A->id,
        'valid_from' => '2026-01-01',
        'valid_to' => null,
    ]);

    CourseOffering::factory()->create([
        'period_id' => $period->id,
        'class_group_id' => $class2022A->id,
    ]);

    $response = $this->actingAs($this->admin)
        ->getJson(route('admin.academic.cohorts.preview-archive', ['cohort_year' => 2022]))
        ->assertOk();

    $data = $response->json();
    expect($data['cohort_year'])->toBe(2022)
        ->and($data['active_classes_count'])->toBe(2)
        ->and($data['active_ketis_count'])->toBe(1)
        ->and($data['active_offerings_count'])->toBe(1)
        ->and($data['classes'])->toHaveCount(2)
        ->and($data['active_ketis'])->toHaveCount(1)
        ->and($data['active_ketis'][0]['name'])->toBe('Keti Angkatan 2022');
});

test('admin dapat meluluskan dan mengarsipkan angkatan secara massal dengan penonaktifan keti', function () {
    $class1 = ClassGroup::factory()->create([
        'name' => 'Tekom A 2022',
        'code' => 'TEKOM-A-2022',
        'cohort_year' => 2022,
        'is_active' => true,
    ]);
    $class2 = ClassGroup::factory()->create([
        'name' => 'Tekom B 2022',
        'code' => 'TEKOM-B-2022',
        'cohort_year' => 2022,
        'is_active' => true,
    ]);

    // Kelas angkatan lain yang tidak boleh terpengaruh
    $otherClass = ClassGroup::factory()->create([
        'name' => 'Tekom A 2023',
        'code' => 'TEKOM-A-2023',
        'cohort_year' => 2023,
        'is_active' => true,
    ]);

    $ketiUser = User::factory()->create(['name' => 'Keti 2022', 'is_active' => true]);
    $rep = ClassRepresentative::factory()->create([
        'user_id' => $ketiUser->id,
        'class_group_id' => $class1->id,
        'valid_from' => '2026-01-01',
        'valid_to' => null,
    ]);

    $otherKeti = User::factory()->create(['name' => 'Keti 2023', 'is_active' => true]);
    $otherRep = ClassRepresentative::factory()->create([
        'user_id' => $otherKeti->id,
        'class_group_id' => $otherClass->id,
        'valid_from' => '2026-01-01',
        'valid_to' => null,
    ]);

    $response = $this->actingAs($this->admin)
        ->post(route('admin.academic.cohorts.graduate'), [
            'cohort_year' => 2022,
            'reason' => 'Yudisium Periode Genap 2025/2026',
            'deactivate_ketis' => true,
        ]);

    $response->assertRedirect(route('admin.academic.index', ['tab' => 'kelas']))
        ->assertSessionHas('toast', fn ($toast) => $toast['type'] === 'success' && str_contains($toast['message'], '2 kelas diarsipkan'));

    // Verifikasi kelas diarsipkan
    expect($class1->fresh()->is_active)->toBeFalse()
        ->and($class2->fresh()->is_active)->toBeFalse()
        ->and($otherClass->fresh()->is_active)->toBeTrue();

    // Verifikasi keti dinonaktifkan
    $rep->refresh();
    expect($rep->valid_to?->toDateString())->toBe('2026-11-03') // yesterday relative to 2026-11-04
        ->and($ketiUser->fresh()->is_active)->toBeFalse();

    // Verifikasi keti lain tidak terpengaruh
    $otherRep->refresh();
    expect($otherRep->valid_to)->toBeNull()
        ->and($otherKeti->fresh()->is_active)->toBeTrue();

    // Verifikasi audit logs
    $cohortAudit = AuditLog::query()
        ->where('action', 'cohort.graduated')
        ->where('auditable_id', $class1->id)
        ->first();

    expect($cohortAudit)->not->toBeNull()
        ->and($cohortAudit->user_id)->toBe($this->admin->id)
        ->and($cohortAudit->reason)->toBe('Yudisium Periode Genap 2025/2026')
        ->and($cohortAudit->after['archived_classes_count'])->toBe(2)
        ->and($cohortAudit->after['deactivated_ketis_count'])->toBe(1);

    $ketiAudit = AuditLog::query()
        ->where('action', 'keti.deactivated')
        ->where('auditable_id', $ketiUser->id)
        ->first();

    expect($ketiAudit)->not->toBeNull()
        ->and($ketiAudit->reason)->toBe('Yudisium Periode Genap 2025/2026');
});

test('meluluskan angkatan dengan deactivate_ketis false hanya menonaktifkan masa jabatan keti tanpa menonaktifkan akun user', function () {
    $class = ClassGroup::factory()->create([
        'cohort_year' => 2021,
        'is_active' => true,
    ]);

    $ketiUser = User::factory()->create(['is_active' => true]);
    $rep = ClassRepresentative::factory()->create([
        'user_id' => $ketiUser->id,
        'class_group_id' => $class->id,
        'valid_from' => '2026-01-01',
        'valid_to' => null,
    ]);

    $this->actingAs($this->admin)
        ->postJson(route('admin.academic.cohorts.graduate'), [
            'cohort_year' => 2021,
            'deactivate_ketis' => false,
        ])
        ->assertOk()
        ->assertJsonPath('result.deactivated_ketis_count', 0);

    expect($class->fresh()->is_active)->toBeFalse()
        ->and($rep->fresh()->valid_to?->toDateString())->toBe('2026-11-03')
        ->and($ketiUser->fresh()->is_active)->toBeTrue();

    expect(AuditLog::query()->where('action', 'keti.deactivated')->exists())->toBeFalse();
    expect(AuditLog::query()->where('action', 'cohort.graduated')->exists())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| 2. Generating New Cohort Classes From Template
|--------------------------------------------------------------------------
*/

test('admin dapat melihat preview pembukaan angkatan baru dari templat', function () {
    ClassGroup::factory()->create([
        'name' => 'Tekom A 2025',
        'code' => 'TEKOM-A-2025',
        'cohort_year' => 2025,
        'study_program' => 'Teknik Komputer',
        'student_count' => 35,
    ]);
    ClassGroup::factory()->create([
        'name' => 'Tekom B 2025',
        'code' => 'TEKOM-B-2025',
        'cohort_year' => 2025,
        'study_program' => 'Teknik Komputer',
        'student_count' => 40,
    ]);

    // Target class yang sudah ada sebagian
    ClassGroup::factory()->create([
        'name' => 'Tekom A 2026',
        'code' => 'TEKOM-A-2026',
        'cohort_year' => 2026,
        'study_program' => 'Teknik Komputer',
    ]);

    $response = $this->actingAs($this->admin)
        ->getJson(route('admin.academic.cohorts.preview-generate', [
            'source_cohort_year' => 2025,
            'target_cohort_year' => 2026,
            'copy_student_count' => true,
        ]))
        ->assertOk();

    $classes = $response->json('classes');
    expect($classes)->toHaveCount(2);

    $classA = collect($classes)->firstWhere('target_name', 'Tekom A 2026');
    $classB = collect($classes)->firstWhere('target_name', 'Tekom B 2026');

    expect($classA['already_exists'])->toBeTrue()
        ->and($classB['already_exists'])->toBeFalse()
        ->and($classB['target_code'])->toBe('TEKOM-B-2026')
        ->and($classB['student_count'])->toBe(40);
});

test('admin dapat meng-generate kelas angkatan baru dari templat dengan benar', function () {
    ClassGroup::factory()->create([
        'name' => 'Tekom A 2024',
        'code' => 'TEKOM-A-2024',
        'cohort_year' => 2024,
        'study_program' => 'Teknik Komputer',
        'student_count' => 36,
        'is_active' => true,
    ]);

    ClassGroup::factory()->create([
        'name' => 'Tekom B 2024',
        'code' => 'TEKOM-B-2024',
        'cohort_year' => 2024,
        'study_program' => 'Teknik Komputer',
        'student_count' => 38,
        'is_active' => true,
    ]);

    $response = $this->actingAs($this->admin)
        ->post(route('admin.academic.cohorts.generate'), [
            'source_cohort_year' => 2024,
            'target_cohort_year' => 2025,
            'copy_student_count' => true,
        ]);

    $response->assertRedirect(route('admin.academic.index', ['tab' => 'kelas']))
        ->assertSessionHas('toast', fn ($toast) => $toast['type'] === 'success' && str_contains($toast['message'], 'Berhasil membuat 2 kelas baru'));

    $newClasses = ClassGroup::query()->where('cohort_year', 2025)->get();
    expect($newClasses)->toHaveCount(2);

    $newA = $newClasses->firstWhere('name', 'Tekom A 2025');
    $newB = $newClasses->firstWhere('name', 'Tekom B 2025');

    expect($newA)->not->toBeNull()
        ->and($newA->code)->toBe('TEKOM-A-2025')
        ->and($newA->study_program)->toBe('Teknik Komputer')
        ->and($newA->student_count)->toBe(36)
        ->and($newA->is_active)->toBeTrue()
        ->and($newB)->not->toBeNull()
        ->and($newB->code)->toBe('TEKOM-B-2025')
        ->and($newB->student_count)->toBe(38)
        ->and($newB->is_active)->toBeTrue();

    // Verifikasi audit log
    $audit = AuditLog::query()
        ->where('action', 'cohort.generated')
        ->where('auditable_id', $newA->id)
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->after['source_cohort_year'])->toBe(2024)
        ->and($audit->after['target_cohort_year'])->toBe(2025)
        ->and($audit->after['created_count'])->toBe(2);
});

test('generasi angkatan baru mencegah duplikasi jika kelas sudah ada di angkatan target', function () {
    ClassGroup::factory()->create([
        'name' => 'Tekom A 2024',
        'code' => 'TEKOM-A-2024',
        'cohort_year' => 2024,
        'study_program' => 'Teknik Komputer',
    ]);
    ClassGroup::factory()->create([
        'name' => 'Tekom B 2024',
        'code' => 'TEKOM-B-2024',
        'cohort_year' => 2024,
        'study_program' => 'Teknik Komputer',
    ]);

    // Tekom A 2025 sudah ada sebelumnya
    ClassGroup::factory()->create([
        'name' => 'Tekom A 2025',
        'code' => 'TEKOM-A-2025',
        'cohort_year' => 2025,
        'study_program' => 'Teknik Komputer',
    ]);

    $this->actingAs($this->admin)
        ->postJson(route('admin.academic.cohorts.generate'), [
            'source_cohort_year' => 2024,
            'target_cohort_year' => 2025,
        ])
        ->assertOk()
        ->assertJsonPath('result.created_count', 1);

    expect(ClassGroup::query()->where('cohort_year', 2025)->count())->toBe(2);
});

test('generasi angkatan baru dengan kustomisasi daftar kelas berhasil', function () {
    ClassGroup::factory()->create([
        'cohort_year' => 2023,
    ]);

    $customClasses = [
        [
            'name' => 'PTI A 2025',
            'study_program' => 'Pendidikan Teknologi Informasi',
            'student_count' => 30,
        ],
        [
            'name' => 'PTI B 2025',
            'study_program' => 'Pendidikan Teknologi Informasi',
            'student_count' => 32,
        ],
    ];

    $this->actingAs($this->admin)
        ->postJson(route('admin.academic.cohorts.generate'), [
            'source_cohort_year' => 2023,
            'target_cohort_year' => 2025,
            'classes' => $customClasses,
        ])
        ->assertOk()
        ->assertJsonPath('result.created_count', 2);

    $ptiA = ClassGroup::query()->where('cohort_year', 2025)->where('name', 'PTI A 2025')->first();
    expect($ptiA)->not->toBeNull()
        ->and($ptiA->code)->toBe('PTI-A-2025')
        ->and($ptiA->study_program)->toBe('Pendidikan Teknologi Informasi')
        ->and($ptiA->student_count)->toBe(30)
        ->and($ptiA->is_active)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| 3. Authorization and Validation Edge Cases
|--------------------------------------------------------------------------
*/

test('pengguna bukan admin tidak dapat mengakses endpoint siklus angkatan', function () {
    $keti = User::factory()->create();

    $this->getJson(route('admin.academic.cohorts.preview-archive', ['cohort_year' => 2024]))
        ->assertUnauthorized();

    $this->actingAs($keti)
        ->get(route('admin.academic.cohorts.preview-archive', ['cohort_year' => 2024]))
        ->assertForbidden();

    $this->actingAs($keti)
        ->post(route('admin.academic.cohorts.graduate'), ['cohort_year' => 2024])
        ->assertForbidden();

    $this->actingAs($keti)
        ->post(route('admin.academic.cohorts.generate'), [
            'source_cohort_year' => 2024,
            'target_cohort_year' => 2025,
        ])
        ->assertForbidden();
});

test('validasi kelulusan angkatan menolak input tidak valid', function (array $payload, string $errorField) {
    ClassGroup::factory()->create(['cohort_year' => 2024]);

    $this->actingAs($this->admin)
        ->postJson(route('admin.academic.cohorts.graduate'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($errorField);
})->with([
    'missing cohort_year' => [[], 'cohort_year'],
    'non-numeric cohort_year' => [['cohort_year' => 'bukan-tahun'], 'cohort_year'],
    'cohort_year out of bounds below' => [['cohort_year' => 1999], 'cohort_year'],
    'cohort_year out of bounds above' => [['cohort_year' => 2101], 'cohort_year'],
    'cohort_year not exists in class_groups' => [['cohort_year' => 2010], 'cohort_year'],
]);

test('validasi generate angkatan menolak input tidak valid', function (array $payload, string $errorField) {
    ClassGroup::factory()->create(['cohort_year' => 2024]);

    $this->actingAs($this->admin)
        ->postJson(route('admin.academic.cohorts.generate'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($errorField);
})->with([
    'missing source_cohort_year' => [['target_cohort_year' => 2025], 'source_cohort_year'],
    'missing target_cohort_year' => [['source_cohort_year' => 2024], 'target_cohort_year'],
    'source not exists' => [['source_cohort_year' => 2015, 'target_cohort_year' => 2025], 'source_cohort_year'],
    'target same as source' => [['source_cohort_year' => 2024, 'target_cohort_year' => 2024], 'target_cohort_year'],
    'target out of range' => [['source_cohort_year' => 2024, 'target_cohort_year' => 2105], 'target_cohort_year'],
    'invalid default_student_count' => [['source_cohort_year' => 2024, 'target_cohort_year' => 2025, 'default_student_count' => 999], 'default_student_count'],
    'malformed classes array' => [['source_cohort_year' => 2024, 'target_cohort_year' => 2025, 'classes' => [['name' => '', 'study_program' => '']]], 'classes.0.name'],
]);

/*
|--------------------------------------------------------------------------
| 4. UI Integration in Academic Index (Classes Tab)
|--------------------------------------------------------------------------
*/

test('halaman tab kelas menampilkan tombol aksi dan modal siklus angkatan', function () {
    ClassGroup::factory()->create([
        'name' => 'Tekom A 2024',
        'code' => 'TEKOM-A-2024',
        'cohort_year' => 2024,
        'study_program' => 'Teknik Komputer',
        'is_active' => true,
    ]);

    $this->actingAs($this->admin)
        ->get(route('admin.academic.index', ['tab' => 'kelas']))
        ->assertOk()
        ->assertSee('Luluskan &amp; Arsipkan', false)
        ->assertSee('Buka Angkatan Baru')
        ->assertSee('modal-graduate-cohort')
        ->assertSee('modal-generate-cohort');
});
