<?php

use App\Enums\RoomBlockSource;
use App\Enums\ScheduleSource;
use App\Enums\ScheduleVersionStatus;
use App\Exceptions\DomainException;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\ClassGroup;
use App\Models\LeadershipTerm;
use App\Models\Lecturer;
use App\Models\Period;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\ScheduleSession;
use App\Models\ScheduleVersion;
use App\Models\User;
use App\Services\AvailabilityService;
use App\Services\DraftService;
use App\Services\ScheduleBlockBuilder;
use App\Services\SchedulePublisher;
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
    $this->draft = app(DraftService::class)->create($this->period, ScheduleSource::Manual, $this->official);
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
    app(DraftService::class)->toggleLock($this->draft->sessions()->first());

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
