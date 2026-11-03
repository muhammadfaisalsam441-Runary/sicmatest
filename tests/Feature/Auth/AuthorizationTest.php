<?php

use App\Models\User;
use Illuminate\Support\Facades\Gate;

test('tamu diarahkan ke halaman masuk', function (string $routeName) {
    $this->get(route($routeName))->assertRedirect(route('login'));
})->with(['keti.search', 'admin.dashboard', 'keti.application']);

test('Keti tidak bisa membuka Panel Admin', function () {
    $this->actingAs(User::factory()->approvedKeti()->create())
        ->get(route('admin.dashboard'))
        ->assertForbidden();
});

test('admin tidak bisa membuka Portal Keti', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('keti.search'))
        ->assertForbidden();
});

test('admin nonaktif tidak bisa membuka Panel Admin', function () {
    $this->actingAs(User::factory()->admin()->create(['is_active' => false]))
        ->get(route('admin.dashboard'))
        ->assertForbidden();
});

test('pengguna yang sudah masuk diarahkan dari halaman masuk ke beranda perannya', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('login'))
        ->assertRedirect(route('admin.dashboard'));
});

test('hanya admin dengan izin publish yang boleh menetapkan jadwal', function () {
    expect(Gate::forUser(User::factory()->superAdmin()->create())->allows('publish-schedule'))->toBeTrue()
        ->and(Gate::forUser(User::factory()->admin()->create())->allows('publish-schedule'))->toBeFalse()
        ->and(Gate::forUser(User::factory()->admin()->create(['can_publish' => true]))->allows('publish-schedule'))->toBeTrue()
        ->and(Gate::forUser(User::factory()->approvedKeti()->create(['can_publish' => true]))->allows('publish-schedule'))->toBeFalse();
});
