<?php

use App\Enums\RoomBlockSource;
use App\Enums\ScheduleSource;
use App\Enums\ScheduleVersionStatus;
use App\Exceptions\DomainException;
use App\Models\Academic\ClassGroup;
use App\Models\Academic\CourseOffering;
use App\Models\Academic\Period;
use App\Models\Academic\SessionReschedule;
use App\Models\Lecturer\LeadershipTerm;
use App\Models\Lecturer\Lecturer;
use App\Models\Reservation\Reservation;
use App\Models\Room\Room;
use App\Models\Room\RoomBlock;
use App\Models\Scheduling\ScheduleSession;
use App\Models\Scheduling\ScheduleVersion;
use App\Models\User\AppNotification;
use App\Models\User\AuditLog;
use App\Models\User\User;
use App\Services\Room\AvailabilityService;
use App\Services\Room\ReservationService;
use App\Services\Scheduling\ScheduleBlockBuilder;
use App\Services\Scheduling\ScheduleDraftService;
use App\Services\Scheduling\SchedulePublisher;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00'));
    $this->period = Period::factory()->create();
    $this->room = Room::factory()->create(['name' => 'AE 107']);
    $this->other = Room::factory()->create(['name' => 'AE 106']);
    $this->classGroup = ClassGroup::factory()->create(['name' => 'Tekom C']);

    $this->official = ScheduleVersion::factory()->official()->for($this->period)->create(['version_no' => 2, 'effective_from' => '2026-09-01']);
    $session = ScheduleSession::factory()->for($this->official, 'version')->create(['room_id' => $this->room->id, 'day_of_week' => 3, 'start_minute' => 600, 'end_minute' => 750]);
    $session->participants()->sync([$this->classGroup->id]);
    app(ScheduleBlockBuilder::class)->rebuild($this->official);

    $this->publisher = User::factory()->superAdmin()->create();
    $this->draft = app(ScheduleDraftService::class)->create($this->period, ScheduleSource::Manual, $this->official);
});

test('draf tidak memengaruhi status ruang sebelum ditetapkan', function () {
    $this->draft->sessions()->first()->update(['room_id' => $this->other->id]);

    $availability = app(AvailabilityService::class);

    expect($availability->forRoom($this->room, CarbonImmutable::parse('2026-11-11 10:30'))->isAvailable())->toBeFalse()
        ->and($availability->forRoom($this->other, CarbonImmutable::parse('2026-11-11 10:30'))->isAvailable())->toBeTrue();
});

test('menetapkan draf memindahkan blok ruang mulai tanggal berlaku dan menjaga riwayat', function () {
    $this->draft->sessions()->first()->update(['room_id' => $this->other->id]);

    app(SchedulePublisher::class)->publish($this->draft, $this->publisher, CarbonImmutable::parse('2026-11-09'), $this->draft->lock_version, 'Pindah ruang');

    $availability = app(AvailabilityService::class);

    expect($this->draft->fresh()->status)->toBe(ScheduleVersionStatus::Official)
        ->and($this->official->fresh()->status)->toBe(ScheduleVersionStatus::Superseded)
        ->and($this->official->fresh()->effective_to->toDateString())->toBe('2026-11-08')
        // Rabu 4 Nov masih memakai jadwal lama, Rabu 11 Nov memakai jadwal baru.
        ->and($availability->forRoom($this->room, CarbonImmutable::parse('2026-11-04 10:30'))->isAvailable())->toBeFalse()
        ->and($availability->forRoom($this->room, CarbonImmutable::parse('2026-11-11 10:30'))->isAvailable())->toBeTrue()
        ->and($availability->forRoom($this->other, CarbonImmutable::parse('2026-11-11 10:30'))->isAvailable())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'schedule.published')->value('reason'))->toBe('Pindah ruang');
});

test('Keti kelas terdampak diberi tahu setelah penetapan', function () {
    $keti = User::factory()->approvedKeti($this->classGroup)->create();

    app(SchedulePublisher::class)->publish($this->draft, $this->publisher, now(), $this->draft->lock_version);

    expect(AppNotification::query()->where('user_id', $keti->id)->where('type', 'schedule.published')->exists())->toBeTrue();
});

test('admin tanpa izin publish-schedule tidak bisa menetapkan', function () {
    $admin = User::factory()->admin()->create();

    expect(fn () => app(SchedulePublisher::class)->publish($this->draft, $admin, now(), $this->draft->lock_version))
        ->toThrow(DomainException::class);

    expect($this->draft->fresh()->status)->toBe(ScheduleVersionStatus::Draft);
});

test('draf dengan masalah wajib tidak bisa ditetapkan', function () {
    $this->draft->sessions()->first()->update(['day_of_week' => 4]);
    $leader = Lecturer::factory()->create();
    LeadershipTerm::factory()->for($leader)->create();
    $this->draft->sessions()->first()->lecturers()->sync([$leader->id => ['role' => 'lead']]);

    try {
        app(SchedulePublisher::class)->publish($this->draft, $this->publisher, now(), $this->draft->lock_version);
        $this->fail('Penetapan seharusnya ditolak.');
    } catch (DomainException $exception) {
        expect($exception->errorCode)->toBe('DRAFT_HAS_ISSUES');
    }

    expect($this->official->fresh()->status)->toBe(ScheduleVersionStatus::Official)
        ->and($this->draft->issues()->blocking()->where('code', 'LEADERSHIP_THURSDAY')->exists())->toBeTrue();
});

test('reservasi yang masuk setelah pratinjau membuat penetapan ditolak tanpa membatalkannya', function () {
    $this->draft->sessions()->first()->update(['room_id' => $this->other->id]);
    $reservation = Reservation::factory()->for($this->other)->create(['start_at' => '2026-11-11 10:00', 'end_at' => '2026-11-11 11:00']);
    RoomBlock::factory()->for($this->other)->create(['start_at' => '2026-11-11 10:00', 'end_at' => '2026-11-11 11:00', 'source_type' => RoomBlockSource::Reservation, 'source_id' => $reservation->id]);

    expect(fn () => app(SchedulePublisher::class)->publish($this->draft, $this->publisher, CarbonImmutable::parse('2026-11-09'), $this->draft->lock_version))
        ->toThrow(DomainException::class, 'Selesaikan 1 masalah wajib dulu.');

    expect($reservation->fresh()->status->value)->toBe('booked')
        ->and($this->draft->fresh()->status)->toBe(ScheduleVersionStatus::Draft);
});

test('draf yang berubah sejak ditinjau ditolak dengan VERSION_CONFLICT', function () {
    $reviewedLockVersion = $this->draft->lock_version;
    app(ScheduleDraftService::class)->toggleLock($this->draft->sessions()->first());

    try {
        app(SchedulePublisher::class)->publish($this->draft->fresh(), $this->publisher, now(), $reviewedLockVersion);
        $this->fail('Penetapan seharusnya ditolak.');
    } catch (DomainException $exception) {
        expect($exception->errorCode)->toBe('VERSION_CONFLICT');
    }
});

test('route tetapkan hanya untuk admin dengan izin dan menampilkan halaman berhasil', function () {
    $admin = User::factory()->admin()->create();
    $payload = ['effective_from' => '2026-11-04', 'lock_version' => $this->draft->lock_version];

    $this->actingAs($admin)->post(route('admin.drafts.publish', $this->draft), $payload)->assertForbidden();

    $this->actingAs($this->publisher)
        ->post(route('admin.drafts.publish', $this->draft), $payload)
        ->assertRedirect(route('admin.drafts.published', $this->draft));

    $this->withoutVite()->get(route('admin.drafts.published', $this->draft))->assertOk()->assertSee('Jadwal resmi berhasil diperbarui');
});

/**
 * Pertemuan Rabu 11 Nov 10:00 (sesi resmi di AE 107) yang sudah dipindah Keti.
 */
function movedWednesdayMeeting(array $attributes = []): SessionReschedule
{
    $session = test()->official->sessions()->first();

    RoomBlock::query()->where('source_type', RoomBlockSource::Schedule)->where('source_id', $session->id)
        ->where('start_at', '2026-11-11 10:00:00')->update(['is_active' => false]);

    return SessionReschedule::factory()->create([
        'schedule_session_id' => $session->id,
        'session_title' => $session->title,
        'participant_key' => SessionReschedule::participantKey([test()->classGroup->id]),
        'original_start_at' => '2026-11-11 10:00',
        'original_end_at' => '2026-11-11 12:30',
        'original_room_id' => test()->room->id,
        ...$attributes,
    ]);
}

function draftBlockOn(string $start): RoomBlock
{
    return RoomBlock::query()->whereBelongsTo(test()->draft, 'scheduleVersion')->where('source_type', RoomBlockSource::Schedule)
        ->where('start_at', $start)->sole();
}

test('pemindahan pertemuan tetap berlaku setelah jadwal ditetapkan ulang (penawaran mata kuliah sama)', function () {
    $offering = CourseOffering::factory()->create(['period_id' => $this->period->id, 'class_group_id' => $this->classGroup->id]);
    $this->official->sessions()->update(['course_offering_id' => $offering->id]);
    $this->draft->sessions()->update(['course_offering_id' => $offering->id, 'title' => 'Judul baru di draf']);
    movedWednesdayMeeting(['course_offering_id' => $offering->id]);

    app(SchedulePublisher::class)->publish($this->draft, $this->publisher, CarbonImmutable::parse('2026-11-09'), $this->draft->lock_version);

    expect(draftBlockOn('2026-11-11 10:00:00')->is_active)->toBeFalse()
        ->and(draftBlockOn('2026-11-18 10:00:00')->is_active)->toBeTrue();
});

test('pemindahan pertemuan tanpa penawaran tetap berlaku lewat judul dan kelas peserta', function () {
    movedWednesdayMeeting();

    app(SchedulePublisher::class)->publish($this->draft, $this->publisher, CarbonImmutable::parse('2026-11-09'), $this->draft->lock_version);

    expect(draftBlockOn('2026-11-11 10:00:00')->is_active)->toBeFalse()
        ->and(draftBlockOn('2026-11-18 10:00:00')->is_active)->toBeTrue();
});

test('slot asli yang sudah dipesan kelas lain tidak menahan penetapan jadwal baru', function () {
    movedWednesdayMeeting();
    app(ReservationService::class)->create(User::factory()->approvedKeti()->create(), $this->room, CarbonImmutable::parse('2026-11-11 10:00'), CarbonImmutable::parse('2026-11-11 11:40'), 'Rapat', null, (string) str()->uuid());

    app(SchedulePublisher::class)->publish($this->draft, $this->publisher, CarbonImmutable::parse('2026-11-09'), $this->draft->lock_version);

    expect($this->draft->fresh()->status)->toBe(ScheduleVersionStatus::Official)
        ->and(draftBlockOn('2026-11-11 10:00:00')->is_active)->toBeFalse();
});
