<?php

use App\Enums\RescheduleStatus;
use App\Enums\ReservationStatus;
use App\Enums\RoomBlockSource;
use App\Enums\SessionPhase;
use App\Enums\TeachingRole;
use App\Exceptions\DomainException;
use App\Models\Academic\ClassGroup;
use App\Models\Lecturer\Lecturer;
use App\Models\Reservation\Reservation;
use App\Models\Room\Room;
use App\Models\Room\RoomBlock;
use App\Models\Room\RoomClosure;
use App\Models\User\AppNotification;
use App\Models\User\AuditLog;
use App\Models\User\User;
use App\Services\Academic\MeetingRescheduleService;
use App\Services\Room\OverdueReservationService;
use App\Services\Room\ReservationService;
use App\Services\Room\RoomClosureService;
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

test('reservasi tidak boleh diperpanjang jika batas waktu sudah lewat', function () {
    $keti = User::factory()->approvedKeti()->create();
    $room = Room::factory()->create();
    $reservation = reserveFor($keti, $room, '2026-11-04 10:00', '2026-11-04 11:00');
    $this->service->start($reservation, $keti);

    $this->travelTo(CarbonImmutable::parse('2026-11-04 11:05'));
    expectDomainError(fn () => $this->service->extend($reservation->fresh(), $keti), 'INVALID_TIME');
});

test('Keti tidak bisa memesan ruang saat kelasnya sedang ada jadwal kuliah resmi', function () {
    $class = ClassGroup::factory()->create();
    $keti = User::factory()->approvedKeti($class)->create();
    $room = Room::factory()->create();
    meetingSession([$class], ['title' => 'Struktur Data', 'day_of_week' => 3, 'start_minute' => 600, 'end_minute' => 700, 'room_id' => Room::factory()->create()->id]);

    expectDomainError(fn () => reserveFor($keti, $room, '2026-11-04 10:00', '2026-11-04 11:40'), 'CLASS_TIME_CONFLICT');
});

test('kelas pasangan dalam kelas gabungan terikat jadwal pengganti di SessionReschedule', function () {
    $classA = ClassGroup::factory()->create(['name' => 'Tekom A']);
    $classB = ClassGroup::factory()->create(['name' => 'Tekom B']);
    $ketiA = User::factory()->approvedKeti($classA)->create();
    $ketiB = User::factory()->approvedKeti($classB)->create();
    $room1 = Room::factory()->create();
    $room2 = Room::factory()->create();

    $lecturer = Lecturer::factory()->create();
    $team = [[$lecturer, TeachingRole::Lead, SessionPhase::Full]];
    $joint = meetingSession([$classA, $classB], ['title' => 'Jaringan Komputer', 'day_of_week' => 5, 'start_minute' => 450, 'end_minute' => 550, 'room_id' => $room1->id], $team);
    app(MeetingRescheduleService::class)->move($ketiA, $joint, CarbonImmutable::parse('2026-11-06'), CarbonImmutable::parse('2026-11-05 10:00'), $room1, [$lecturer->id], (string) str()->uuid());

    expectDomainError(fn () => reserveFor($ketiB, $room2, '2026-11-05 10:00', '2026-11-05 11:40'), 'CLASS_TIME_CONFLICT');
});

test('pembatalan Kuliah Pengganti di jam yang sama dengan jadwal asli tetap berhasil', function () {
    $classGroup = ClassGroup::factory()->create(['name' => 'Tekom C']);
    $keti = User::factory()->approvedKeti($classGroup)->create();
    $lecturer = Lecturer::factory()->create();
    $ae107 = Room::factory()->create(['name' => 'AE 107']);
    $ae209 = Room::factory()->create(['name' => 'AE 209']);

    $session = meetingSession(
        [$classGroup],
        ['title' => 'Pemrograman Web', 'day_of_week' => 5, 'start_minute' => 450, 'end_minute' => 550, 'room_id' => $ae107->id],
        [[$lecturer, TeachingRole::Lead, SessionPhase::Full]],
    );

    $rescheduleService = app(MeetingRescheduleService::class);
    $reschedule = $rescheduleService->move(
        $keti,
        $session,
        CarbonImmutable::parse('2026-11-06'),
        CarbonImmutable::parse('2026-11-06 07:30'),
        $ae209,
        [$lecturer->id],
        (string) str()->uuid(),
    );

    $cancelled = $rescheduleService->cancel($reschedule->reservation, $keti);

    $originalBlock = RoomBlock::query()
        ->where('source_type', RoomBlockSource::Schedule)
        ->where('source_id', $session->id)
        ->where('start_at', '2026-11-06 07:30:00')
        ->sole();

    expect($cancelled->status)->toBe(ReservationStatus::Cancelled)
        ->and($cancelled->block->is_active)->toBeFalse()
        ->and($reschedule->fresh()->status)->toBe(RescheduleStatus::Cancelled)
        ->and($originalBlock->is_active)->toBeTrue();
});

test('perpanjangan ditolak jika tepat di end_at kelas memiliki kuliah resmi', function () {
    $class = ClassGroup::factory()->create(['name' => 'Tekom C']);
    $keti = User::factory()->approvedKeti($class)->create();
    $room1 = Room::factory()->create(['name' => 'AE 107']);
    $room2 = Room::factory()->create(['name' => 'AE 209']);

    meetingSession([$class], ['title' => 'Basis Data', 'day_of_week' => 3, 'start_minute' => 660, 'end_minute' => 760, 'room_id' => $room2->id]);

    $reservation = reserveFor($keti, $room1, '2026-11-04 10:00', '2026-11-04 11:00');
    $this->service->start($reservation, $keti);

    expectDomainError(fn () => $this->service->extend($reservation->fresh(), $keti), 'CLASS_TIME_CONFLICT');
    expect($reservation->fresh()->end_at->format('H:i'))->toBe('11:00')
        ->and($reservation->fresh()->block->end_at->format('H:i'))->toBe('11:00');
});

test('perpanjangan Kuliah Pengganti mengabaikan sesi aslinya dan memperbarui target_end_at', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 08:00'));

    $class = ClassGroup::factory()->create(['name' => 'Tekom C']);
    $keti = User::factory()->approvedKeti($class)->create();
    $lecturer = Lecturer::factory()->create();
    $room1 = Room::factory()->create(['name' => 'AE 107']);
    $room2 = Room::factory()->create(['name' => 'AE 209']);

    $session = meetingSession(
        [$class],
        ['title' => 'Pemrograman Web', 'day_of_week' => 3, 'start_minute' => 660, 'end_minute' => 760, 'room_id' => $room1->id],
        [[$lecturer, TeachingRole::Lead, SessionPhase::Full]],
    );

    $reschedule = app(MeetingRescheduleService::class)->move(
        $keti,
        $session,
        CarbonImmutable::parse('2026-11-04'),
        CarbonImmutable::parse('2026-11-04 09:00'),
        $room2,
        [$lecturer->id],
        (string) str()->uuid(),
    );

    $this->travelTo(CarbonImmutable::parse('2026-11-04 09:05'));
    $this->service->start($reschedule->reservation, $keti);
    $extended = $this->service->extend($reschedule->reservation->fresh(), $keti);

    expect($extended->end_at->format('H:i'))->toBe('11:10')
        ->and($extended->block->end_at->format('H:i'))->toBe('11:10')
        ->and($reschedule->fresh()->target_end_at->format('H:i'))->toBe('11:10');
});

test('kedaluwarsa otomatis tidak membatalkan di batas tepat 15 menit dan tidak mengaktifkan blok asli jika sudah terpakai', function () {
    config(['sicma.reservation.auto_expire_no_show' => true]);

    $class = ClassGroup::factory()->create();
    $keti = User::factory()->approvedKeti($class)->create();
    $lecturer = Lecturer::factory()->create();
    $originalRoom = Room::factory()->create(['name' => 'AE 107']);
    $targetRoom = Room::factory()->create(['name' => 'AE 209']);

    $this->travelTo(CarbonImmutable::parse('2026-11-04 09:45'));
    $boundaryBooking = reserveFor(User::factory()->approvedKeti()->create(), Room::factory()->create(), '2026-11-04 09:45', '2026-11-04 11:00');

    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00'));
    expect(app(OverdueReservationService::class)->flag()['expired'])->toBe(0)
        ->and($boundaryBooking->fresh()->status)->toBe(ReservationStatus::Booked);

    $session = meetingSession(
        [$class],
        ['title' => 'Algoritma', 'day_of_week' => 5, 'start_minute' => 450, 'end_minute' => 550, 'room_id' => $originalRoom->id],
        [[$lecturer, TeachingRole::Lead, SessionPhase::Full]],
    );

    $reschedule = app(MeetingRescheduleService::class)->move(
        $keti,
        $session,
        CarbonImmutable::parse('2026-11-06'),
        CarbonImmutable::parse('2026-11-04 10:00'),
        $targetRoom,
        [$lecturer->id],
        (string) str()->uuid(),
    );

    // Kelas lain memesan ruang asli di slot asli sebelum pengganti kedaluwarsa.
    reserveFor(User::factory()->approvedKeti()->create(), $originalRoom, '2026-11-06 07:30', '2026-11-06 09:10');

    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:16'));
    app(OverdueReservationService::class)->flag();

    $originalBlock = RoomBlock::query()
        ->where('source_type', RoomBlockSource::Schedule)
        ->where('source_id', $session->id)
        ->where('start_at', '2026-11-06 07:30:00')
        ->sole();

    expect($reschedule->reservation->fresh()->status)->toBe(ReservationStatus::Cancelled)
        ->and($reschedule->fresh()->status)->toBe(RescheduleStatus::Cancelled)
        ->and($originalBlock->is_active)->toBeFalse();
});

test('penutupan ruang dengan cancel_impacted menyelesaikan reservasi aktif dan membatalkan Kuliah Pengganti yang jam aslinya sudah lewat', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 08:00'));

    $admin = User::factory()->admin()->create();
    $class = ClassGroup::factory()->create();
    $keti = User::factory()->approvedKeti($class)->create();
    $lecturer = Lecturer::factory()->create();
    $room1 = Room::factory()->create(['name' => 'AE 107']);
    $room2 = Room::factory()->create(['name' => 'AE 209']);

    // 1. Reservasi sedang digunakan di AE 107
    $activeBooking = reserveFor($keti, $room1, '2026-11-04 08:00', '2026-11-04 09:00');
    $this->service->start($activeBooking, $keti);

    app(RoomClosureService::class)->create(
        $room1,
        CarbonImmutable::parse('2026-11-04 08:00'),
        CarbonImmutable::parse('2026-11-04 12:00'),
        'Perbaikan listrik',
        $admin,
        cancelImpacted: true,
    );

    expect($activeBooking->fresh()->status)->toBe(ReservationStatus::Finished);

    // 2. Kuliah Pengganti ke AE 209 yang jam aslinya sudah lewat saat AE 209 ditutup
    $session = meetingSession(
        [$class],
        ['title' => 'Basis Data', 'day_of_week' => 3, 'start_minute' => 600, 'end_minute' => 700, 'room_id' => Room::factory()->create()->id],
        [[$lecturer, TeachingRole::Lead, SessionPhase::Full]],
    );

    $reschedule = app(MeetingRescheduleService::class)->move(
        $keti,
        $session,
        CarbonImmutable::parse('2026-11-04'),
        CarbonImmutable::parse('2026-11-06 10:00'),
        $room2,
        [$lecturer->id],
        (string) str()->uuid(),
    );

    $this->travelTo(CarbonImmutable::parse('2026-11-04 12:00'));

    app(RoomClosureService::class)->create(
        $room2,
        CarbonImmutable::parse('2026-11-06 09:00'),
        CarbonImmutable::parse('2026-11-06 13:00'),
        'Pemeliharaan AC',
        $admin,
        cancelImpacted: true,
    );

    $originalBlock = RoomBlock::query()
        ->where('source_type', RoomBlockSource::Schedule)
        ->where('source_id', $session->id)
        ->where('start_at', '2026-11-04 10:00:00')
        ->sole();

    expect(RoomClosure::query()->whereBelongsTo($room2)->exists())->toBeTrue()
        ->and($reschedule->reservation->fresh()->status)->toBe(ReservationStatus::Cancelled)
        ->and($reschedule->fresh()->status)->toBe(RescheduleStatus::Cancelled)
        ->and($originalBlock->is_active)->toBeFalse();
});
