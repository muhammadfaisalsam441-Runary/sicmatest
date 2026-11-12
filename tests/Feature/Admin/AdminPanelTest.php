<?php

use App\Enums\KetiApplicationStatus;
use App\Enums\RescheduleStatus;
use App\Enums\ReservationStatus;
use App\Enums\RoomBlockSource;
use App\Enums\RoomType;
use App\Enums\SessionPhase;
use App\Enums\TeachingRole;
use App\Models\Academic\ClassGroup;
use App\Models\Academic\SessionReschedule;
use App\Models\Lecturer\Lecturer;
use App\Models\Reservation\Reservation;
use App\Models\Room\Building;
use App\Models\Room\Room;
use App\Models\Room\RoomBlock;
use App\Models\User\AppNotification;
use App\Models\User\AuditLog;
use App\Models\User\ClassRepresentative;
use App\Models\User\KetiApplication;
use App\Models\User\User;
use App\Services\Academic\MeetingRescheduleService;
use App\Services\Room\AvailabilityService;
use App\Services\Room\ReservationService;
use App\Support\Wita;
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

test('admin mengubah kapasitas hanya untuk ruang terpilih', function () {
    $room1 = Room::factory()->create(['capacity' => null]);
    $room2 = Room::factory()->create(['capacity' => 20]);
    $other = Room::factory()->create(['capacity' => 50]);

    $this->actingAs($this->admin)
        ->from(route('admin.rooms.index'))
        ->post(route('admin.rooms.batch'), [
            'form' => 'batch_capacity',
            'action' => 'set_capacity',
            'room_ids' => [$room1->id, $room2->id],
            'capacity' => 30,
        ])
        ->assertRedirect(route('admin.rooms.index'))
        ->assertSessionHas('toast.title', 'Berhasil memperbarui 2 ruang');

    expect($room1->fresh()->capacity)->toBe(30)
        ->and($room2->fresh()->capacity)->toBe(30)
        ->and($other->fresh()->capacity)->toBe(50)
        ->and($room1->fresh()->building_id)->toBe($room1->building_id)
        ->and($room2->fresh()->type)->toBe($room2->type);
});

test('admin dapat memperbarui banyak ruang sekaligus lewat edit massal di tabel', function () {
    $buildingA = Building::factory()->create(['name' => 'Gedung A']);
    $buildingB = Building::factory()->create(['name' => 'Gedung B']);
    $room1 = Room::factory()->for($buildingA)->create(['name' => 'R101', 'code' => 'R101', 'floor' => 1, 'type' => RoomType::Classroom, 'capacity' => 30]);
    $room2 = Room::factory()->for($buildingA)->create(['name' => 'Lab Lama', 'code' => 'LAB-LAMA', 'floor' => 2, 'type' => RoomType::Lab, 'capacity' => null]);

    $this->actingAs($this->admin)
        ->from(route('admin.rooms.index'))
        ->put(route('admin.rooms.bulk-update'), [
            // Format sama dengan formulir #bulkRoomForm di admin/rooms/index: id di dalam baris, gedung berupa nama.
            'rooms' => [
                [
                    'id' => $room1->id,
                    'name' => 'R101',
                    'building' => $buildingB->name,
                    'floor' => 2,
                    'type' => RoomType::Classroom->value,
                    'capacity' => 45,
                ],
                [
                    'id' => $room2->id,
                    'name' => 'Lab Jaringan Baru',
                    'building' => $buildingB->name,
                    'floor' => 3,
                    'type' => RoomType::Lab->value,
                    'capacity' => 36,
                ],
            ],
        ])
        ->assertRedirect(route('admin.rooms.index'))
        ->assertSessionHas('toast.title', 'Berhasil menyimpan 2 ruang');

    $room1->refresh();
    $room2->refresh();

    expect($room1->building_id)->toBe($buildingB->id)
        ->and($room1->floor)->toBe(2)
        ->and($room1->capacity)->toBe(45)
        ->and($room2->name)->toBe('Lab Jaringan Baru')
        ->and($room2->code)->toBe('LAB-JARINGAN-BARU')
        ->and($room2->building_id)->toBe($buildingB->id)
        ->and($room2->floor)->toBe(3)
        ->and($room2->capacity)->toBe(36);
});

test('kapasitas massal yang tidak valid tidak mengubah ruang dan mempertahankan pilihan', function (mixed $capacity, string $message) {
    $room = Room::factory()->create(['capacity' => 20]);

    $this->actingAs($this->admin)
        ->from(route('admin.rooms.index'))
        ->post(route('admin.rooms.batch'), [
            'form' => 'batch_capacity',
            'action' => 'set_capacity',
            'room_ids' => [$room->id],
            'capacity' => $capacity,
        ])
        ->assertRedirect(route('admin.rooms.index'))
        ->assertSessionHasErrors(['capacity' => $message])
        ->assertSessionHasInput('room_ids', [$room->id]);

    expect($room->fresh()->capacity)->toBe(20);
    $this->withCookie(config('session.cookie'), session()->getId())
        ->get(route('admin.rooms.index'))->assertOk()->assertSee($message);
})->with([
    'kosong' => [null, 'Masukkan kapasitas ruang.'],
    'nol' => [0, 'Kapasitas minimal 1 orang.'],
    'negatif' => [-1, 'Kapasitas minimal 1 orang.'],
    'pecahan' => [30.5, 'Isi dengan angka bulat, misalnya 40.'],
    'teks' => ['tiga puluh', 'Isi dengan angka bulat, misalnya 40.'],
    'melebihi batas' => [1001, 'Kapasitas maksimal 1.000 orang.'],
]);

test('kapasitas massal menolak pilihan ruang kosong atau tidak terdaftar', function (array $ids, string $field) {
    $room = Room::factory()->create(['capacity' => 20]);

    $this->actingAs($this->admin)
        ->post(route('admin.rooms.batch'), [
            'action' => 'set_capacity',
            'room_ids' => $ids,
            'capacity' => 30,
        ])->assertSessionHasErrors($field);

    expect($room->fresh()->capacity)->toBe(20);
})->with([
    'tidak memilih ruang' => [[], 'room_ids'],
    'ruang tidak terdaftar' => [[999999], 'room_ids.0'],
]);

test('Keti dan tamu tidak dapat mengubah kapasitas ruang secara massal', function () {
    $room = Room::factory()->create(['capacity' => 20]);
    $payload = ['action' => 'set_capacity', 'room_ids' => [$room->id], 'capacity' => 30];

    $this->actingAs(User::factory()->approvedKeti()->create())
        ->post(route('admin.rooms.batch'), $payload)->assertForbidden();
    auth()->logout();
    $this->post(route('admin.rooms.batch'), $payload)->assertRedirect(route('login'));

    expect($room->fresh()->capacity)->toBe(20);
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

test('penutupan ruang dengan opsi batalkan reservasi otomatis membatalkan reservasi terdampak', function () {
    $room = Room::factory()->create(['name' => 'AE 105']);
    $keti = User::factory()->approvedKeti()->create();
    $reservation = app(ReservationService::class)->create($keti, $room, now()->setTime(14, 0), now()->setTime(16, 0), 'Kelas pengganti', 20, 'key-closure-cancel');

    $payload = [
        'room_id' => $room->id,
        'start_at' => now()->setTime(13, 0)->format('Y-m-d H:i'),
        'end_at' => now()->setTime(17, 0)->format('Y-m-d H:i'),
        'reason' => 'Perbaikan Listrik',
        'confirm_impact' => 1,
        'cancel_impacted' => 1,
    ];

    $this->actingAs($this->admin)
        ->post(route('admin.closures.store'), $payload)
        ->assertRedirect(route('admin.rooms.index'))
        ->assertSessionHas('toast');

    expect($reservation->fresh()->status)->toBe(ReservationStatus::Cancelled);
});

test('membuka kembali ruang menonaktifkan blok penutupan', function () {
    $room = Room::factory()->create();
    $this->actingAs($this->admin)->post(route('admin.closures.store'), ['room_id' => $room->id, 'start_at' => '2026-11-04 12:00', 'end_at' => '2026-11-04 16:00', 'reason' => 'Perawatan']);
    $closure = $room->closures()->sole();

    $this->delete(route('admin.closures.destroy', $closure))->assertRedirect(route('admin.rooms.index'));

    expect(RoomBlock::query()->where('source_type', RoomBlockSource::Closure)->where('is_active', true)->exists())->toBeFalse();
});

test('penutupan ruang masa depan tidak dianggap tutup hari ini di daftar ruang admin', function () {
    $room = Room::factory()->create(['name' => 'AE 109']);
    // Penutupan minggu depan (hari ini 2026-11-04)
    $this->actingAs($this->admin)->post(route('admin.closures.store'), [
        'room_id' => $room->id,
        'start_at' => '2026-11-11 08:00',
        'end_at' => '2026-11-11 12:00',
        'reason' => 'Renovasi Depan',
    ]);

    $response = $this->get(route('admin.rooms.index'));
    $response->assertOk();

    expect($response->viewData('closedRoomsCount'))->toBe(0)
        ->and($response->viewData('closures')->contains('room_id', $room->id))->toBeFalse();
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

test('klik notifikasi admin redirect ke scheduler show jika ada optimization_job_id atau drafts show jika ada schedule_version_id', function () {
    $notif1 = AppNotification::create([
        'user_id' => $this->admin->id,
        'type' => 'optimization.completed',
        'title' => 'Optimasi selesai',
        'body' => 'Jadwal berhasil disusun.',
        'data' => ['optimization_job_id' => 99],
    ]);

    $this->actingAs($this->admin)
        ->post(route('admin.notifications.read', $notif1))
        ->assertRedirect(route('admin.scheduler.show', 99));

    $notif2 = AppNotification::create([
        'user_id' => $this->admin->id,
        'type' => 'schedule.draft_created',
        'title' => 'Draf baru dibuat',
        'body' => 'Draf v2 berhasil dibuat.',
        'data' => ['schedule_version_id' => 88],
    ]);

    $this->post(route('admin.notifications.read', $notif2))
        ->assertRedirect(route('admin.drafts.show', 88));
});

/**
 * Pertemuan Pemrograman Web – Tekom C (Senin 9 Nov 07:30, AE 107) yang dipindah ke Rabu 4 Nov 13:00 di AE 209.
 */
function adminMovedMeeting(): SessionReschedule
{
    $classGroup = ClassGroup::factory()->create(['name' => 'Tekom C']);
    $partner = Lecturer::factory()->create(['name' => 'Sari Dewi']);
    $ae107 = Room::factory()->create(['name' => 'AE 107']);
    $ae209 = Room::factory()->create(['name' => 'AE 209']);
    $session = meetingSession([$classGroup], ['title' => 'Pemrograman Web', 'room_id' => $ae107->id], [[$partner, TeachingRole::Partner, SessionPhase::AfterUts]]);

    return app(MeetingRescheduleService::class)->move(
        User::factory()->approvedKeti($classGroup)->create(), $session, CarbonImmutable::parse('2026-11-09'),
        CarbonImmutable::parse('2026-11-04 13:00'), $ae209, [$partner->id], (string) str()->uuid(),
    );
}

test('riwayat reservasi admin menandai pemindahan pertemuan beserta jadwal asal dan dosen', function () {
    $reschedule = adminMovedMeeting();
    $original = $reschedule->original_start_at;

    $this->actingAs($this->admin)->get(route('admin.reservations.index'))->assertOk()
        ->assertSee('Pindahan')
        ->assertSee('dari '.Wita::longDate($original).' '.Wita::clock($original, false))
        ->assertSee('Sari Dewi');
});

test('dashboard admin menampilkan kuliah pengganti dengan nama mata kuliahnya', function () {
    adminMovedMeeting();
    $this->travelTo(CarbonImmutable::parse('2026-11-04 13:10'));

    $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk()
        ->assertSee('Kuliah Pengganti: Pemrograman Web');

    expect(app(AvailabilityService::class)->forRoom(Room::query()->where('name', 'AE 107')->sole(), CarbonImmutable::parse('2026-11-09 08:00'))->isAvailable())->toBeTrue();
});

test('admin membatalkan pengganti dengan alasan dan pertemuan kembali ke jam asli', function () {
    $reschedule = adminMovedMeeting();

    $this->actingAs($this->admin)->post(route('admin.reservations.cancel', $reschedule->reservation_id), ['reason' => 'Ruang dipakai rapat jurusan'])
        ->assertRedirect();

    expect($reschedule->fresh()->status)->toBe(RescheduleStatus::Cancelled)
        ->and(RoomBlock::query()->where('source_type', RoomBlockSource::Schedule)->where('start_at', '2026-11-09 07:30:00')->sole()->is_active)->toBeTrue();
});
