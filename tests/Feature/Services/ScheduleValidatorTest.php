<?php

use App\Enums\IssueSeverity;
use App\Enums\ScheduleVersionStatus;
use App\Enums\SessionMode;
use App\Enums\SessionPhase;
use App\Models\ClassGroup;
use App\Models\CourseOffering;
use App\Models\LeadershipTerm;
use App\Models\Lecturer;
use App\Models\LecturerLoadTarget;
use App\Models\Period;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\ScheduleSession;
use App\Models\ScheduleVersion;
use App\Services\ScheduleValidator;
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

    draftSession($this->draft, ['day_of_week' => 4], [], [$leader]);
    draftSession($this->draft, ['day_of_week' => 4, 'start_minute' => 780, 'end_minute' => 930], [], [$formerLeader]);

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
