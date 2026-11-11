<?php

namespace App\Services\Academic;

use App\Enums\RescheduleStatus;
use App\Enums\ReservationStatus;
use App\Enums\RoomBlockSource;
use App\Exceptions\DomainException;
use App\Models\Academic\ClassGroup;
use App\Models\Academic\SessionReschedule;
use App\Models\Lecturer\Lecturer;
use App\Models\Reservation\Reservation;
use App\Models\Room\Room;
use App\Models\Room\RoomBlock;
use App\Models\Scheduling\ScheduleSession;
use App\Models\User\User;
use App\Services\Account\AuditLogger;
use App\Services\Account\NotificationService;
use App\Services\Room\AvailabilityService;
use App\Services\Room\ReservationService;
use App\Support\AvailabilityCache;
use App\Support\Wita;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Keti memindahkan satu pertemuan kuliah ke slot lain yang kosong (bukan mengubah jadwal mingguan admin).
 *
 * Slot baru disimpan sebagai reservasi "Kuliah Pengganti"; blok jadwal asli dinonaktifkan sehingga ruangnya
 * terbuka untuk kelas lain. Semua pemeriksaan dan penyimpanan berjalan dalam satu transaksi dengan lock pada
 * ruang tujuan dan kelas peserta.
 */
class MeetingRescheduleService
{
    public function __construct(
        private MeetingLocator $meetings,
        private LecturerConflictChecker $lecturers,
        private ReservationService $reservations,
        private AvailabilityService $availability,
        private ClassScheduleService $schedules,
        private AuditLogger $audit,
        private NotificationService $notifications,
    ) {}

    /**
     * @param  list<int>  $lecturerIds
     *
     * @throws DomainException
     */
    public function move(User $keti, ScheduleSession $session, CarbonInterface $originalDate, CarbonInterface $newStart, Room $room, array $lecturerIds, string $idempotencyKey): SessionReschedule
    {
        if ($existing = $this->findByKey($keti, $idempotencyKey)) {
            return $existing;
        }

        $session->loadMissing(['participants', 'lecturers']);
        $newStart = $this->normalize($newStart);

        try {
            return DB::transaction(function () use ($keti, $session, $originalDate, $newStart, $room, $lecturerIds, $idempotencyKey): SessionReschedule {
                // Lock dulu sebelum membaca apa pun (REPEATABLE READ): ruang tujuan, lalu kelas peserta urut ID.
                $lockedRoom = Room::query()->whereKey($room->id)->lockForUpdate()->firstOrFail();
                ClassGroup::query()->whereKey($session->participants->modelKeys())->orderBy('id')->lockForUpdate()->get();

                if ($existing = $this->findByKey($keti, $idempotencyKey)) {
                    return $existing;
                }

                return $this->createMove($keti, $session, $originalDate, $newStart, $lockedRoom, $lecturerIds, $idempotencyKey);
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            return $this->findByKey($keti, $idempotencyKey)
                ?? throw new DomainException('ROOM_CONFLICT', 'Ruangan sudah lebih dulu dipesan kelas lain untuk jam tersebut.');
        }
    }

    /**
     * Pindahkan lagi pertemuan yang sudah dipindah: pengganti lama dibatalkan (status "replaced") dan pengganti baru
     * dibuat untuk pertemuan asli yang sama. Jam asli boleh sudah lewat.
     *
     * @param  list<int>  $lecturerIds
     *
     * @throws DomainException
     */
    public function moveAgain(User $keti, Reservation $replacement, CarbonInterface $newStart, Room $room, array $lecturerIds, string $idempotencyKey): SessionReschedule
    {
        if ($existing = $this->findByKey($keti, $idempotencyKey)) {
            return $existing;
        }

        $old = $replacement->sessionReschedule;
        $meeting = $old === null ? null : $this->meetings->meetingsOn($old->original_start_at)
            ->first(fn (Meeting $meeting): bool => $meeting->reschedule?->id === $old->id);

        if ($meeting === null) {
            throw new DomainException('MEETING_NOT_FOUND', 'Reservasi ini bukan pertemuan kuliah yang dipindah.');
        }

        $session = $meeting->session;
        $newStart = $this->normalize($newStart);

        return DB::transaction(function () use ($keti, $session, $old, $newStart, $room, $lecturerIds, $idempotencyKey): SessionReschedule {
            $lockedRoom = Room::query()->whereKey($room->id)->lockForUpdate()->firstOrFail();
            ClassGroup::query()->whereKey($session->participants->modelKeys())->orderBy('id')->lockForUpdate()->get();
            $oldReservation = Reservation::query()->whereKey($old->reservation_id)->lockForUpdate()->firstOrFail();

            if ($existing = $this->findByKey($keti, $idempotencyKey)) {
                return $existing;
            }

            $old->refresh();
            $this->assertReplaceable($oldReservation, $old);

            // Pengganti lama dilepas lebih dulu agar slotnya boleh dipakai lagi oleh pengganti baru.
            $this->releaseReplacement($oldReservation, $keti, null);
            $old->forceFill(['status' => RescheduleStatus::Replaced, 'cancelled_at' => now(), 'cancelled_by' => $keti->id])->save();

            return $this->createMove($keti, $session, $old->original_start_at, $newStart, $lockedRoom, $lecturerIds, $idempotencyKey, $old);
        }, attempts: 3);
    }

    /**
     * Batalkan pemindahan: reservasi pengganti dibatalkan dan pertemuan kembali ke jam & ruang aslinya.
     *
     * @throws DomainException DATA_INCOMPLETE | INVALID_STATUS_TRANSITION | RESTORE_NOT_POSSIBLE
     */
    public function cancel(Reservation $reservation, User $actor, ?string $reason = null): Reservation
    {
        if ($actor->isAdmin() && blank($reason)) {
            throw new DomainException('DATA_INCOMPLETE', 'Alasan pembatalan wajib diisi oleh admin.');
        }

        $reschedule = $reservation->sessionReschedule
            ?? throw new DomainException('MEETING_NOT_FOUND', 'Reservasi ini bukan pertemuan kuliah yang dipindah.');

        return DB::transaction(function () use ($reservation, $reschedule, $actor, $reason): Reservation {
            // Lock ruang asli lalu reservasi pengganti sebelum membaca apa pun.
            $originalRoom = Room::query()->whereKey($reschedule->original_room_id)->lockForUpdate()->firstOrFail();
            $locked = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            $reschedule->refresh();

            if ($reschedule->status === RescheduleStatus::Cancelled) {
                return $locked;
            }

            $this->assertReplaceable($locked, $reschedule);

            $start = CarbonImmutable::instance($reschedule->original_start_at);
            $end = CarbonImmutable::instance($reschedule->original_end_at);

            if ($start->lessThanOrEqualTo(CarbonImmutable::now())) {
                throw new DomainException('RESTORE_NOT_POSSIBLE', 'Jam asli pertemuan sudah lewat sehingga tidak bisa dikembalikan. Gunakan Pindahkan lagi untuk memilih slot lain.');
            }

            if ($this->availability->blocksFor([$originalRoom->id], $start, $end, lock: true)->get($originalRoom->id, collect())->isNotEmpty()) {
                throw new DomainException('RESTORE_NOT_POSSIBLE', "Ruangan asli {$originalRoom->name} sudah dipakai pada jam itu. Gunakan Pindahkan lagi untuk memilih slot lain.");
            }

            $this->releaseReplacement($locked, $actor, $reason);
            $reschedule->forceFill(['status' => RescheduleStatus::Cancelled, 'cancelled_at' => now(), 'cancelled_by' => $actor->id])->save();
            $this->originalBlocks($reschedule)->update(['is_active' => true]);
            AvailabilityCache::bust();

            $this->audit->log('meeting.reschedule_cancelled', $reschedule, ['status' => RescheduleStatus::Active->value], ['status' => RescheduleStatus::Cancelled->value], $reason);

            $summary = "{$reschedule->session_title} kembali ke {$originalRoom->name}, ".Wita::dayName($start).', '.Wita::date($start).' '.Wita::range($start, $end).'.';
            $classIds = array_map('intval', array_filter(explode(',', $reschedule->participant_key)));

            if ($actor->id !== $locked->created_by) {
                $this->notifications->notify($locked->creator, 'meeting.reschedule_cancelled', 'Pemindahan pertemuan dibatalkan', $summary.($reason ? ' Alasan: '.$reason : ''), ['reservation_id' => $locked->id]);
            }

            if (! $actor->isAdmin()) {
                $this->notifications->notify($this->notifications->admins(), 'meeting.reschedule_cancelled', "Pemindahan dibatalkan: {$reschedule->session_title}", $summary, ['reservation_id' => $locked->id]);
            }

            $this->notifications->notify(
                $this->notifications->ketisOf($classIds)->reject(fn (User $user): bool => in_array($user->id, [$actor->id, $locked->created_by], true)),
                'meeting.reschedule_cancelled',
                'Pemindahan pertemuan dibatalkan',
                $summary,
                ['reservation_id' => $locked->id],
            );

            return $locked->fresh(['block']);
        });
    }

    private function assertReplaceable(Reservation $reservation, SessionReschedule $reschedule): void
    {
        if ($reschedule->status !== RescheduleStatus::Active || $reservation->status !== ReservationStatus::Booked) {
            throw new DomainException('INVALID_STATUS_TRANSITION', 'Pemindahan hanya bisa diubah selama pertemuan pengganti belum dimulai.');
        }
    }

    /**
     * Batalkan reservasi pengganti dan lepas blok ruangnya.
     */
    private function releaseReplacement(Reservation $reservation, User $actor, ?string $reason): void
    {
        $reservation->forceFill([
            'status' => ReservationStatus::Cancelled,
            'cancelled_at' => now(),
            'cancelled_by' => $actor->id,
            'cancel_reason' => $reason,
            'lock_version' => $reservation->lock_version + 1,
        ])->save();

        $reservation->block()->update(['is_active' => false]);
    }

    /**
     * Blok jadwal pertemuan asli pada versi resmi yang berlaku di tanggal itu (tetap ditemukan setelah jadwal ditetapkan ulang).
     */
    private function originalBlocks(SessionReschedule $reschedule)
    {
        $version = $this->schedules->officialVersionOn($reschedule->original_start_at);

        return RoomBlock::query()
            ->where('source_type', RoomBlockSource::Schedule)
            ->where('schedule_version_id', $version?->id)
            ->where('room_id', $reschedule->original_room_id)
            ->where('start_at', $reschedule->original_start_at);
    }

    /**
     * Aturan 1–11 lalu simpan. $replacing = pemindahan aktif yang sedang diganti (Pindahkan lagi).
     *
     * @param  list<int>  $lecturerIds
     */
    private function createMove(User $keti, ScheduleSession $session, CarbonInterface $originalDate, CarbonImmutable $newStart, Room $lockedRoom, array $lecturerIds, string $idempotencyKey, ?SessionReschedule $replacing = null): SessionReschedule
    {
        $classGroup = $this->assertParticipant($keti, $session);

        $meeting = $this->meetings->find($session, $originalDate)
            ?? throw new DomainException('MEETING_NOT_FOUND', 'Pertemuan ini tidak ada di jadwal resmi pada tanggal tersebut.');

        if ($meeting->isMoved() && $meeting->reschedule->id !== $replacing?->id) {
            throw new DomainException('MEETING_ALREADY_MOVED', 'Pertemuan ini sudah dipindah. Buka pertemuan penggantinya untuk memindahkan lagi.', ['reservation_id' => $meeting->reschedule->reservation_id]);
        }

        if ($replacing === null && $meeting->start->lessThanOrEqualTo(CarbonImmutable::now())) {
            throw new DomainException('MEETING_STARTED', 'Pertemuan yang sudah dimulai tidak bisa dipindah.');
        }

        $newEnd = $newStart->addMinutes((int) $meeting->start->diffInMinutes($meeting->end));
        $this->assertTeachingSlot($session, $newStart, $newEnd);
        $lecturers = $this->assertLecturers($session, $lecturerIds);
        $this->assertClassesFree($meeting, $newStart, $newEnd, $replacing);
        $this->lecturers->assertFree($lecturers, $newStart, $newEnd, $meeting, $replacing?->id);

        $reservation = $this->reservations->createForReschedule($keti, $classGroup, $lockedRoom, $newStart, $newEnd, $idempotencyKey);

        $reschedule = SessionReschedule::create([
            'schedule_session_id' => $session->id,
            'course_offering_id' => $session->course_offering_id,
            'session_title' => $session->title,
            'participant_key' => SessionReschedule::participantKey($session->participants->modelKeys()),
            'original_start_at' => $meeting->start,
            'original_end_at' => $meeting->end,
            'original_room_id' => $session->room_id,
            'reservation_id' => $reservation->id,
            'status' => RescheduleStatus::Active,
            'created_by' => $keti->id,
        ]);

        $reschedule->lecturers()->attach($lecturers->mapWithKeys(fn (Lecturer $lecturer): array => [
            $lecturer->id => ['role' => $session->lecturers->firstWhere('id', $lecturer->id)->pivot->role],
        ])->all());

        $reservation->forceFill(['session_reschedule_id' => $reschedule->id])->save();

        $this->scheduleBlock($session, $meeting->start)->update(['is_active' => false]);
        AvailabilityCache::bust();

        $this->audit->log('meeting.rescheduled', $reschedule, null, [
            'original_start_at' => $meeting->start->toDateTimeString(),
            'start_at' => $newStart->toDateTimeString(),
            'room_id' => $lockedRoom->id,
            'lecturer_ids' => $lecturers->modelKeys(),
            'replaces' => $replacing?->id,
        ]);

        $summary = "{$session->title} ".Wita::dayName($meeting->start).', '.Wita::date($meeting->start).' '.Wita::range($meeting->start, $meeting->end)
            ." dipindah ke {$lockedRoom->name}, ".Wita::dayName($newStart).', '.Wita::date($newStart).' '.Wita::range($newStart, $newEnd).'.';

        $this->notifications->notify($this->notifications->admins(), 'meeting.rescheduled', "Pertemuan dipindahkan: {$session->title}", "{$classGroup->name}: {$summary}", ['reservation_id' => $reservation->id]);
        $this->notifications->notify(
            $this->notifications->ketisOf($session->participants->modelKeys())->reject(fn (User $user): bool => $user->id === $keti->id),
            'meeting.rescheduled',
            'Pertemuan dipindahkan',
            $summary,
            ['reservation_id' => $reservation->id],
        );

        return $reschedule->fresh(['reservation.block', 'lecturers']);
    }

    private function assertParticipant(User $keti, ScheduleSession $session): ClassGroup
    {
        $classGroup = $keti->isApprovedKeti() ? $keti->activeClassGroup() : null;

        if ($classGroup === null || ! $session->participants->contains('id', $classGroup->id)) {
            throw new DomainException('NOT_CLASS_REPRESENTATIVE', 'Hanya Keti kelas peserta yang dapat memindahkan pertemuan ini.');
        }

        return $classGroup;
    }

    /**
     * Hari kuliah di semester, bukan libur, tidak menyentuh istirahat Jumat, dalam jam operasional, belum lewat.
     */
    private function assertTeachingSlot(ScheduleSession $session, CarbonImmutable $start, CarbonImmutable $end): void
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
            throw new DomainException('OUTSIDE_OPERATING_HOURS', 'Ruangan hanya bisa dipakai pukul '.Wita::clock($open, false).'–'.Wita::clock($close).'.');
        }

        if ($start->lessThan(CarbonImmutable::now())) {
            throw new DomainException('INVALID_TIME', 'Waktu mulai sudah lewat. Pilih waktu lain.');
        }
    }

    /**
     * @param  list<int>  $lecturerIds
     * @return Collection<int, Lecturer>
     */
    private function assertLecturers(ScheduleSession $session, array $lecturerIds): Collection
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
     */
    private function assertClassesFree(Meeting $meeting, CarbonImmutable $start, CarbonImmutable $end, ?SessionReschedule $replacing): void
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
            $this->reservations->assertClassFree($classGroup, $start, $end, ignoreReservationId: $replacing?->reservation_id, hint: 'Pilih jam lain.');
        }
    }

    private function scheduleBlock(ScheduleSession $session, CarbonImmutable $start)
    {
        return RoomBlock::query()
            ->where('source_type', RoomBlockSource::Schedule)
            ->where('source_id', $session->id)
            ->where('start_at', $start);
    }

    private function findByKey(User $keti, string $idempotencyKey): ?SessionReschedule
    {
        return Reservation::query()
            ->where('created_by', $keti->id)
            ->where('idempotency_key', $idempotencyKey)
            ->whereNotNull('session_reschedule_id')
            ->first()
            ?->sessionReschedule()
            ->with(['reservation.block', 'lecturers'])
            ->first();
    }

    private function normalize(CarbonInterface $time): CarbonImmutable
    {
        return CarbonImmutable::instance($time)->setTimezone(config('app.timezone'))->startOfMinute();
    }
}
