<?php

use App\Enums\ReservationStatus;
use App\Enums\RoomBlockSource;
use App\Enums\RoomStatus;
use App\Models\Room\Room;
use App\Models\Room\RoomBlock;
use App\Models\Room\RoomClosure;
use App\Models\User\AppNotification;
use App\Models\User\User;
use App\Services\Room\AvailabilityService;
use App\Services\Room\ReservationService;
use App\Services\Room\SearchCriteria;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->withoutVite();
    // Rabu, 4 November 2026
    $this->travelTo(CarbonImmutable::parse('2026-11-04 07:00'));
    officialVersion();
    $this->admin = User::factory()->admin()->create(['name' => 'Admin JTIK']);
});

test('penutupan ruang berulang membuat blok harian sesuai hari yang dipilih dan membuka jam di luar rentang', function () {
    $room = Room::factory()->create(['name' => 'Lab Jaringan']);

    // 4 Nov (Rab) s.d. 15 Nov (Min): Sen-Jum ada 8 hari kerja (4,5,6, 9,10,11,12,13)
    $this->actingAs($this->admin)
        ->post(route('admin.closures.store'), [
            'mode' => 'recurring',
            'room_id' => $room->id,
            'start_date' => '2026-11-04',
            'end_date' => '2026-11-15',
            'start_time' => '07:30',
            'end_time' => '12:00',
            'days_of_week' => [1, 2, 3, 4, 5],
            'reason' => 'Praktikum terjadwal khusus prodi',
        ])
        ->assertRedirect(route('admin.rooms.index'))
        ->assertSessionHas('toast.type', 'success');

    $closure = RoomClosure::query()->whereBelongsTo($room)->sole();
    expect($closure->is_recurring)->toBeTrue()
        ->and($closure->days_of_week)->toBe([1, 2, 3, 4, 5])
        ->and($closure->daily_start_time)->toBe('07:30')
        ->and($closure->daily_end_time)->toBe('12:00')
        ->and(RoomBlock::query()->where('source_type', RoomBlockSource::Closure)->where('source_id', $closure->id)->where('is_active', true)->count())->toBe(8);

    $availability = app(AvailabilityService::class);

    // Rabu 4 Nov jam 09:00 -> Tutup
    $morning = $availability->forRoom($room, CarbonImmutable::parse('2026-11-04 09:00'));
    expect($morning->status)->toBe(RoomStatus::Closed)
        ->and($morning->badge())->toBe('Tutup');

    // Rabu 4 Nov jam 13:00 -> Tersedia
    $afternoon = $availability->forRoom($room, CarbonImmutable::parse('2026-11-04 13:00'));
    expect($afternoon->status)->toBe(RoomStatus::Available);

    // Sabtu 7 Nov jam 09:00 -> Tersedia (Sabtu tidak termasuk Sen-Jum)
    $saturday = $availability->forRoom($room, CarbonImmutable::parse('2026-11-07 09:00'));
    expect($saturday->status)->toBe(RoomStatus::Available);

    // Slot pemesanan pada 4 Nov hanya tersedia mulai 12:00
    $slots = $availability->dailySlots(new SearchCriteria(
        start: CarbonImmutable::parse('2026-11-04 07:30'),
        durationMinutes: 100,
        roomId: $room->id,
    ));

    expect($slots)->toHaveCount(1)
        ->and($slots->first()['windows'])->toHaveCount(1)
        ->and($slots->first()['windows'][0]['start']->format('H:i'))->toBe('12:00');
});

test('status ruang berulang di panel admin hanya ditutup pada jam tutup harian dan daftar aturan tetap dapat dihapus', function () {
    $room = Room::factory()->create(['name' => 'Lab Multimedia']);

    $this->actingAs($this->admin)
        ->post(route('admin.closures.store'), [
            'mode' => 'recurring',
            'room_id' => $room->id,
            'start_date' => '2026-11-04',
            'end_date' => '2026-11-11',
            'start_time' => '07:30',
            'end_time' => '12:00',
            'days_of_week' => [1, 2, 3, 4, 5],
            'reason' => 'Perawatan rutin pagi',
        ])
        ->assertRedirect(route('admin.rooms.index'));

    $closure = RoomClosure::query()->whereBelongsTo($room)->sole();

    // Pukul 10:00 di hari kerja -> Ditutup
    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00'));
    $morningRes = $this->actingAs($this->admin)->get(route('admin.rooms.index'))->assertOk();
    expect($morningRes->viewData('closedRoomsCount'))->toBe(1)
        ->and($morningRes->viewData('closures')->contains('id', $closure->id))->toBeTrue()
        ->and($morningRes->viewData('recurringClosures')->contains('id', $closure->id))->toBeTrue();

    // Pukul 14:00 di hari yang sama -> Tersedia, namun aturan berulang tetap muncul di recurringClosures
    $this->travelTo(CarbonImmutable::parse('2026-11-04 14:00'));
    $afternoonRes = $this->actingAs($this->admin)->get(route('admin.rooms.index'))->assertOk();
    expect($afternoonRes->viewData('closedRoomsCount'))->toBe(0)
        ->and($afternoonRes->viewData('closures')->contains('id', $closure->id))->toBeFalse()
        ->and($afternoonRes->viewData('recurringClosures')->contains('id', $closure->id))->toBeTrue();

    // Hapus aturan berulang menonaktifkan semua bloknya
    $this->delete(route('admin.closures.destroy', $closure))
        ->assertRedirect(route('admin.rooms.index'))
        ->assertSessionHas('toast.type', 'success');

    expect(RoomClosure::query()->find($closure->id))->toBeNull()
        ->and(RoomBlock::query()->where('source_type', RoomBlockSource::Closure)->where('source_id', $closure->id)->where('is_active', true)->exists())->toBeFalse();

    $afterDelete = app(AvailabilityService::class)->forRoom($room, CarbonImmutable::parse('2026-11-05 09:00'));
    expect($afterDelete->status)->toBe(RoomStatus::Available);
});

test('pratinjau dampak penutupan berulang hanya mendeteksi reservasi pada jam tutup harian', function () {
    $room = Room::factory()->create(['name' => 'AE 201']);
    $keti = User::factory()->approvedKeti()->create();
    $reservations = app(ReservationService::class);

    // Reservasi pagi (09:00-10:40) -> beririsan dengan 07:30-12:00
    $morningBooking = $reservations->create(
        $keti,
        $room,
        CarbonImmutable::parse('2026-11-05 09:00'),
        CarbonImmutable::parse('2026-11-05 10:40'),
        'Diskusi / Kerja Kelompok',
        20,
        'recurring-impact-morning',
    );

    // Reservasi siang (13:00-14:40) -> di luar 07:30-12:00
    $afternoonBooking = $reservations->create(
        $keti,
        $room,
        CarbonImmutable::parse('2026-11-05 13:00'),
        CarbonImmutable::parse('2026-11-05 14:40'),
        'Diskusi / Kerja Kelompok',
        20,
        'recurring-impact-afternoon',
    );

    $payload = [
        'mode' => 'recurring',
        'room_id' => $room->id,
        'start_date' => '2026-11-04',
        'end_date' => '2026-11-06',
        'start_time' => '07:30',
        'end_time' => '12:00',
        'days_of_week' => [1, 2, 3, 4, 5],
        'reason' => 'Khusus pagi',
    ];

    $this->actingAs($this->admin)
        ->from(route('admin.rooms.index'))
        ->post(route('admin.closures.store'), $payload)
        ->assertRedirect(route('admin.rooms.index'))
        ->assertSessionHas('closure_impact', fn (array $impact): bool => count($impact) === 1);

    expect(RoomBlock::query()->where('source_type', RoomBlockSource::Closure)->exists())->toBeFalse();

    $this->post(route('admin.closures.store'), [
        ...$payload,
        'confirm_impact' => 1,
        'cancel_impacted' => 1,
    ])->assertRedirect(route('admin.rooms.index'))
        ->assertSessionHas('toast.type', 'success');

    expect($morningBooking->fresh()->status)->toBe(ReservationStatus::Cancelled)
        ->and($afternoonBooking->fresh()->status)->toBe(ReservationStatus::Booked)
        ->and(AppNotification::query()->where('user_id', $keti->id)->exists())->toBeTrue();
});

test('penutupan berulang menolak rentang melebihi 366 hari atau tidak ada tanggal yang cocok', function () {
    $room = Room::factory()->create();

    $this->actingAs($this->admin)
        ->from(route('admin.rooms.index'))
        ->post(route('admin.closures.store'), [
            'mode' => 'recurring',
            'room_id' => $room->id,
            'start_date' => '2026-11-04',
            'end_date' => '2027-12-01',
            'start_time' => '07:30',
            'end_time' => '12:00',
            'days_of_week' => [1, 2, 3, 4, 5],
            'reason' => 'Terlalu panjang',
        ])
        ->assertSessionHasErrors('end_date');

    // 2026-11-04 (Rabu) - 2026-11-05 (Kamis) tetapi memilih hanya Sabtu (6)
    $this->actingAs($this->admin)
        ->from(route('admin.rooms.index'))
        ->post(route('admin.closures.store'), [
            'mode' => 'recurring',
            'room_id' => $room->id,
            'start_date' => '2026-11-04',
            'end_date' => '2026-11-05',
            'start_time' => '07:30',
            'end_time' => '12:00',
            'days_of_week' => [6],
            'reason' => 'Tidak ada hari cocok',
        ])
        ->assertSessionHasErrors('days_of_week');
});
