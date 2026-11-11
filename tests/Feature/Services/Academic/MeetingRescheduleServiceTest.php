<?php

use App\Enums\RescheduleStatus;
use App\Enums\ReservationStatus;
use App\Enums\RoomBlockSource;
use App\Enums\SessionPhase;
use App\Enums\TeachingRole;
use App\Models\Academic\CalendarException;
use App\Models\Academic\ClassGroup;
use App\Models\Academic\SessionReschedule;
use App\Models\Lecturer\LeadershipTerm;
use App\Models\Lecturer\Lecturer;
use App\Models\Reservation\Reservation;
use App\Models\Room\Room;
use App\Models\Room\RoomBlock;
use App\Models\Scheduling\ScheduleSession;
use App\Models\User\AppNotification;
use App\Models\User\AuditLog;
use App\Models\User\User;
use App\Services\Academic\MeetingRescheduleService;
use App\Services\Room\ReservationService;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00'));
    $this->classC = ClassGroup::factory()->create(['name' => 'Tekom C']);
    $this->keti = User::factory()->approvedKeti($this->classC)->create();
    $this->admin = User::factory()->admin()->create();
    $this->lead = Lecturer::factory()->create(['name' => 'Budi Santoso']);
    $this->partner = Lecturer::factory()->create(['name' => 'Sari Dewi']);
    $this->team = [
        [$this->lead, TeachingRole::Lead, SessionPhase::BeforeUts],
        [$this->partner, TeachingRole::Partner, SessionPhase::AfterUts],
    ];
    $this->ae107 = Room::factory()->create(['name' => 'AE 107']);
    $this->ae209 = Room::factory()->create(['name' => 'AE 209']);
    // Pemrograman Web – Tekom C, Senin 07:30–10:00 di AE 107.
    $this->session = meetingSession([$this->classC], ['title' => 'Pemrograman Web', 'room_id' => $this->ae107->id], $this->team);
    $this->service = app(MeetingRescheduleService::class);
});

/**
 * Pindahkan pertemuan Senin 9 Nov 07:30 (bawaan ke Selasa 10 Nov 10:00 di AE 209 dengan dosen mitra).
 */
function moveMeeting(array $overrides = []): SessionReschedule
{
    $args = [
        'keti' => test()->keti,
        'session' => test()->session,
        'originalDate' => CarbonImmutable::parse('2026-11-09'),
        'newStart' => CarbonImmutable::parse('2026-11-10 10:00'),
        'room' => test()->ae209,
        'lecturerIds' => [test()->partner->id],
        'idempotencyKey' => (string) str()->uuid(),
        ...$overrides,
    ];

    return app(MeetingRescheduleService::class)->move(...$args);
}

function expectNothingMoved(int $reschedules = 0): void
{
    expect(Reservation::query()->count())->toBe($reschedules)
        ->and(SessionReschedule::query()->count())->toBe($reschedules);
}

test('pertemuan dipindah menjadi reservasi Kuliah Pengganti dan ruang asli dilepas', function () {
    $reschedule = moveMeeting();
    $reservation = $reschedule->reservation;

    expect($reschedule->status)->toBe(RescheduleStatus::Active)
        ->and($reschedule->original_start_at->format('Y-m-d H:i'))->toBe('2026-11-09 07:30')
        ->and($reschedule->original_room_id)->toBe($this->ae107->id)
        ->and($reschedule->lecturers->pluck('id')->all())->toBe([$this->partner->id])
        ->and($reschedule->lecturers->first()->pivot->role)->toBe('partner')
        ->and($reservation->purpose)->toBe('Kuliah Pengganti')
        ->and($reservation->class_group_id)->toBe($this->classC->id)
        ->and($reservation->session_reschedule_id)->toBe($reschedule->id)
        ->and($reservation->start_at->format('Y-m-d H:i'))->toBe('2026-11-10 10:00')
        ->and($reservation->end_at->format('H:i'))->toBe('12:30')
        ->and($reservation->block->is_active)->toBeTrue();

    $original = RoomBlock::query()->where('source_type', RoomBlockSource::Schedule)->where('source_id', $this->session->id)
        ->where('start_at', '2026-11-09 07:30:00')->sole();
    $nextWeek = RoomBlock::query()->where('source_type', RoomBlockSource::Schedule)->where('source_id', $this->session->id)
        ->where('start_at', '2026-11-16 07:30:00')->sole();

    expect($original->is_active)->toBeFalse()
        ->and($nextWeek->is_active)->toBeTrue()
        ->and(AuditLog::query()->where('action', 'meeting.rescheduled')->exists())->toBeTrue()
        ->and(AppNotification::query()->whereBelongsTo($this->admin)->where('type', 'meeting.rescheduled')->exists())->toBeTrue();
});

test('Keti kelas gabungan mana pun bisa memindahkan dan Keti kelas lain diberi tahu', function () {
    $classA = ClassGroup::factory()->create(['name' => 'Tekom A']);
    $ketiA = User::factory()->approvedKeti($classA)->create();
    $joint = meetingSession([$this->classC, $classA], ['title' => 'Statistika', 'day_of_week' => 3, 'start_minute' => 780, 'end_minute' => 880, 'room_id' => $this->ae107->id], $this->team);

    $reschedule = moveMeeting(['keti' => $ketiA, 'session' => $joint, 'originalDate' => CarbonImmutable::parse('2026-11-11')]);

    expect($reschedule->reservation->class_group_id)->toBe($classA->id)
        ->and(AppNotification::query()->whereBelongsTo($this->keti)->where('title', 'Pertemuan dipindahkan')->exists())->toBeTrue()
        ->and(AppNotification::query()->whereBelongsTo($ketiA)->where('title', 'Pertemuan dipindahkan')->exists())->toBeFalse();
});

test('Keti yang kelasnya bukan peserta ditolak', function () {
    expectDomainCode(fn () => moveMeeting(['keti' => User::factory()->approvedKeti()->create()]), 'NOT_CLASS_REPRESENTATIVE');
    expectNothingMoved();
});

test('pertemuan yang tidak ada, sudah dipindah, atau sudah dimulai ditolak', function () {
    expectDomainCode(fn () => moveMeeting(['originalDate' => CarbonImmutable::parse('2026-11-10')]), 'MEETING_NOT_FOUND');
    expectNothingMoved();

    moveMeeting();
    expectDomainCode(fn () => moveMeeting(['newStart' => CarbonImmutable::parse('2026-11-11 10:00')]), 'MEETING_ALREADY_MOVED');
    expectNothingMoved(1);

    $this->travelTo(CarbonImmutable::parse('2026-11-16 08:00'));
    expectDomainCode(fn () => moveMeeting(['originalDate' => CarbonImmutable::parse('2026-11-16'), 'newStart' => CarbonImmutable::parse('2026-11-17 10:00')]), 'MEETING_STARTED');
    expectNothingMoved(1);
});

test('slot baru harus hari kuliah di semester, bukan libur dan bukan istirahat Jumat', function (string $newStart) {
    CalendarException::factory()->create(['period_id' => officialVersion()->period_id, 'date' => '2026-11-17']);

    expectDomainCode(fn () => moveMeeting(['newStart' => CarbonImmutable::parse($newStart)]), 'INVALID_TEACHING_DAY');
    expectNothingMoved();
})->with([
    'Sabtu' => '2026-11-14 10:00',
    'hari libur' => '2026-11-17 10:00',
    'di luar semester' => '2027-01-20 10:00',
    'istirahat Jumat' => '2026-11-13 11:30',
]);

test('slot baru harus dalam jam operasional dan belum lewat', function () {
    expectDomainCode(fn () => moveMeeting(['newStart' => CarbonImmutable::parse('2026-11-10 16:00')]), 'OUTSIDE_OPERATING_HOURS');
    expectDomainCode(fn () => moveMeeting(['newStart' => CarbonImmutable::parse('2026-11-04 08:00')]), 'INVALID_TIME');
    expectNothingMoved();
});

test('dosen terpilih wajib ada dan harus anggota tim pengajar', function () {
    expectDomainCode(fn () => moveMeeting(['lecturerIds' => []]), 'INVALID_LECTURER');
    expectDomainCode(fn () => moveMeeting(['lecturerIds' => [Lecturer::factory()->create()->id]]), 'INVALID_LECTURER');
    expectNothingMoved();
});

test('ruang tujuan yang terpakai ditolak', function () {
    scheduleBlock($this->ae209, '2026-11-10 10:00', '2026-11-10 12:00');

    expectDomainCode(fn () => moveMeeting(), 'ROOM_CONFLICT');
    expectNothingMoved();
});

test('kelas peserta yang sedang kuliah resmi di slot baru ditolak', function () {
    meetingSession([$this->classC], ['title' => 'Basis Data', 'day_of_week' => 2, 'start_minute' => 660, 'end_minute' => 750, 'room_id' => Room::factory()->create()->id]);

    expect(expectDomainCode(fn () => moveMeeting(), 'CLASS_TIME_CONFLICT')->getMessage())->toContain('Basis Data');
    expectNothingMoved();
});

test('kelas peserta yang punya pesanan di slot baru ditolak', function () {
    Reservation::factory()->create(['class_group_id' => $this->classC->id, 'start_at' => '2026-11-10 11:00', 'end_at' => '2026-11-10 12:00', 'status' => ReservationStatus::Booked]);

    expectDomainCode(fn () => moveMeeting(), 'CLASS_TIME_CONFLICT');
    expect(SessionReschedule::query()->count())->toBe(0);
});

test('dosen terpilih yang bentrok atau pimpinan di hari Kamis ditolak', function () {
    meetingSession([ClassGroup::factory()->create()], ['title' => 'Basis Data', 'day_of_week' => 2, 'start_minute' => 600, 'end_minute' => 750, 'room_id' => Room::factory()->create()->id], $this->team);

    expectDomainCode(fn () => moveMeeting(), 'LECTURER_CONFLICT');

    LeadershipTerm::factory()->create(['lecturer_id' => $this->partner->id]);
    expectDomainCode(fn () => moveMeeting(['newStart' => CarbonImmutable::parse('2026-11-12 10:00')]), 'LEADERSHIP_THURSDAY');
    expectNothingMoved();
});

test('pemindahan tidak dibatasi 7 hari ke depan maupun jumlah reservasi aktif kelas', function () {
    Reservation::factory()->count(3)->sequence(
        ['start_at' => '2026-11-05 08:00', 'end_at' => '2026-11-05 09:00'],
        ['start_at' => '2026-11-05 10:00', 'end_at' => '2026-11-05 11:00'],
        ['start_at' => '2026-11-05 13:00', 'end_at' => '2026-11-05 14:00'],
    )->create(['class_group_id' => $this->classC->id, 'status' => ReservationStatus::Booked]);

    $reschedule = moveMeeting(['newStart' => CarbonImmutable::parse('2026-11-30 10:00')]);

    expect($reschedule->reservation->start_at->format('Y-m-d H:i'))->toBe('2026-11-30 10:00');
});

test('pertemuan bisa dipindah ke ruang lain pada jam yang sama', function () {
    $reschedule = moveMeeting(['newStart' => CarbonImmutable::parse('2026-11-09 07:30')]);

    expect($reschedule->reservation->room_id)->toBe($this->ae209->id)
        ->and($reschedule->reservation->start_at->format('Y-m-d H:i'))->toBe('2026-11-09 07:30');
});

test('kirim ulang dengan kunci idempotensi yang sama tidak menggandakan pemindahan', function () {
    $key = (string) str()->uuid();

    $first = moveMeeting(['idempotencyKey' => $key]);
    $second = moveMeeting(['idempotencyKey' => $key]);

    expect($second->id)->toBe($first->id);
    expectNothingMoved(1);
});

function originalBlock(): RoomBlock
{
    return RoomBlock::query()->where('source_type', RoomBlockSource::Schedule)->where('source_id', test()->session->id)
        ->where('start_at', '2026-11-09 07:30:00')->sole();
}

test('Keti membatalkan pemindahan: pertemuan kembali ke jam asli', function () {
    $reschedule = moveMeeting();

    $cancelled = $this->service->cancel($reschedule->reservation, $this->keti);

    expect($cancelled->status)->toBe(ReservationStatus::Cancelled)
        ->and($cancelled->block->is_active)->toBeFalse()
        ->and($reschedule->fresh()->status)->toBe(RescheduleStatus::Cancelled)
        ->and($reschedule->fresh()->cancelled_by)->toBe($this->keti->id)
        ->and(originalBlock()->is_active)->toBeTrue()
        ->and(AuditLog::query()->where('action', 'meeting.reschedule_cancelled')->exists())->toBeTrue()
        ->and(AppNotification::query()->whereBelongsTo($this->admin)->where('type', 'meeting.reschedule_cancelled')->exists())->toBeTrue();
});

test('pembatalan lewat ReservationService juga mengembalikan pertemuan ke jam asli', function () {
    $reschedule = moveMeeting();

    app(ReservationService::class)->cancel($reschedule->reservation, $this->keti);

    expect($reschedule->fresh()->status)->toBe(RescheduleStatus::Cancelled)
        ->and(originalBlock()->is_active)->toBeTrue();
});

test('admin wajib memberi alasan dan Keti pemindah diberi tahu', function () {
    $reschedule = moveMeeting();

    expectDomainCode(fn () => app(ReservationService::class)->cancel($reschedule->reservation, $this->admin), 'DATA_INCOMPLETE');

    app(ReservationService::class)->cancel($reschedule->reservation, $this->admin, 'Ruang dipakai rapat jurusan');

    expect(originalBlock()->is_active)->toBeTrue()
        ->and(AppNotification::query()->whereBelongsTo($this->keti)->where('type', 'meeting.reschedule_cancelled')->exists())->toBeTrue();
});

test('pemindahan yang penggantinya sudah dipakai tidak bisa dibatalkan', function () {
    $reschedule = moveMeeting(['newStart' => CarbonImmutable::parse('2026-11-04 13:00')]);
    $this->travelTo(CarbonImmutable::parse('2026-11-04 13:05'));
    app(ReservationService::class)->start($reschedule->reservation, $this->keti);

    expectDomainCode(fn () => $this->service->cancel($reschedule->reservation->fresh(), $this->keti), 'INVALID_STATUS_TRANSITION');
    expect($reschedule->fresh()->status)->toBe(RescheduleStatus::Active);
});

test('pembatalan ditolak bila ruang asli sudah dipesan kelas lain', function () {
    $reschedule = moveMeeting();
    app(ReservationService::class)->create(User::factory()->approvedKeti()->create(), $this->ae107, CarbonImmutable::parse('2026-11-09 07:30'), CarbonImmutable::parse('2026-11-09 09:10'), 'Rapat', null, (string) str()->uuid());

    expectDomainCode(fn () => $this->service->cancel($reschedule->reservation, $this->keti), 'RESTORE_NOT_POSSIBLE');

    expect($reschedule->fresh()->status)->toBe(RescheduleStatus::Active)
        ->and($reschedule->reservation->fresh()->status)->toBe(ReservationStatus::Booked)
        ->and(originalBlock()->is_active)->toBeFalse();
});

test('pembatalan ditolak bila jam asli sudah lewat', function () {
    $reschedule = moveMeeting(['newStart' => CarbonImmutable::parse('2026-11-11 10:00')]);
    $this->travelTo(CarbonImmutable::parse('2026-11-09 08:00'));

    expectDomainCode(fn () => $this->service->cancel($reschedule->reservation, $this->keti), 'RESTORE_NOT_POSSIBLE');
});

test('Pindahkan lagi mengganti pengganti lama walau jam asli sudah lewat', function () {
    $old = moveMeeting(['newStart' => CarbonImmutable::parse('2026-11-11 10:00')]);
    $this->travelTo(CarbonImmutable::parse('2026-11-09 08:00'));

    $new = $this->service->moveAgain($this->keti, $old->reservation, CarbonImmutable::parse('2026-11-12 13:00'), $this->ae209, [$this->partner->id], (string) str()->uuid());

    expect($old->fresh()->status)->toBe(RescheduleStatus::Replaced)
        ->and($old->reservation->fresh()->status)->toBe(ReservationStatus::Cancelled)
        ->and($new->status)->toBe(RescheduleStatus::Active)
        ->and($new->original_start_at->format('Y-m-d H:i'))->toBe('2026-11-09 07:30')
        ->and($new->reservation->start_at->format('Y-m-d H:i'))->toBe('2026-11-12 13:00')
        ->and(originalBlock()->is_active)->toBeFalse();
});

test('Pindahkan lagi boleh memakai slot yang beririsan dengan pengganti lama', function () {
    $old = moveMeeting();

    $new = $this->service->moveAgain($this->keti, $old->reservation, CarbonImmutable::parse('2026-11-10 11:00'), $this->ae209, [$this->partner->id], (string) str()->uuid());

    expect($new->reservation->start_at->format('H:i'))->toBe('11:00');
});

test('Pindahkan lagi untuk reservasi biasa ditolak', function () {
    $reservation = app(ReservationService::class)->create($this->keti, $this->ae209, CarbonImmutable::parse('2026-11-05 10:00'), CarbonImmutable::parse('2026-11-05 11:00'), 'Rapat', null, (string) str()->uuid());

    expectDomainCode(fn () => $this->service->moveAgain($this->keti, $reservation, CarbonImmutable::parse('2026-11-12 13:00'), $this->ae209, [$this->partner->id], (string) str()->uuid()), 'MEETING_NOT_FOUND');
});

test('reservasi lama berkegiatan Kuliah Pengganti tanpa pemindahan dibatalkan lewat alur biasa', function () {
    $legacy = app(ReservationService::class)->create($this->keti, $this->ae209, CarbonImmutable::parse('2026-11-05 10:00'), CarbonImmutable::parse('2026-11-05 11:00'), 'Kuliah Pengganti', null, (string) str()->uuid());

    $cancelled = app(ReservationService::class)->cancel($legacy, $this->keti);

    expect($cancelled->status)->toBe(ReservationStatus::Cancelled)
        ->and($cancelled->block->is_active)->toBeFalse()
        ->and(SessionReschedule::query()->count())->toBe(0);
});

/*
| Temuan review akhir.
*/

/**
 * Sesi gabungan Statistika (Tekom C + Tekom A), Rabu 13:00–14:40, beserta Keti Tekom A.
 *
 * @return array{0: ScheduleSession, 1: User, 2: ClassGroup}
 */
function jointStatistics(): array
{
    $classA = ClassGroup::factory()->create(['name' => 'Tekom A']);
    $ketiA = User::factory()->approvedKeti($classA)->create();
    $joint = meetingSession([test()->classC, $classA], ['title' => 'Statistika', 'day_of_week' => 3, 'start_minute' => 780, 'end_minute' => 880, 'room_id' => test()->ae107->id], test()->team);

    return [$joint, $ketiA, $classA];
}

test('pengganti kelas gabungan memblokir kelas peserta lain', function () {
    [$joint, $ketiA, $classA] = jointStatistics();
    moveMeeting(['session' => $joint, 'originalDate' => CarbonImmutable::parse('2026-11-11'), 'newStart' => CarbonImmutable::parse('2026-11-10 13:00')]);
    $onlyA = meetingSession([$classA], ['title' => 'Basis Data', 'day_of_week' => 4, 'start_minute' => 780, 'end_minute' => 880, 'room_id' => Room::factory()->create()->id], [[Lecturer::factory()->create(), TeachingRole::Lead, SessionPhase::Full]]);

    expectDomainCode(fn () => moveMeeting(['keti' => $ketiA, 'session' => $onlyA->fresh(['participants', 'lecturers']), 'originalDate' => CarbonImmutable::parse('2026-11-12'), 'newStart' => CarbonImmutable::parse('2026-11-10 13:00'), 'room' => Room::factory()->create(), 'lecturerIds' => [$onlyA->lecturers->first()->id]]), 'CLASS_TIME_CONFLICT');
    expectDomainCode(fn () => app(ReservationService::class)->create($ketiA, Room::factory()->create(), CarbonImmutable::parse('2026-11-10 13:30'), CarbonImmutable::parse('2026-11-10 14:30'), 'Rapat', null, (string) str()->uuid()), 'CLASS_TIME_CONFLICT');
});

test('Keti kelas gabungan lain tidak bisa memindahkan lagi pengganti milik kelas lain', function () {
    [$joint, $ketiA] = jointStatistics();
    $reschedule = moveMeeting(['session' => $joint, 'originalDate' => CarbonImmutable::parse('2026-11-11'), 'newStart' => CarbonImmutable::parse('2026-11-10 13:00')]);

    expectDomainCode(fn () => $this->service->moveAgain($ketiA, $reschedule->reservation, CarbonImmutable::parse('2026-11-12 13:00'), $this->ae209, [$this->partner->id], (string) str()->uuid()), 'NOT_CLASS_REPRESENTATIVE');

    expect($reschedule->fresh()->status)->toBe(RescheduleStatus::Active)
        ->and($reschedule->reservation->fresh()->status)->toBe(ReservationStatus::Booked);
});

test('batal pemindahan setelah jadwal ditetapkan ulang dengan ruang baru mengaktifkan blok ruang baru', function () {
    $reschedule = moveMeeting();
    $ae300 = Room::factory()->create(['name' => 'AE 300']);
    $version = republishSchedule(fn ($draft) => $draft->sessions()->where('course_offering_id', $this->session->course_offering_id)->update(['room_id' => $ae300->id]));

    $this->service->cancel($reschedule->reservation, $this->keti);

    $restored = RoomBlock::query()->whereBelongsTo($version, 'scheduleVersion')->where('source_type', RoomBlockSource::Schedule)
        ->where('start_at', '2026-11-09 07:30:00')->sole();
    expect($restored->room_id)->toBe($ae300->id)
        ->and($restored->is_active)->toBeTrue()
        ->and($reschedule->fresh()->status)->toBe(RescheduleStatus::Cancelled);
});

test('admin tetap bisa membatalkan pengganti walau jam asli sudah lewat', function () {
    $reschedule = moveMeeting(['newStart' => CarbonImmutable::parse('2026-11-11 10:00')]);
    $this->travelTo(CarbonImmutable::parse('2026-11-09 08:00'));

    app(ReservationService::class)->cancel($reschedule->reservation, $this->admin, 'Dosen berhalangan');

    expect($reschedule->reservation->fresh()->status)->toBe(ReservationStatus::Cancelled)
        ->and($reschedule->reservation->fresh()->block->is_active)->toBeFalse()
        ->and(originalBlock()->is_active)->toBeFalse()
        ->and(AppNotification::query()->whereBelongsTo($this->keti)->where('type', 'meeting.reschedule_cancelled')->exists())->toBeTrue();
});

test('pertemuan tidak dikembalikan bila dosennya sudah mengajar pertemuan pindahan lain di jam asli', function () {
    $reschedule = moveMeeting();
    $classX = ClassGroup::factory()->create(['name' => 'Tekom X']);
    $other = meetingSession([$classX], ['title' => 'Jaringan', 'day_of_week' => 2, 'start_minute' => 780, 'end_minute' => 930, 'room_id' => Room::factory()->create()->id], $this->team);
    moveMeeting(['keti' => User::factory()->approvedKeti($classX)->create(), 'session' => $other, 'originalDate' => CarbonImmutable::parse('2026-11-10'), 'newStart' => CarbonImmutable::parse('2026-11-09 07:30'), 'room' => Room::factory()->create()]);

    $exception = expectDomainCode(fn () => $this->service->cancel($reschedule->reservation, $this->keti), 'RESTORE_NOT_POSSIBLE');

    expect($exception->getMessage())->toContain('Sari Dewi')
        ->and($reschedule->fresh()->status)->toBe(RescheduleStatus::Active);
});
