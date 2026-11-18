<?php

namespace App\Services\Academic;

use App\Enums\RoomBlockSource;
use App\Exceptions\DomainException;
use App\Models\Academic\ClassGroup;
use App\Models\Academic\SessionReschedule;
use App\Models\Lecturer\Lecturer;
use App\Models\Room\RoomBlock;
use App\Models\Scheduling\ScheduleSession;
use App\Models\User\User;
use App\Services\Room\AvailabilityService;
use App\Services\Room\ReservationService;
use App\Support\Wita;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Validasi aturan pemindahan pertemuan kuliah (peserta, slot, dosen, dan bebas bentrok kelas).
 */
class MeetingMoveValidator
{
    public function __construct(
        private MeetingLocator $meetings,
        private LecturerConflictChecker $lecturers,
        private ReservationService $reservations,
        private AvailabilityService $availability,
        private ClassScheduleService $schedules,
    ) {}

    /**
     * Periksa seluruh aturan pemindahan pertemuan selain ketersediaan ruang tujuan.
     *
     * @param  list<int>  $lecturerIds
     * @return array{classGroup: ClassGroup, meeting: Meeting, end: CarbonImmutable, lecturers: Collection<int, Lecturer>}
     *
     * @throws DomainException
     */
    public function validateMove(
        User $keti,
        ScheduleSession $session,
        CarbonInterface $originalDate,
        CarbonImmutable $newStart,
        array $lecturerIds,
        ?SessionReschedule $replacing,
        bool $online = false,
    ): array {
        $classGroup = $this->assertParticipant($keti, $session);

        $meeting = $this->meetings->find($session, $originalDate)
            ?? throw new DomainException('MEETING_NOT_FOUND', 'Pertemuan ini tidak ada di jadwal resmi pada tanggal tersebut.');

        if ($meeting->isMoved() && $meeting->reschedule->id !== $replacing?->id) {
            throw new DomainException('MEETING_ALREADY_MOVED', 'Pertemuan ini sudah dipindah. Buka pertemuan penggantinya untuk memindahkan lagi.', [
                'reservation_id' => $meeting->reschedule->reservation_id,
                'reschedule_id' => $meeting->reschedule->id,
            ]);
        }

        if ($replacing === null && $meeting->start->lessThanOrEqualTo(CarbonImmutable::now())) {
            throw new DomainException('MEETING_STARTED', 'Pertemuan yang sudah dimulai tidak bisa diubah atau dipindah.');
        }

        $newEnd = $newStart->addMinutes((int) $meeting->start->diffInMinutes($meeting->end));

        if (! $online || ! $newStart->equalTo($meeting->start)) {
            $this->assertTeachingSlot($session, $newStart, $newEnd, $online);
        }

        $lecturers = $this->assertLecturers($session, $lecturerIds);
        $this->assertClassesFree($meeting, $newStart, $newEnd, $replacing);
        $this->lecturers->assertFree($lecturers, $newStart, $newEnd, $meeting, $replacing?->id);

        return ['classGroup' => $classGroup, 'meeting' => $meeting, 'end' => $newEnd, 'lecturers' => $lecturers];
    }

    /**
     * Pastikan akun Keti mewakili salah satu kelas peserta sesi ini.
     *
     * @throws DomainException
     */
    public function assertParticipant(User $keti, ScheduleSession $session): ClassGroup
    {
        $classGroup = $keti->isApprovedKeti() ? $keti->activeClassGroup() : null;

        if ($classGroup === null || ! $session->participants->contains('id', $classGroup->id)) {
            throw new DomainException('NOT_CLASS_REPRESENTATIVE', 'Hanya Keti kelas peserta yang dapat memindahkan pertemuan ini.');
        }

        return $classGroup;
    }

    /**
     * Hari kuliah di semester, bukan libur, tidak menyentuh istirahat Jumat, dalam jam operasional, belum lewat.
     *
     * @throws DomainException
     */
    public function assertTeachingSlot(ScheduleSession $session, CarbonImmutable $start, CarbonImmutable $end, bool $online = false): void
    {
        $period = $session->version->period;
        $day = $start->startOfDay();
        $break = config('sicma.scheduler.friday_break', ['start' => 720, 'end' => 790]);
        $startMinute = $start->hour * 60 + $start->minute;
        $endMinute = $startMinute + (int) $start->diffInMinutes($end);

        $touchesFridayBreak = $start->dayOfWeekIso === 5 && $startMinute < $break['end'] && $endMinute > $break['start'];

        if (! in_array($start->dayOfWeekIso, config('sicma.scheduler.days'), true)
            || ! $this->schedules->hasClassesOn($period->loadMissing('calendarExceptions'), $day)
            || $touchesFridayBreak) {
            throw new DomainException('INVALID_TEACHING_DAY', 'Pertemuan pengganti hanya bisa Senin–Jumat di dalam semester, bukan hari libur dan bukan istirahat Jumat 12.00–13.10.');
        }

        [$open, $close] = $this->availability->operatingWindow($start);

        if ($start->lessThan($open) || $end->greaterThan($close)) {
            $subject = $online ? 'Perkuliahan pengganti' : 'Ruangan';

            throw new DomainException('OUTSIDE_OPERATING_HOURS', "{$subject} hanya bisa dipakai pukul ".Wita::clock($open, false).'–'.Wita::clock($close).'.');
        }

        if ($start->lessThan(CarbonImmutable::now())) {
            throw new DomainException('INVALID_TIME', 'Waktu mulai sudah lewat. Pilih waktu lain.');
        }
    }

    /**
     * Validasi dan muat dosen pengajar yang dipilih dari tim pengampu mata kuliah.
     *
     * @param  list<int>  $lecturerIds
     * @return Collection<int, Lecturer>
     *
     * @throws DomainException
     */
    public function assertLecturers(ScheduleSession $session, array $lecturerIds): Collection
    {
        $ids = collect($lecturerIds)->map(fn ($id): int => (int) $id)->unique();
        $team = $session->lecturers->modelKeys();

        if ($ids->isEmpty() || $ids->diff($team)->isNotEmpty()) {
            throw new DomainException('INVALID_LECTURER', 'Pilih minimal satu dosen dari tim pengajar mata kuliah ini.');
        }

        return Lecturer::query()->whereKey($ids->all())->with('leadershipTerms')->get();
    }

    /**
     * Semua kelas peserta tidak sedang kuliah resmi lain maupun punya reservasi aktif di slot baru.
     *
     * @throws DomainException
     */
    public function assertClassesFree(Meeting $meeting, CarbonImmutable $start, CarbonImmutable $end, ?SessionReschedule $replacing): void
    {
        $classIds = $meeting->session->participants->modelKeys();

        $clash = $this->meetings->meetingsOn($start)->first(fn (Meeting $other): bool => $other->start->lessThan($end)
            && $other->end->greaterThan($start)
            && ! $other->isMoved()
            && ! $other->is($meeting)
            && $other->session->participants->pluck('id')->intersect($classIds)->isNotEmpty());

        if ($clash !== null) {
            $class = $clash->session->participants->first(fn (ClassGroup $group): bool => in_array($group->id, $classIds, true));

            throw new DomainException('CLASS_TIME_CONFLICT', "Kelas {$class->name} sedang kuliah {$clash->session->title} pukul ".Wita::range($clash->start, $clash->end).'. Pilih jam lain.');
        }

        foreach ($meeting->session->participants as $classGroup) {
            $this->reservations->assertClassFree($classGroup, $start, $end, ignoreReservationId: $replacing?->reservation_id, hint: 'Pilih jam lain.', ignoreRescheduleId: $replacing?->id);
        }
    }

    /**
     * Query blok ruang terjadwal untuk pertemuan asli pada jam mulai tertentu.
     *
     * @return Builder<RoomBlock>
     */
    public function scheduleBlock(ScheduleSession $session, CarbonImmutable $start): Builder
    {
        return RoomBlock::query()
            ->where('source_type', RoomBlockSource::Schedule)
            ->where('source_id', $session->id)
            ->where('start_at', $start);
    }
}
