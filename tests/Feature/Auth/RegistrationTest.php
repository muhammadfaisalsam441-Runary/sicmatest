<?php

use App\Enums\KetiApplicationStatus;
use App\Enums\UserRole;
use App\Mail\VerificationCodeMail;
use App\Models\Academic\ClassGroup;
use App\Models\User\ClassRepresentative;
use App\Models\User\User;
use Illuminate\Support\Facades\Mail;

test('halaman pendaftaran menampilkan kelas aktif dan menandai kuota Keti per kelas', function (): void {
    $this->withoutVite();

    $availableClass = ClassGroup::factory()->create(['name' => 'Tekom A', 'is_active' => true]);
    $oneKetiClass = ClassGroup::factory()->create(['name' => 'Tekom B', 'is_active' => true]);
    $fullClass = ClassGroup::factory()->create(['name' => 'Tekom C', 'is_active' => true]);
    $expiredClass = ClassGroup::factory()->create(['name' => 'Tekom D', 'is_active' => true]);
    ClassGroup::factory()->inactive()->create(['name' => 'Tekom Nonaktif']);

    ClassRepresentative::factory()->for($oneKetiClass)->create([
        'valid_from' => now()->subDays(10)->toDateString(),
        'valid_to' => null,
    ]);
    ClassRepresentative::factory()->for($fullClass)->count(2)->create([
        'valid_from' => now()->subDays(10)->toDateString(),
        'valid_to' => null,
    ]);
    ClassRepresentative::factory()->for($expiredClass)->create([
        'valid_from' => now()->subMonth()->toDateString(),
        'valid_to' => now()->subDay()->toDateString(),
    ]);

    $this->get(route('register'))
        ->assertOk()
        ->assertSee($availableClass->name)
        ->assertDontSee($availableClass->name.' (')
        ->assertSee($oneKetiClass->name.' (1/2 Keti aktif)')
        ->assertSee($fullClass->name.' (Kuota Keti penuh 2/2)')
        ->assertSee($expiredClass->name)
        ->assertDontSee($expiredClass->name.' (')
        ->assertDontSee('Tekom Nonaktif');
});

test('Keti mendaftar, akun belum terverifikasi, dan kode dikirim', function () {
    Mail::fake();
    $classGroup = ClassGroup::factory()->create();

    $this->post(route('register.store'), [
        'name' => 'Dimas Pratama',
        'nim' => '2502040001',
        'email' => 'Dimas@Sicma.test',
        'class_group_id' => $classGroup->id,
        'password' => 'rahasia-panjang',
    ])->assertRedirect(route('verification.notice'));

    $user = User::query()->where('nim', '2502040001')->sole();

    expect($user->role)->toBe(UserRole::Keti)
        ->and($user->email)->toBe('dimas@sicma.test')
        ->and($user->hasVerifiedEmail())->toBeFalse()
        ->and($user->latestKetiApplication->status)->toBe(KetiApplicationStatus::Pending)
        ->and($user->latestKetiApplication->class_group_id)->toBe($classGroup->id);

    $this->assertAuthenticatedAs($user);
    Mail::assertSent(VerificationCodeMail::class, fn (VerificationCodeMail $mail) => $mail->hasTo('dimas@sicma.test') && strlen($mail->code) === 6);
});

test('NIM atau email yang sudah terdaftar menampilkan "Periksa data pendaftaran"', function () {
    User::factory()->create(['nim' => '2502040001']);

    $this->from(route('register'))
        ->post(route('register.store'), [
            'name' => 'Dimas',
            'nim' => '2502040001',
            'email' => 'baru@sicma.test',
            'class_group_id' => ClassGroup::factory()->create()->id,
            'password' => 'rahasia-panjang',
        ])
        ->assertRedirect(route('register'))
        ->assertSessionHasErrors(['nim' => 'NIM sudah terdaftar.']);

    $this->withViewErrors(['nim' => 'NIM sudah terdaftar.'])
        ->view('auth.register', ['classGroups' => collect()])
        ->assertSee('Periksa data pendaftaran')
        ->assertSee('Akun mungkin sudah ada');
});

test('kelas nonaktif tidak bisa dipilih saat mendaftar', function () {
    $this->post(route('register.store'), [
        'name' => 'Dimas',
        'nim' => '2502040009',
        'email' => 'dimas@sicma.test',
        'class_group_id' => ClassGroup::factory()->inactive()->create()->id,
        'password' => 'rahasia-panjang',
    ])->assertSessionHasErrors('class_group_id');
});

test('daftar kelas di halaman pendaftaran dikelompokkan dan diurutkan berdasarkan prodi, angkatan terbaru, dan jenis kelas', function (): void {
    $this->withoutVite();

    ClassGroup::factory()->create(['name' => 'TEKOM A 2025', 'study_program' => 'Teknik Komputer', 'cohort_year' => 2025]);
    ClassGroup::factory()->create(['name' => 'PTIK A 2024', 'study_program' => 'Pendidikan Teknik Informatika dan Komputer', 'cohort_year' => 2024]);
    ClassGroup::factory()->create(['name' => 'PTIK ULANG 2026', 'study_program' => 'Pendidikan Teknik Informatika dan Komputer', 'cohort_year' => 2026]);
    ClassGroup::factory()->create(['name' => 'PTIK B 2026', 'study_program' => 'Pendidikan Teknik Informatika dan Komputer', 'cohort_year' => 2026]);
    ClassGroup::factory()->create(['name' => 'PTIK A 2026', 'study_program' => 'Pendidikan Teknik Informatika dan Komputer', 'cohort_year' => 2026]);
    ClassGroup::factory()->create(['name' => 'PTIK Pilihan A 2026', 'study_program' => 'Pendidikan Teknik Informatika dan Komputer', 'cohort_year' => 2026]);

    $this->get(route('register'))
        ->assertOk()
        ->assertSeeInOrder([
            '<optgroup label="PTIK — Angkatan 2026">',
            'PTIK A 2026',
            'PTIK B 2026',
            'PTIK Pilihan A 2026',
            'PTIK ULANG 2026',
            '<optgroup label="PTIK — Angkatan 2024">',
            'PTIK A 2024',
            '<optgroup label="TEKOM — Angkatan 2025">',
            'TEKOM A 2025',
        ], false);
});
