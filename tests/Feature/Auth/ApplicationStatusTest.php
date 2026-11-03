<?php

use App\Enums\KetiApplicationStatus;
use App\Models\ClassGroup;
use App\Models\KetiApplication;
use App\Models\User;

test('pengajuan pending tidak bisa membuka Portal Keti', function () {
    $keti = User::factory()->pendingKeti()->create();

    $this->actingAs($keti)->get(route('keti.search'))->assertRedirect(route('keti.application'));
    $this->actingAs($keti)->get(route('keti.application'))->assertOk()->assertSee('Pengajuan sedang diperiksa');
});

test('setelah disetujui Keti bisa membuka Portal Keti', function () {
    $keti = User::factory()->approvedKeti()->create();

    $this->actingAs($keti)->get(route('keti.search'))->assertOk()->assertSee('Cari Ruangan');
    $this->actingAs($keti)->get(route('keti.application'))->assertSee('Akun Keti sudah aktif');
});

test('pengajuan yang perlu diperbaiki menampilkan alasan dan bisa diajukan ulang', function () {
    $keti = User::factory()->create();
    KetiApplication::factory()->for($keti)->needsRevision()->create(['review_note' => 'Data kelas belum sesuai.']);
    $newClass = ClassGroup::factory()->create();

    $this->actingAs($keti)->get(route('keti.application'))
        ->assertSee('Pengajuan perlu diperbaiki')
        ->assertSee('Data kelas belum sesuai.');

    $this->actingAs($keti)
        ->put(route('keti.application.resubmit'), ['class_group_id' => $newClass->id])
        ->assertRedirect(route('keti.application'));

    expect($keti->ketiApplications()->count())->toBe(2)
        ->and($keti->fresh()->latestKetiApplication->status)->toBe(KetiApplicationStatus::Pending)
        ->and($keti->fresh()->latestKetiApplication->class_group_id)->toBe($newClass->id);
});

test('pengajuan pending tidak bisa diajukan ulang', function () {
    $keti = User::factory()->pendingKeti()->create();

    $this->actingAs($keti)
        ->from(route('keti.application'))
        ->put(route('keti.application.resubmit'), ['class_group_id' => ClassGroup::factory()->create()->id])
        ->assertSessionHas('error_code', 'INVALID_STATUS_TRANSITION');

    expect($keti->ketiApplications()->count())->toBe(1);
});
