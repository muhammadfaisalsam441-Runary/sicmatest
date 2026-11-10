<?php

use App\Models\Lecturer\Lecturer;
use App\Models\Room\Room;
use App\Models\User\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00'));
    officialVersion();
});

test('label data di Portal Publik bisa diganti lewat config tanpa mengubah tampilan', function () {
    Room::factory()->create();

    $this->get(route('public.rooms'))->assertOk()->assertSee('Data simulasi');

    config(['sicma.public_portal.sim_label' => 'Jadwal resmi']);

    $this->get(route('public.rooms'))->assertOk()
        ->assertSee('Jadwal resmi')
        ->assertDontSee('Data simulasi');
    $this->get(route('public.help'))->assertOk()
        ->assertSee('Status dihitung dari jadwal resmi, reservasi, dan penutupan ruang.');
});

test('label data bisa disembunyikan', function () {
    config(['sicma.public_portal.show_sim_label' => false]);

    $this->get(route('home'))->assertOk()->assertDontSee('Data simulasi');
});

test('Data Dosen meringkas NIDN yang belum diisi tanpa warna peringatan di setiap baris', function () {
    Lecturer::factory()->count(2)->create(['nidn' => null]);
    Lecturer::factory()->create(['nidn' => '0011223344']);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.lecturers.index'))->assertOk()
        ->assertSee('2 dari 3 dosen belum memiliki NIDN')
        ->assertDontSee('text-danger text-[11px] font-medium">NIDN belum diisi', false);
});
