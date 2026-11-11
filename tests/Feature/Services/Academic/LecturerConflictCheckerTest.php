<?php

use App\Enums\ReservationStatus;
use App\Enums\SessionPhase;
use App\Enums\TeachingRole;
use App\Models\Academic\ClassGroup;
use App\Models\Academic\SessionReschedule;
use App\Models\Lecturer\LeadershipTerm;
use App\Models\Lecturer\Lecturer;
use App\Models\Reservation\Reservation;
use App\Services\Academic\LecturerConflictChecker;
use App\Services\Academic\MeetingLocator;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00'));
    $this->checker = app(LecturerConflictChecker::class);
    $this->lead = Lecturer::factory()->create(['name' => 'Budi Santoso']);
    $this->partner = Lecturer::factory()->create(['name' => 'Sari Dewi']);
    $this->team = [
        [$this->lead, TeachingRole::Lead, SessionPhase::BeforeUts],
        [$this->partner, TeachingRole::Partner, SessionPhase::AfterUts],
    ];
    // Basis Data – Tekom A, Selasa 10:00–12:30.
    $this->other = meetingSession([ClassGroup::factory()->create(['name' => 'Tekom A'])], ['title' => 'Basis Data', 'day_of_week' => 2, 'start_minute' => 600, 'end_minute' => 750], $this->team);
});

function tuesday(string $time): CarbonImmutable
{
    return CarbonImmutable::parse('2026-11-10 '.$time);
}

test('mitra yang sedang bergiliran mengajar kelas lain dianggap bentrok', function () {
    $exception = expectDomainCode(fn () => $this->checker->assertFree(collect([$this->partner]), tuesday('11:00'), tuesday('12:00')), 'LECTURER_CONFLICT');

    expect($exception->getMessage())->toContain('Sari Dewi (mitra)')->toContain('Basis Data – Tekom A');
});

test('pengampu yang bukan gilirannya sesudah UTS tidak dianggap bentrok', function () {
    $this->checker->assertFree(collect([$this->lead]), tuesday('11:00'), tuesday('12:00'));

    expect(true)->toBeTrue();
});

test('pertemuan lain yang sudah dipindah tidak dihitung', function () {
    SessionReschedule::factory()->create(['course_offering_id' => $this->other->course_offering_id, 'original_start_at' => '2026-11-10 10:00']);

    $this->checker->assertFree(collect([$this->partner]), tuesday('11:00'), tuesday('12:00'));

    expect(true)->toBeTrue();
});

test('pemindahan aktif lain dengan dosen terpilih yang sama dianggap bentrok selama reservasinya aktif', function () {
    $reservation = Reservation::factory()->create(['start_at' => '2026-11-11 08:00', 'end_at' => '2026-11-11 10:00', 'status' => ReservationStatus::Booked]);
    $reschedule = SessionReschedule::factory()->create(['reservation_id' => $reservation->id, 'session_title' => 'Jaringan Komputer']);
    $reschedule->lecturers()->attach($this->lead->id, ['role' => TeachingRole::Lead->value]);
    $wednesday = fn (string $time) => CarbonImmutable::parse('2026-11-11 '.$time);

    $exception = expectDomainCode(fn () => $this->checker->assertFree(collect([$this->lead]), $wednesday('09:00'), $wednesday('11:00')), 'LECTURER_CONFLICT');
    expect($exception->getMessage())->toContain('Budi Santoso (pengampu)')->toContain('Jaringan Komputer');

    $this->checker->assertFree(collect([$this->lead]), $wednesday('09:00'), $wednesday('11:00'), ignoreRescheduleId: $reschedule->id);

    $reservation->update(['status' => ReservationStatus::Cancelled]);
    $this->checker->assertFree(collect([$this->lead]), $wednesday('09:00'), $wednesday('11:00'));
});

test('pertemuan yang sedang dipindah sendiri tidak dihitung', function () {
    $meeting = app(MeetingLocator::class)->find($this->other, tuesday('00:00'));

    $this->checker->assertFree(collect([$this->partner]), tuesday('11:00'), tuesday('12:00'), $meeting);

    expect(true)->toBeTrue();
});

test('dosen pimpinan tidak boleh mengajar pertemuan pindahan hari Kamis', function () {
    LeadershipTerm::factory()->create(['lecturer_id' => $this->lead->id, 'valid_from' => '2026-01-01', 'valid_to' => null]);
    $thursday = fn (string $time) => CarbonImmutable::parse('2026-11-12 '.$time);

    $exception = expectDomainCode(fn () => $this->checker->assertFree(collect([$this->lead->fresh()]), $thursday('10:00'), $thursday('12:00')), 'LEADERSHIP_THURSDAY');
    expect($exception->getMessage())->toContain('Budi Santoso');

    $this->lead->leadershipTerms()->update(['valid_to' => '2026-11-01']);
    $this->checker->assertFree(collect([$this->lead->fresh()]), $thursday('10:00'), $thursday('12:00'));
});
