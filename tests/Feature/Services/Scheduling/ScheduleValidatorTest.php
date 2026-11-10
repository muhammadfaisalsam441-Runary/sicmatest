<?php

use App\Enums\IssueSeverity;
use App\Enums\ScheduleVersionStatus;
use App\Enums\SessionMode;
use App\Enums\SessionPhase;
use App\Models\Academic\ClassGroup;
use App\Models\Academic\CourseOffering;
use App\Models\Academic\Period;
use App\Models\Lecturer\LeadershipTerm;
use App\Models\Lecturer\Lecturer;
use App\Models\Lecturer\LecturerAvailability;
use App\Models\Lecturer\LecturerLoadTarget;
use App\Models\Lecturer\TeachingAssignment;
use App\Models\Reservation\Reservation;
use App\Models\Room\Room;
use App\Models\Room\RoomBlock;
use App\Models\Scheduling\ScheduleSession;
use App\Models\Scheduling\ScheduleVersion;
use App\Services\Scheduling\ScheduleValidator;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00'));
    $this->period = Period::factory()->create();
    $this->draft = ScheduleVersion::factory()->for($this->period)->create(['status' => ScheduleVersionStatus::Draft, 'effective_from' => '2026-11-09']);
});

function draftSession(ScheduleVersion $draft, array $attributes = [], array $classes = [], array $lecturers = []): ScheduleSession
{
    $session = ScheduleSession::factory()->for($draft, 'version')->create($attributes);
    $session->participants()->sync(collect($classes)->pluck('id'));
    $session->lecturers()->sync(collect($lecturers)->mapWithKeys(fn ($lecturer) => [$lecturer->id => ['role' => 'lead']]));

    return $session;
}

function issueCodes(ScheduleVersion $draft): array
{
    return app(ScheduleValidator::class)->validate($draft)
        ->where('severity', IssueSeverity::Error)
        ->pluck('code')->sort()->values()->all();
}

test('draf tanpa bentrok tidak punya masalah wajib', function () {
    $room = Room::factory()->create();
    draftSession($this->draft, ['room_id' => $room->id, 'day_of_week' => 1, 'start_minute' => 450, 'end_minute' => 600], [ClassGroup::factory()->create()]);
    draftSession($this->draft, ['room_id' => $room->id, 'day_of_week' => 1, 'start_minute' => 600, 'end_minute' => 750], [ClassGroup::factory()->create()]);

    expect(issueCodes($this->draft))->toBe([]);
});

test('bentrok ruang, dosen, dan kelas pada jam yang beririsan menjadi masalah wajib', function () {
    $room = Room::factory()->create(['name' => 'AE 107']);
    $class = ClassGroup::factory()->create(['name' => 'Tekom C']);
    $lecturer = Lecturer::factory()->create();

    draftSession($this->draft, ['room_id' => $room->id, 'day_of_week' => 1, 'start_minute' => 450, 'end_minute' => 600], [$class], [$lecturer]);
    draftSession($this->draft, ['room_id' => $room->id, 'day_of_week' => 1, 'start_minute' => 550, 'end_minute' => 650], [$class], [$lecturer]);

    expect(issueCodes($this->draft))->toBe(['CLASS_CONFLICT', 'LECTURER_CONFLICT', 'ROOM_CONFLICT'])
        ->and($this->draft->issues()->blocking()->count())->toBe(3);
});

test('sesi online tidak bentrok ruang tetapi tetap diperiksa untuk dosen', function () {
    $lecturer = Lecturer::factory()->create();
    draftSession($this->draft, ['mode' => SessionMode::Online, 'room_id' => null, 'day_of_week' => 2, 'start_minute' => 450, 'end_minute' => 600], [], [$lecturer]);
    draftSession($this->draft, ['day_of_week' => 2, 'start_minute' => 500, 'end_minute' => 600], [], [$lecturer]);

    expect(issueCodes($this->draft))->toBe(['LECTURER_CONFLICT']);
});

test('sesi sebelum UTS dan setelah UTS pada jam sama tidak bentrok', function () {
    $room = Room::factory()->create();
    draftSession($this->draft, ['room_id' => $room->id, 'phase' => SessionPhase::BeforeUts]);
    draftSession($this->draft, ['room_id' => $room->id, 'phase' => SessionPhase::AfterUts]);

    expect(issueCodes($this->draft))->toBe([]);
});

test('dosen pimpinan pada hari Kamis ditolak selama masa jabatan', function () {
    $leader = Lecturer::factory()->create();
    LeadershipTerm::factory()->for($leader)->create(['valid_from' => '2025-01-01', 'valid_to' => null]);
    $formerLeader = Lecturer::factory()->create();
    LeadershipTerm::factory()->for($formerLeader)->create(['valid_from' => '2024-01-01', 'valid_to' => '2025-12-31']);
    $afterUtsLeader = Lecturer::factory()->create();
    LeadershipTerm::factory()->for($afterUtsLeader)->create(['valid_from' => '2026-11-01', 'valid_to' => null]);

    $this->draft->update(['effective_from' => '2026-09-01']);

    draftSession($this->draft, ['day_of_week' => 4], [], [$leader]);
    draftSession($this->draft, ['day_of_week' => 4, 'start_minute' => 780, 'end_minute' => 930], [], [$formerLeader]);
    draftSession($this->draft, ['day_of_week' => 4, 'start_minute' => 940, 'end_minute' => 1040, 'phase' => SessionPhase::BeforeUts], [], [$afterUtsLeader]);

    expect(issueCodes($this->draft))->toBe(['LEADERSHIP_THURSDAY']);
});

test('sesi Hubungi Dosen dan sesi luring tanpa ruang menjadi masalah wajib', function () {
    draftSession($this->draft, ['room_id' => null]);
    ScheduleSession::factory()->unscheduled()->for($this->draft, 'version')->create();

    expect(issueCodes($this->draft))->toBe(['SESSION_WITHOUT_ROOM', 'SESSION_WITHOUT_TIME']);
});

test('kapasitas kurang hanya peringatan selama kebijakan belum ditetapkan', function () {
    $room = Room::factory()->create(['capacity' => 30]);
    draftSession($this->draft, ['room_id' => $room->id], [ClassGroup::factory()->create(['student_count' => 40])]);
    draftSession($this->draft, ['room_id' => Room::factory()->create(['capacity' => null])->id, 'day_of_week' => 3], [ClassGroup::factory()->create(['student_count' => 40])]);

    $issues = app(ScheduleValidator::class)->validate($this->draft);

    expect($issues->pluck('code')->all())->toBe(['CAPACITY_EXCEEDED'])
        ->and($issues->first()->severity)->toBe(IssueSeverity::Warning);
});

test('beban melebihi maksimum hanya diperiksa bila maksimum sudah ditetapkan', function () {
    $lecturer = Lecturer::factory()->create();
    $offering = CourseOffering::factory()->for($this->period)->create();
    $offering->course->update(['sks' => 3]);
    draftSession($this->draft, ['course_offering_id' => $offering->id], [], [$lecturer]);

    expect(issueCodes($this->draft))->toBe([]);

    LecturerLoadTarget::create(['lecturer_id' => $lecturer->id, 'period_id' => $this->period->id, 'max_sks' => 2]);

    expect(issueCodes($this->draft->fresh()))->toBe(['LOAD_EXCEEDED']);
});

test('draf yang bentrok dengan reservasi aktif ditandai tanpa membatalkan reservasi', function () {
    $room = Room::factory()->create();
    $reservation = Reservation::factory()->for($room)->create(['start_at' => '2026-11-09 08:00', 'end_at' => '2026-11-09 09:00']);
    RoomBlock::factory()->for($room)->create(['start_at' => '2026-11-09 08:00', 'end_at' => '2026-11-09 09:00', 'source_id' => $reservation->id]);

    draftSession($this->draft, ['room_id' => $room->id, 'day_of_week' => 1, 'start_minute' => 450, 'end_minute' => 600]);

    expect(issueCodes($this->draft))->toBe(['RESERVATION_CONFLICT'])
        ->and($reservation->fresh()->status->value)->toBe('booked');
});

test('pemeriksaan ulang mengganti masalah lama yang sudah diperbaiki', function () {
    $room = Room::factory()->create();
    $first = draftSession($this->draft, ['room_id' => $room->id]);
    $second = draftSession($this->draft, ['room_id' => $room->id]);

    expect(issueCodes($this->draft))->toBe(['ROOM_CONFLICT']);

    $second->update(['day_of_week' => 2]);

    expect(issueCodes($this->draft->fresh()))->toBe([])
        ->and($this->draft->issues()->count())->toBe(0);
});

test('beban pengajar tim memakai bobot mata kuliah sekali per penawaran', function () {
    $lead = Lecturer::factory()->create();
    $partner = Lecturer::factory()->create();
    $offering = CourseOffering::factory()->for($this->period)->create();
    $offering->course->update(['sks' => 3]);
    foreach ([$lead, $partner] as $lecturer) {
        TeachingAssignment::factory()->for($offering, 'courseOffering')->for($lecturer)->create(['credited_sks' => 1.5]);
        LecturerLoadTarget::create(['lecturer_id' => $lecturer->id, 'period_id' => $this->period->id, 'max_sks' => 3]);
    }
    draftSession($this->draft, ['course_offering_id' => $offering->id, 'day_of_week' => 1], [], [$lead, $partner]);
    draftSession($this->draft, ['course_offering_id' => $offering->id, 'day_of_week' => 2], [], [$lead, $partner]);

    expect(issueCodes($this->draft))->toBe([]);
    $lead->loadTargets()->update(['max_sks' => 2]);
    expect(issueCodes($this->draft->fresh()))->toBe(['LOAD_EXCEEDED']);
});

test('sesi tanpa penawaran mata kuliah tidak dianggap lolos pemeriksaan beban maksimum', function () {
    $lead = Lecturer::factory()->create();
    $partner = Lecturer::factory()->create();
    LecturerLoadTarget::create(['lecturer_id' => $lead->id, 'period_id' => $this->period->id, 'max_sks' => 2]);
    draftSession($this->draft, ['course_offering_id' => null], [], [$lead, $partner]);

    expect(issueCodes($this->draft))->toBe(['LOAD_UNCONFIRMED']);
});

test('maksimum beban memakai SKS semester untuk tim 7 + 7', function () {
    $lead = Lecturer::factory()->create();
    $partner = Lecturer::factory()->create();
    $offering = CourseOffering::factory()->for($this->period)->create();
    $offering->course->update(['sks' => 2]);
    TeachingAssignment::factory()->for($offering, 'courseOffering')->for($lead)->create([
        'phase' => SessionPhase::BeforeUts,
        'credited_sks' => 1,
    ]);
    TeachingAssignment::factory()->for($offering, 'courseOffering')->for($partner)->create([
        'phase' => SessionPhase::AfterUts,
        'role' => 'partner',
    ]);
    $lead->loadTargets()->create(['period_id' => $this->period->id, 'max_sks' => 1]);
    $partner->loadTargets()->create(['period_id' => $this->period->id, 'max_sks' => 2]);
    draftSession($this->draft, ['course_offering_id' => $offering->id, 'phase' => SessionPhase::BeforeUts], [], [$lead]);
    draftSession($this->draft, ['course_offering_id' => $offering->id, 'phase' => SessionPhase::AfterUts], [], [$partner]);

    expect(issueCodes($this->draft))->toBe(['LOAD_EXCEEDED']);
    $lead->loadTargets()->update(['max_sks' => 2]);
    expect(issueCodes($this->draft->fresh()))->toBe([]);
});

test('sesi sebelum dan setelah UTS membutuhkan kalender UTS sebelum ditetapkan', function () {
    $this->period->update(['uts_start' => null, 'uts_end' => null]);
    draftSession($this->draft, ['phase' => SessionPhase::BeforeUts]);

    expect(issueCodes($this->draft))->toBe(['UTS_CALENDAR_MISSING']);
});

test('jadwal yang memakai ruang ditutup atau dosen tidak tersedia memiliki masalah wajib', function () {
    $lecturer = Lecturer::factory()->create();
    $room = Room::factory()->create();
    RoomBlock::factory()->for($room)->create(['source_type' => 'closure', 'start_at' => '2026-11-09 07:30', 'end_at' => '2026-11-09 12:30']);
    LecturerAvailability::create(['lecturer_id' => $lecturer->id, 'period_id' => $this->period->id, 'day_of_week' => 1,
        'start_minute' => 450, 'end_minute' => 500, 'type' => 'unavailable']);
    draftSession($this->draft, ['room_id' => $room->id], [], [$lecturer]);

    expect(issueCodes($this->draft))->toBe(['LECTURER_UNAVAILABLE', 'ROOM_CLOSED']);
});
