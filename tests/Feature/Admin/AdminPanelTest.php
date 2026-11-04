<?php

use App\Enums\KetiApplicationStatus;
use App\Enums\ReservationStatus;
use App\Enums\RoomBlockSource;
use App\Enums\RoomType;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\ClassGroup;
use App\Models\ClassRepresentative;
use App\Models\KetiApplication;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\User;
use App\Services\AvailabilityService;
use App\Services\ReservationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00'));
    officialVersion();
    $this->admin = User::factory()->admin()->create(['name' => 'Admin JTIK']);
});

test('semua halaman Panel Admin tampil untuk admin', function (string $routeName, string $text) {
    $this->actingAs($this->admin)
        ->get(route($routeName))
        ->assertOk()
        ->assertSee($text);
})->with([
    ['admin.dashboard', 'Ruang JTIK hari ini'],
    ['admin.rooms.index', 'Data ruang'],
    ['admin.reservations.index', 'Reservasi tidak perlu persetujuan.'],
    ['admin.usage-logs.index', 'Siapa yang memakai ruang'],
    ['admin.keti-accounts.index', 'Periksa identitas dan kelas sebelum memberi akses pemesanan.'],
    ['admin.notifications', 'Pemberitahuan'],
    ['admin.profile', 'Profil admin'],
]);

test('Keti dan tamu tidak bisa membuka Panel Admin', function () {
    $keti = User::factory()->approvedKeti()->create();

    $this->actingAs($keti)->get(route('admin.rooms.index'))->assertForbidden();
    auth()->logout();
    $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
});

test('dashboard memakai angka ringkasan yang sama dengan AvailabilityService', function () {
    $busy = Room::factory()->create(['name' => 'AE 103']);
    Room::factory()->count(2)->create();
    scheduleBlock($busy, '2026-11-04 09:00', '2026-11-04 11:00');

    $summary = app(AvailabilityService::class)->summary(now());

    expect($summary)->toMatchArray(['all' => 3, 'available' => 2, 'busy' => 1]);

    $this->actingAs($this->admin)
        ->get(route('admin.dashboard'))
        ->assertViewHas('summary', $summary)
        ->assertSeeInOrder(['AE 103', 'Hingga 11:00', 'Digunakan']);
});

test('admin menambah ruang dengan gedung baru, fasilitas, dan kapasitas kosong', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.rooms.store'), [
            'form' => 'add',
            'name' => 'C1-R7',
            'type' => 'classroom',
            'capacity' => '',
            'building' => 'Pinisi',
            'facilities' => 'proyektor, AC, Proyektor',
        ])
        ->assertRedirect(route('admin.rooms.index'));

    $room = Room::query()->where('name', 'C1-R7')->with(['building', 'facilities'])->sole();

    expect($room->capacity)->toBeNull()
        ->and($room->building->name)->toBe('Pinisi')
        ->and($room->facilities->pluck('name')->sort()->values()->all())->toBe(['AC', 'Proyektor'])
        ->and(AuditLog::query()->where('action', 'room.created')->exists())->toBeTrue();
});

test('ruang tanpa gedung disimpan sebagai gedung belum didata', function () {
    $room = Room::factory()->create(['name' => 'Lab ICT 10']);

    $this->actingAs($this->admin)
        ->put(route('admin.rooms.update', $room), ['form' => 'edit-'.$room->id, 'name' => 'Lab ICT 10', 'type' => 'lab', 'capacity' => 35, 'building' => ''])
        ->assertRedirect(route('admin.rooms.index'));

    expect($room->fresh()->building_id)->toBeNull();
    $this->get(route('admin.rooms.index'))->assertSee('Gedung belum didata');
});

test('validasi ruang menampilkan pesan bahasa Indonesia', function () {
    $this->actingAs($this->admin)
        ->from(route('admin.rooms.index'))
        ->post(route('admin.rooms.store'), ['form' => 'add', 'name' => '', 'type' => 'classroom', 'capacity' => 'empat puluh'])
        ->assertSessionHasErrors(['name', 'capacity' => 'Isi dengan angka, misalnya 40.']);
});

test('ruang dengan reservasi aktif tidak bisa dihapus', function () {
    $room = Room::factory()->create(['name' => 'AE 106']);
    Reservation::factory()->for($room)->create(['start_at' => '2026-11-04 13:00', 'end_at' => '2026-11-04 14:00']);

    $this->actingAs($this->admin)
        ->from(route('admin.rooms.index'))
        ->delete(route('admin.rooms.destroy', $room))
        ->assertSessionHas('error_code', 'ROOM_IN_USE');

    expect($room->fresh()->trashed())->toBeFalse();
});

test('ruang yang tidak dipakai dihapus secara soft delete', function () {
    $room = Room::factory()->create();

    $this->actingAs($this->admin)->delete(route('admin.rooms.destroy', $room))->assertRedirect(route('admin.rooms.index'));

    expect($room->fresh()->trashed())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'room.deleted')->exists())->toBeTrue();
});

test('admin dapat melakukan aksi massal ubah gedung, jenis ruang, dan tambah fasilitas', function () {
    $room1 = Room::factory()->create(['name' => 'R1', 'building_id' => null, 'type' => RoomType::Classroom]);
    $room2 = Room::factory()->create(['name' => 'R2', 'building_id' => null, 'type' => RoomType::Classroom]);

    $this->actingAs($this->admin)->post(route('admin.rooms.batch'), [
        'action' => 'set_building',
        'room_ids' => [$room1->id, $room2->id],
        'building' => 'Pinisi',
    ])->assertRedirect();

    expect($room1->fresh()->building->name)->toBe('Pinisi')
        ->and($room2->fresh()->building->name)->toBe('Pinisi');

    $this->post(route('admin.rooms.batch'), [
        'action' => 'set_type',
        'room_ids' => [$room1->id, $room2->id],
        'type' => 'lab',
    ])->assertRedirect();

    expect($room1->fresh()->type)->toBe(RoomType::Lab)
        ->and($room2->fresh()->type)->toBe(RoomType::Lab);

    $this->post(route('admin.rooms.batch'), [
        'action' => 'add_facilities',
        'room_ids' => [$room1->id, $room2->id],
        'facilities' => 'Proyektor, AC',
    ])->assertRedirect();

    expect($room1->fresh()->facilities->pluck('name')->all())->toContain('Proyektor', 'AC')
        ->and($room2->fresh()->facilities->pluck('name')->all())->toContain('Proyektor', 'AC');
});

test('penutupan ruang yang berdampak meminta konfirmasi dan tidak membatalkan reservasi', function () {
    $room = Room::factory()->create(['name' => 'AE 107']);
    $classGroup = ClassGroup::factory()->create(['name' => 'Tekom C']);
    $keti = User::factory()->approvedKeti($classGroup)->create();
    $reservation = app(ReservationService::class)->create($keti, $room, now()->setTime(13, 0), now()->setTime(14, 0), 'Kerja kelompok', 20, 'key-1');

    $payload = ['form' => 'closure', 'room_id' => $room->id, 'start_at' => '2026-11-04 12:00', 'end_at' => '2026-11-04 16:00', 'reason' => 'Perawatan AC'];

    $this->actingAs($this->admin)
        ->from(route('admin.rooms.index'))
        ->post(route('admin.closures.store'), $payload)
        ->assertRedirect(route('admin.rooms.index'))
        ->assertSessionHas('closure_impact');

    expect(RoomBlock::query()->where('source_type', RoomBlockSource::Closure)->exists())->toBeFalse();

    $this->post(route('admin.closures.store'), [...$payload, 'confirm_impact' => 1])->assertSessionHas('toast');

    expect(RoomBlock::query()->where('source_type', RoomBlockSource::Closure)->where('is_active', true)->count())->toBe(1)
        ->and($reservation->fresh()->status)->toBe(ReservationStatus::Booked)
        ->and(AppNotification::query()->where('user_id', $keti->id)->where('type', 'room.closed')->exists())->toBeTrue()
        ->and(app(AvailabilityService::class)->forRoom($room, now()->setTime(15, 0))->badge())->toBe('Tutup');
});

test('membuka kembali ruang menonaktifkan blok penutupan', function () {
    $room = Room::factory()->create();
    $this->actingAs($this->admin)->post(route('admin.closures.store'), ['room_id' => $room->id, 'start_at' => '2026-11-04 12:00', 'end_at' => '2026-11-04 16:00', 'reason' => 'Perawatan']);
    $closure = $room->closures()->sole();

    $this->delete(route('admin.closures.destroy', $closure))->assertRedirect(route('admin.rooms.index'));

    expect(RoomBlock::query()->where('source_type', RoomBlockSource::Closure)->where('is_active', true)->exists())->toBeFalse();
});

test('admin membatalkan reservasi wajib dengan alasan, slot terbuka, dan Keti diberi tahu', function () {
    $room = Room::factory()->create(['name' => 'AE 106']);
    $keti = User::factory()->approvedKeti()->create();
    $reservation = app(ReservationService::class)->create($keti, $room, now()->setTime(13, 0), now()->setTime(14, 0), 'Kelas pengganti', 20, 'key-2');

    $this->actingAs($this->admin)
        ->post(route('admin.reservations.cancel', $reservation), ['reason' => ''])
        ->assertSessionHasErrors('reason');

    $this->post(route('admin.reservations.cancel', $reservation), ['reason' => 'Ruang dipakai kegiatan jurusan'])->assertSessionHas('toast');

    expect($reservation->fresh())
        ->status->toBe(ReservationStatus::Cancelled)
        ->cancel_reason->toBe('Ruang dipakai kegiatan jurusan')
        ->and($reservation->block()->first()->is_active)->toBeFalse()
        ->and(AppNotification::query()->where('user_id', $keti->id)->where('type', 'reservation.cancelled')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'reservation.cancelled')->value('reason'))->toBe('Ruang dipakai kegiatan jurusan')
        ->and(app(AvailabilityService::class)->forRoom($room, now()->setTime(13, 0), now()->setTime(14, 0))->isAvailable())->toBeTrue();
});

test('riwayat reservasi bisa difilter berdasarkan status dan kata kunci', function () {
    $room = Room::factory()->create(['name' => 'AE 209']);
    Reservation::factory()->for($room)->create(['purpose' => 'Kelas pengganti', 'status' => ReservationStatus::Booked]);
    Reservation::factory()->create(['purpose' => 'Kegiatan selesai', 'status' => ReservationStatus::Finished]);

    $this->actingAs($this->admin)
        ->get(route('admin.reservations.index', ['status' => 'selesai']))
        ->assertSee('Kegiatan selesai')
        ->assertDontSee('Kelas pengganti');

    $this->get(route('admin.reservations.index', ['q' => 'AE 209']))
        ->assertSee('Kelas pengganti')
        ->assertDontSee('Kegiatan selesai');
});

test('log pemakaian menampilkan waktu rencana dan aktual pada tanggal terpilih', function () {
    Reservation::factory()->create([
        'purpose' => 'Diskusi kelas',
        'status' => ReservationStatus::Finished,
        'start_at' => '2026-11-04 08:00',
        'end_at' => '2026-11-04 09:00',
        'actual_start_at' => '2026-11-04 08:05',
        'actual_end_at' => '2026-11-04 08:50',
    ]);
    Reservation::factory()->create(['purpose' => 'Belum dimulai', 'status' => ReservationStatus::Booked]);

    $this->actingAs($this->admin)
        ->get(route('admin.usage-logs.index', ['tanggal' => '2026-11-04']))
        ->assertSee('Diskusi kelas')
        ->assertSeeInOrder(['08:00–09:00', '08:05', '08:50'])
        ->assertDontSee('Belum dimulai');

    $this->get(route('admin.usage-logs.export', ['tanggal' => '2026-11-04']))
        ->assertOk()
        ->assertDownload('log-pemakaian-2026-11-04.csv');
});

test('menyetujui pengajuan menghubungkan Keti ke kelas dan memberi akses pemesanan', function () {
    $classGroup = ClassGroup::factory()->create(['name' => 'PTIK A']);
    $applicant = User::factory()->pendingKeti($classGroup)->create();
    $application = $applicant->latestKetiApplication;

    $this->actingAs($this->admin)
        ->post(route('admin.keti-accounts.approve', $application))
        ->assertRedirect(route('admin.keti-accounts.index', ['status' => 'approved']));

    expect($application->fresh()->status)->toBe(KetiApplicationStatus::Approved)
        ->and($applicant->fresh()->isApprovedKeti())->toBeTrue()
        ->and($applicant->fresh()->activeClassGroup()->id)->toBe($classGroup->id)
        ->and(AppNotification::query()->where('user_id', $applicant->id)->where('type', 'keti_application.approved')->exists())->toBeTrue();

    auth()->logout();
    $this->actingAs($applicant->fresh())->get(route('keti.search'))->assertOk();
});

test('satu kelas tidak boleh punya dua Keti aktif pada tanggal yang sama', function () {
    $classGroup = ClassGroup::factory()->create(['name' => 'Tekom C']);
    User::factory()->approvedKeti($classGroup)->create(['name' => 'Dimas Pratama']);
    $application = User::factory()->pendingKeti($classGroup)->create()->latestKetiApplication;

    $this->actingAs($this->admin)
        ->from(route('admin.keti-accounts.index'))
        ->post(route('admin.keti-accounts.approve', $application))
        ->assertSessionHas('error_code', 'CLASS_ALREADY_REPRESENTED');

    expect($application->fresh()->status)->toBe(KetiApplicationStatus::Pending)
        ->and(ClassRepresentative::query()->whereBelongsTo($classGroup)->count())->toBe(1);
});

test('minta perbaikan dan tolak wajib beralasan dan alasannya dikirim ke pendaftar', function (string $routeName, KetiApplicationStatus $expected) {
    $application = User::factory()->pendingKeti()->create()->latestKetiApplication;

    $this->actingAs($this->admin)->post(route($routeName, $application), ['reason' => ''])->assertSessionHasErrors('reason');
    $this->post(route($routeName, $application), ['reason' => 'Data kelas belum sesuai.']);

    expect($application->fresh())
        ->status->toBe($expected)
        ->review_note->toBe('Data kelas belum sesuai.')
        ->and(AppNotification::query()->where('user_id', $application->user_id)->value('body'))->toBe('Data kelas belum sesuai.');
})->with([
    'minta perbaikan' => ['admin.keti-accounts.revise', KetiApplicationStatus::NeedsRevision],
    'tolak' => ['admin.keti-accounts.reject', KetiApplicationStatus::Rejected],
]);

test('pengajuan yang sudah diperiksa tidak bisa diperiksa ulang', function () {
    $application = KetiApplication::factory()->approved()->create();

    $this->actingAs($this->admin)
        ->from(route('admin.keti-accounts.index'))
        ->post(route('admin.keti-accounts.reject', $application), ['reason' => 'Coba tolak'])
        ->assertSessionHas('error_code', 'INVALID_STATUS_TRANSITION');
});

test('menonaktifkan Keti mencabut hak pemesanan tanpa menghapus riwayat', function () {
    $keti = User::factory()->approvedKeti()->create();
    $reservation = Reservation::factory()->for($keti, 'creator')->create(['status' => ReservationStatus::Finished]);

    $this->actingAs($this->admin)
        ->post(route('admin.keti-accounts.deactivate', $keti), ['reason' => 'Masa jabatan berakhir'])
        ->assertSessionHas('toast');

    expect($keti->fresh()->isApprovedKeti())->toBeFalse()
        ->and($reservation->fresh())->not->toBeNull();

    auth()->logout();
    $this->actingAs($keti->fresh())->get(route('keti.search'))->assertForbidden();
});

test('tab akun Keti memisahkan pengajuan menunggu, aktif, dan ditolak', function () {
    User::factory()->pendingKeti()->create(['name' => 'Andi Saputra']);
    User::factory()->approvedKeti()->create(['name' => 'Dimas Pratama']);

    $this->actingAs($this->admin)
        ->get(route('admin.keti-accounts.index'))
        ->assertSee('Andi Saputra')
        ->assertDontSee('Dimas Pratama')
        ->assertSee('1 pengajuan menunggu pemeriksaan');

    $this->get(route('admin.keti-accounts.index', ['status' => 'approved']))
        ->assertSee('Dimas Pratama')
        ->assertDontSee('Andi Saputra');
});

test('admin memperbarui profil dan kata sandi', function () {
    $this->actingAs($this->admin)
        ->put(route('admin.profile.update'), ['name' => 'Admin Jurusan', 'email' => 'admin.jurusan@sicma.test'])
        ->assertSessionHas('toast');

    $this->put(route('admin.profile.password'), ['current_password' => 'salah', 'password' => 'rahasia123', 'password_confirmation' => 'rahasia123'])
        ->assertSessionHasErrors('current_password');

    $this->put(route('admin.profile.password'), ['current_password' => 'password', 'password' => 'rahasia123', 'password_confirmation' => 'rahasia123'])
        ->assertSessionHas('toast');

    expect($this->admin->fresh()->name)->toBe('Admin Jurusan')
        ->and(Hash::check('rahasia123', $this->admin->fresh()->password))->toBeTrue();
});

test('notifikasi admin bisa ditandai dibaca', function () {
    AppNotification::create(['user_id' => $this->admin->id, 'type' => 'keti_application.submitted', 'title' => 'Pengajuan Keti baru', 'body' => 'Andi Saputra mengajukan akun Keti.', 'data' => []]);

    $this->actingAs($this->admin)
        ->get(route('admin.notifications', ['filter' => 'belum']))
        ->assertSee('Pengajuan Keti baru');

    $this->post(route('admin.notifications.read-all'));

    expect($this->admin->appNotifications()->unread()->count())->toBe(0);
});
