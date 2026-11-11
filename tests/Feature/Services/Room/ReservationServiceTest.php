<?php

use App\Enums\ReservationStatus;
use App\Enums\RoomBlockSource;
use App\Exceptions\DomainException;
use App\Models\Academic\ClassGroup;
use App\Models\Reservation\Reservation;
use App\Models\Room\Room;
use App\Models\Room\RoomBlock;
use App\Models\User\AppNotification;
use App\Models\User\AuditLog;
use App\Models\User\User;
use App\Services\Room\ReservationService;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00'));
    officialVersion();
    $this->service = app(ReservationService::class);
});

function reserveFor(User $keti, Room $room, string $start = '2026-11-04 10:00', string $end = '2026-11-04 11:00', ?string $key = null, ?int $participants = 20): Reservation
{
    return app(ReservationService::class)->create(
        $keti, $room, CarbonImmutable::parse($start), CarbonImmutable::parse($end), 'Kerja kelompok', $participants, $key ?? (string) str()->uuid(),
    );
}

function expectDomainError(Closure $callback, string $code): void
{
    try {
        $callback();
    } catch (DomainException $exception) {
        expect($exception->errorCode)->toBe($code);

        return;
    }

    test()->fail("DomainException {$code} tidak dilempar.");
}

test('Keti memesan ruang atas nama kelasnya dan ruang langsung terblokir', function () {
    $classGroup = ClassGroup::factory()->create(['name' => 'Tekom C']);
    $keti = User::factory()->approvedKeti($classGroup)->create();
    $room = Room::factory()->create(['name' => 'AE 107']);

    $reservation = reserveFor($keti, $room);

    expect($reservation->status)->toBe(ReservationStatus::Booked)
        ->and($reservation->class_group_id)->toBe($classGroup->id)
        ->and($reservation->created_by)->toBe($keti->id)
        ->and($reservation->block->is_active)->toBeTrue()
        ->and(AuditLog::query()->where('action', 'reservation.created')->exists())->toBeTrue()
        ->and(AppNotification::query()->whereBelongsTo($keti)->where('title', 'Ruangan berhasil dipesan')->exists())->toBeTrue();
});

test('klik ulang dengan idempotency key yang sama tidak membuat reservasi ganda', function () {
    $keti = User::factory()->approvedKeti()->create();
    $room = Room::factory()->create();

    $first = reserveFor($keti, $room, key: 'kunci-sama');
    $second = reserveFor($keti, $room, key: 'kunci-sama');

    expect($second->id)->toBe($first->id)
        ->and(Reservation::query()->count())->toBe(1)
        ->and(RoomBlock::query()->count())->toBe(1);
});

test('dua kelas memesan slot yang sama: hanya yang pertama berhasil', function () {
    $room = Room::factory()->create();
    reserveFor(User::factory()->approvedKeti()->create(), $room);

    expectDomainError(fn () => reserveFor(User::factory()->approvedKeti()->create(), $room, '2026-11-04 10:30', '2026-11-04 11:30'), 'ROOM_CONFLICT');

    expect(Reservation::query()->count())->toBe(1);
});

test('reservasi yang bersambung (A selesai = B mulai) diperbolehkan', function () {
    $room = Room::factory()->create();
    reserveFor(User::factory()->approvedKeti()->create(), $room, '2026-11-04 10:00', '2026-11-04 11:00');

    $next = reserveFor(User::factory()->approvedKeti()->create(), $room, '2026-11-04 11:00', '2026-11-04 12:00');

    expect($next->exists)->toBeTrue();
});

test('kuliah resmi tanpa check-in tetap membuat ruang tidak bisa dipesan', function () {
    $room = Room::factory()->create();
    scheduleBlock($room, '2026-11-04 09:10', '2026-11-04 10:40');

    expectDomainError(fn () => reserveFor(User::factory()->approvedKeti()->create(), $room), 'ROOM_CONFLICT');
});

test('ruang yang kosong sekarang tetapi ada kuliah sebelum durasi selesai ditolak', function () {
    $room = Room::factory()->create();
    scheduleBlock($room, '2026-11-04 10:45', '2026-11-04 12:00');

    expectDomainError(fn () => reserveFor(User::factory()->approvedKeti()->create(), $room), 'ROOM_CONFLICT');
});

test('penutupan ruang menolak pemesanan', function () {
    $room = Room::factory()->create();
    RoomBlock::factory()->for($room)->create(['start_at' => '2026-11-04 08:00', 'end_at' => '2026-11-04 18:00', 'source_type' => RoomBlockSource::Closure]);

    expectDomainError(fn () => reserveFor(User::factory()->approvedKeti()->create(), $room), 'ROOM_CLOSED');
});

test('akun yang belum boleh memesan ditolak', function (Closure $makeUser, string $code) {
    expectDomainError(fn () => reserveFor($makeUser(), Room::factory()->create()), $code);
})->with([
    'Keti pending' => [fn () => User::factory()->pendingKeti()->create(), 'ACCOUNT_NOT_APPROVED'],
    'Keti email belum terverifikasi' => [fn () => User::factory()->unverified()->approvedKeti()->create(), 'ACCOUNT_NOT_APPROVED'],
    'admin' => [fn () => User::factory()->admin()->create(), 'NOT_CLASS_REPRESENTATIVE'],
]);

test('aturan waktu, durasi, dan kapasitas ditegakkan', function (string $start, string $end, ?int $participants, string $code) {
    $room = Room::factory()->create(['capacity' => 30]);

    expectDomainError(fn () => reserveFor(User::factory()->approvedKeti()->create(), $room, $start, $end, participants: $participants), $code);
})->with([
    'di luar jam operasional' => ['2026-11-04 21:00', '2026-11-04 22:00', 10, 'OUTSIDE_OPERATING_HOURS'],
    'durasi terlalu pendek' => ['2026-11-04 10:00', '2026-11-04 10:15', 10, 'INVALID_DURATION'],
    'durasi terlalu panjang' => ['2026-11-04 10:00', '2026-11-04 14:00', 10, 'INVALID_DURATION'],
    'waktu sudah lewat' => ['2026-11-04 08:00', '2026-11-04 09:00', 10, 'INVALID_TIME'],
    'terlalu jauh ke depan' => ['2026-11-20 10:00', '2026-11-20 11:00', 10, 'INVALID_TIME'],
    'melebihi kapasitas' => ['2026-11-04 10:00', '2026-11-04 11:00', 31, 'CAPACITY_EXCEEDED'],
]);

test('jumlah reservasi aktif per kelas dibatasi', function () {
    $keti = User::factory()->approvedKeti()->create();

    foreach ([10, 11, 12] as $hour) {
        reserveFor($keti, Room::factory()->create(), "2026-11-04 {$hour}:00", "2026-11-04 {$hour}:50");
    }

    expectDomainError(fn () => reserveFor($keti, Room::factory()->create(), '2026-11-04 14:00', '2026-11-04 15:00'), 'TOO_MANY_ACTIVE_RESERVATIONS');
});

test('satu kelas tidak bisa memesan dua ruang yang waktunya beririsan', function () {
    $keti = User::factory()->approvedKeti(ClassGroup::factory()->create(['name' => 'Tekom C']))->create();
    reserveFor($keti, Room::factory()->create(['name' => 'AE 107']), '2026-11-04 10:00', '2026-11-04 11:00');

    try {
        reserveFor($keti, Room::factory()->create(), '2026-11-04 10:30', '2026-11-04 11:30');
        test()->fail('CLASS_TIME_CONFLICT tidak dilempar.');
    } catch (DomainException $exception) {
        expect($exception->errorCode)->toBe('CLASS_TIME_CONFLICT')
            ->and($exception->getMessage())->toContain('Tekom C')->toContain('AE 107');
    }

    expect(Reservation::query()->count())->toBe(1)
        ->and(RoomBlock::query()->count())->toBe(1);
});

test('pesanan kelas yang bersambung, dibatalkan, atau milik kelas lain tidak dianggap bentrok', function () {
    $keti = User::factory()->approvedKeti()->create();
    $first = reserveFor($keti, Room::factory()->create(), '2026-11-04 10:00', '2026-11-04 11:00');

    $adjacent = reserveFor($keti, Room::factory()->create(), '2026-11-04 11:00', '2026-11-04 12:00');

    app(ReservationService::class)->cancel($first, $keti);
    $afterCancel = reserveFor($keti, Room::factory()->create(), '2026-11-04 10:00', '2026-11-04 11:00');

    $otherClass = reserveFor(User::factory()->approvedKeti()->create(), Room::factory()->create(), '2026-11-04 10:00', '2026-11-04 11:00');

    expect($adjacent->status)->toBe(ReservationStatus::Booked)
        ->and($afterCancel->status)->toBe(ReservationStatus::Booked)
        ->and($otherClass->status)->toBe(ReservationStatus::Booked);
});

test('perpanjangan ditolak bila beririsan dengan pesanan kelas sendiri di ruang lain', function () {
    $keti = User::factory()->approvedKeti()->create();
    $current = reserveFor($keti, Room::factory()->create(), '2026-11-04 10:00', '2026-11-04 11:00');
    reserveFor($keti, Room::factory()->create(), '2026-11-04 11:15', '2026-11-04 12:00');

    $this->service->start($current, $keti);

    expectDomainError(fn () => $this->service->extend($current, $keti), 'CLASS_TIME_CONFLICT');
    expect($current->fresh()->end_at->format('H:i'))->toBe('11:00');
});

test('mulai gunakan dua kali lalu selesai menghasilkan waktu aktual yang konsisten', function () {
    $keti = User::factory()->approvedKeti()->create();
    $reservation = reserveFor($keti, Room::factory()->create());

    $this->travel(5)->minutes();
    $started = $this->service->start($reservation, $keti);
    $this->travel(2)->minutes();
    $startedAgain = $this->service->start($reservation, $keti);

    $this->travel(30)->minutes();
    $finished = $this->service->finish($reservation, $keti);

    expect($startedAgain->actual_start_at->format('H:i'))->toBe('10:05')
        ->and($started->status)->toBe(ReservationStatus::InUse)
        ->and($finished->status)->toBe(ReservationStatus::Finished)
        ->and($finished->actual_end_at->format('H:i'))->toBe('10:37')
        ->and($finished->block->end_at->format('H:i'))->toBe('10:37');
});

test('mulai gunakan hanya dalam jendela check-in', function () {
    $keti = User::factory()->approvedKeti()->create();
    $reservation = reserveFor($keti, Room::factory()->create(), '2026-11-04 13:00', '2026-11-04 14:00');

    expectDomainError(fn () => $this->service->start($reservation, $keti), 'CHECKIN_WINDOW_CLOSED');

    $this->travelTo(CarbonImmutable::parse('2026-11-04 12:50'));
    expect($this->service->start($reservation, $keti)->status)->toBe(ReservationStatus::InUse);
});

test('transisi status yang tidak sah ditolak', function () {
    $keti = User::factory()->approvedKeti()->create();
    $reservation = reserveFor($keti, Room::factory()->create());

    expectDomainError(fn () => $this->service->finish($reservation, $keti), 'INVALID_STATUS_TRANSITION');

    $this->service->start($reservation, $keti);
    expectDomainError(fn () => $this->service->cancel($reservation, $keti), 'INVALID_STATUS_TRANSITION');
});

test('pembatalan membuka slot lagi dan riwayat tetap tersimpan', function () {
    $room = Room::factory()->create();
    $keti = User::factory()->approvedKeti()->create();
    $reservation = reserveFor($keti, $room);

    $cancelled = $this->service->cancel($reservation, $keti);
    $other = reserveFor(User::factory()->approvedKeti()->create(), $room);

    expect($cancelled->status)->toBe(ReservationStatus::Cancelled)
        ->and($cancelled->block->is_active)->toBeFalse()
        ->and($other->exists)->toBeTrue()
        ->and(Reservation::query()->count())->toBe(2)
        ->and(AuditLog::query()->where('action', 'reservation.cancelled')->exists())->toBeTrue();
});

test('admin wajib menulis alasan saat membatalkan dan Keti diberi tahu', function () {
    $keti = User::factory()->approvedKeti()->create();
    $admin = User::factory()->admin()->create();
    $reservation = reserveFor($keti, Room::factory()->create());

    expectDomainError(fn () => $this->service->cancel($reservation, $admin), 'DATA_INCOMPLETE');

    $this->service->cancel($reservation, $admin, 'Ruang dipakai kegiatan jurusan');

    expect($reservation->fresh()->cancel_reason)->toBe('Ruang dipakai kegiatan jurusan')
        ->and(AppNotification::query()->whereBelongsTo($keti)->where('type', 'reservation.cancelled')->exists())->toBeTrue();
});
