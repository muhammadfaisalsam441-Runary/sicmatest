<?php

use App\Enums\TeachingRole;
use App\Models\Academic\ClassGroup;
use App\Models\Academic\CourseOffering;
use App\Models\Academic\SessionReschedule;
use App\Models\Lecturer\Lecturer;
use App\Models\Reservation\Reservation;
use App\Models\Scheduling\ScheduleSession;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00'));
});

test('kunci peserta diurutkan dan unik', function () {
    expect(SessionReschedule::participantKey([7, 3, 7]))->toBe('3,7');
});

test('pemindahan cocok dengan sesi lewat penawaran mata kuliah dan jam mulai', function () {
    $offering = CourseOffering::factory()->create();
    $session = ScheduleSession::factory()->create(['course_offering_id' => $offering->id]);
    $reschedule = SessionReschedule::factory()->create([
        'course_offering_id' => $offering->id,
        'original_start_at' => '2026-11-09 07:30',
    ]);

    expect($reschedule->matches($session, CarbonImmutable::parse('2026-11-09 07:30')))->toBeTrue()
        ->and($reschedule->matches($session, CarbonImmutable::parse('2026-11-09 07:31')))->toBeFalse();
});

test('tanpa penawaran, pemindahan cocok lewat judul dan kelas peserta', function () {
    [$a, $b] = ClassGroup::factory()->count(2)->create();
    $session = ScheduleSession::factory()->create(['title' => 'Pemrograman Web']);
    $session->participants()->attach([$a->id, $b->id]);
    $reschedule = SessionReschedule::factory()->create([
        'course_offering_id' => null,
        'session_title' => 'Pemrograman Web',
        'participant_key' => SessionReschedule::participantKey([$b->id, $a->id]),
        'original_start_at' => '2026-11-09 07:30',
    ]);
    $start = CarbonImmutable::parse('2026-11-09 07:30');

    expect($reschedule->matches($session->fresh(), $start))->toBeTrue();

    $session->participants()->detach($b->id);

    expect($reschedule->matches($session->fresh(), $start))->toBeFalse();
});

test('reservasi pengganti terhubung ke pemindahan dan dosen terpilih', function () {
    $lecturer = Lecturer::factory()->create();
    $reschedule = SessionReschedule::factory()->create();
    $reschedule->lecturers()->attach($lecturer->id, ['role' => TeachingRole::Lead->value]);
    $reservation = Reservation::factory()->create(['session_reschedule_id' => $reschedule->id]);

    expect($reservation->sessionReschedule->is($reschedule))->toBeTrue()
        ->and($reschedule->lecturers->first()->pivot->role)->toBe('lead');
});
