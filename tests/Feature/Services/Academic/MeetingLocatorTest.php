<?php

use App\Enums\SessionPhase;
use App\Enums\TeachingRole;
use App\Models\Academic\CalendarException;
use App\Models\Academic\ClassGroup;
use App\Models\Academic\SessionReschedule;
use App\Models\Lecturer\Lecturer;
use App\Models\Scheduling\ScheduleSession;
use App\Models\Scheduling\ScheduleVersion;
use App\Services\Academic\MeetingLocator;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00'));
    $this->locator = app(MeetingLocator::class);
    $this->classGroup = ClassGroup::factory()->create(['name' => 'Tekom C']);
    $this->lead = Lecturer::factory()->create(['name' => 'Budi Santoso']);
    $this->partner = Lecturer::factory()->create(['name' => 'Sari Dewi']);
});

function teamSession(array $attributes = []): ScheduleSession
{
    return meetingSession([test()->classGroup], $attributes, [
        [test()->lead, TeachingRole::Lead, SessionPhase::BeforeUts],
        [test()->partner, TeachingRole::Partner, SessionPhase::AfterUts],
    ]);
}

test('pertemuan ditemukan pada hari sesi di jadwal resmi', function () {
    $session = meetingSession([$this->classGroup]);

    $meeting = $this->locator->find($session, CarbonImmutable::parse('2026-11-09'));

    expect($meeting->start->format('Y-m-d H:i'))->toBe('2026-11-09 07:30')
        ->and($meeting->end->format('H:i'))->toBe('10:00')
        ->and($meeting->isMoved())->toBeFalse();
});

test('pertemuan tidak ditemukan di hari lain, hari libur, kuliah online, atau versi draf', function () {
    $session = meetingSession([$this->classGroup]);
    CalendarException::factory()->create(['period_id' => officialVersion()->period_id, 'date' => '2026-11-16']);
    $online = ScheduleSession::factory()->online()->create(['schedule_version_id' => officialVersion()->id]);
    $draft = ScheduleSession::factory()->create(['schedule_version_id' => ScheduleVersion::factory()->create(['period_id' => officialVersion()->period_id])->id]);

    expect($this->locator->find($session, CarbonImmutable::parse('2026-11-10')))->toBeNull()
        ->and($this->locator->find($session->fresh(), CarbonImmutable::parse('2026-11-16')))->toBeNull()
        ->and($this->locator->find($online, CarbonImmutable::parse('2026-11-09')))->toBeNull()
        ->and($this->locator->find($draft, CarbonImmutable::parse('2026-11-09')))->toBeNull();
});

test('pertemuan yang punya pemindahan aktif ditandai sudah dipindah', function () {
    $session = meetingSession([$this->classGroup]);
    SessionReschedule::factory()->create(['course_offering_id' => $session->course_offering_id, 'original_start_at' => '2026-11-09 07:30']);

    expect($this->locator->find($session, CarbonImmutable::parse('2026-11-09'))->isMoved())->toBeTrue()
        ->and($this->locator->find($session, CarbonImmutable::parse('2026-11-16'))->isMoved())->toBeFalse();
});

test('dosen bawaan mengikuti giliran fase UTS', function () {
    $session = teamSession();
    $date = CarbonImmutable::parse('2026-11-09');

    expect($this->locator->defaultLecturerIds($session, $date)->all())->toBe([$this->partner->id]);

    officialVersion()->period->update(['uts_start' => '2026-11-23', 'uts_end' => '2026-11-27']);

    expect($this->locator->defaultLecturerIds($session->fresh(), $date)->all())->toBe([$this->lead->id]);
});

test('tanpa tanggal UTS, dosen bawaan pengampu dan dosen bertugas seluruh tim', function () {
    officialVersion()->period->update(['uts_start' => null, 'uts_end' => null]);
    $session = teamSession();
    $date = CarbonImmutable::parse('2026-11-09');

    expect($this->locator->rotationLecturerIds($session, $date))->toBeNull()
        ->and($this->locator->defaultLecturerIds($session, $date)->all())->toBe([$this->lead->id])
        ->and($this->locator->dutyLecturerIds($session, $date)->sort()->values()->all())->toBe(collect([$this->lead->id, $this->partner->id])->sort()->values()->all());
});

test('sesi tanpa penawaran dan tanpa pengampu: semua dosen sesi menjadi bawaan', function () {
    $session = meetingSession([$this->classGroup], ['course_offering_id' => null], [
        [$this->lead, TeachingRole::Partner, SessionPhase::Full],
        [$this->partner, TeachingRole::Partner, SessionPhase::Full],
    ]);

    expect($this->locator->defaultLecturerIds($session, CarbonImmutable::parse('2026-11-09'))->sort()->values()->all())
        ->toBe(collect([$this->lead->id, $this->partner->id])->sort()->values()->all());
});

test('meetingsOn memuat semua pertemuan resmi tanggal itu termasuk online', function () {
    meetingSession([$this->classGroup]);
    ScheduleSession::factory()->online()->create(['schedule_version_id' => officialVersion()->id]);
    ScheduleSession::factory()->create(['schedule_version_id' => officialVersion()->id, 'day_of_week' => 2]);

    expect($this->locator->meetingsOn(CarbonImmutable::parse('2026-11-09')))->toHaveCount(2);
});
