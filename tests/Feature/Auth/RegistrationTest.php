<?php

use App\Enums\KetiApplicationStatus;
use App\Enums\UserRole;
use App\Mail\VerificationCodeMail;
use App\Models\Academic\ClassGroup;
use App\Models\User\ClassRepresentative;
use App\Models\User\User;
use Illuminate\Support\Facades\Mail;

test('halaman pendaftaran menampilkan kelas aktif dan menandai kelas yang sudah memiliki Keti aktif', function (): void {
    $this->withoutVite();

    $availableClass = ClassGroup::factory()->create(['name' => 'Tekom A', 'is_active' => true]);
    $takenClass = ClassGroup::factory()->create(['name' => 'Tekom B', 'is_active' => true]);
    $expiredClass = ClassGroup::factory()->create(['name' => 'Tekom C', 'is_active' => true]);
    ClassGroup::factory()->inactive()->create(['name' => 'Tekom Nonaktif']);

    ClassRepresentative::factory()->for($takenClass)->create([
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
        ->assertDontSee($availableClass->name.' (Sudah ada Keti)')
        ->assertSee($takenClass->name.' (Sudah ada Keti)')
        ->assertSee($expiredClass->name)
        ->assertDontSee($expiredClass->name.' (Sudah ada Keti)')
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
