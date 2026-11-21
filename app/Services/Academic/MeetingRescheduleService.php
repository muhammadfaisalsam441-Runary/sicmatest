<?php

namespace App\Services\Academic;

use App\Enums\RescheduleMode;
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
use Illuminate\Database\Eloquent\Builder;
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
        private ?MeetingMoveValidator $validator = null,
        private ?MeetingRecommendationFinder $recommendationFinder = null,
    ) {
        $this->validator ??= new MeetingMoveValidator(
            $this->meetings,
            $this->lecturers,
            $this->reservations,
            $this->availability,
            $this->schedules,
        );
        $this->recommendationFinder ??= new MeetingRecommendationFinder(
            $this->meetings,
            $this->availability,
            $this->schedules,
            $this->validator,
        );
    }

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
        $newStart = Wita::normalize($newStart);

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
        if ($old === null) {
            throw new DomainException('MEETING_NOT_FOUND', 'Reservasi ini bukan pertemuan kuliah yang dipindah.');
        }

        return $this->replaceWithRoom($keti, $old, $newStart, $room, $lecturerIds, $idempotencyKey);
    }

    /**
     * Jadikan satu pertemuan kuliah menjadi Kuliah Online (tanpa ruangan), baik di jam yang sama
     * maupun di jam pengganti yang baru. Blok ruang jadwal asli dinonaktifkan agar ruangnya kosong.
     *
     * @param  list<int>  $lecturerIds
     *
     * @throws DomainException
     */
    public function makeOnline(
        User $keti,
        ScheduleSession $session,
        CarbonInterface $originalDate,
        ?CarbonInterface $newStart,
        array $lecturerIds,
        ?string $note,
        string $idempotencyKey,
    ): SessionReschedule {
        if ($existing = $this->findByKey($keti, $idempotencyKey)) {
            return $existing;
        }

        $session->loadMissing(['participants', 'lecturers']);
        $normalizedStart = $newStart !== null ? Wita::normalize($newStart) : null;

        try {
            return DB::transaction(function () use ($keti, $session, $originalDate, $normalizedStart, $lecturerIds, $note, $idempotencyKey): SessionReschedule {
                ClassGroup::query()->whereKey($session->participants->modelKeys())->orderBy('id')->lockForUpdate()->get();

                if ($existing = $this->findByKey($keti, $idempotencyKey)) {
                    return $existing;
                }

                return $this->createOnlineMove($keti, $session, $originalDate, $normalizedStart, $lecturerIds, $note, $idempotencyKey);
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            return $this->findByKey($keti, $idempotencyKey)
                ?? throw new DomainException('CONFLICT', 'Pertemuan ini sedang diproses, silakan muat ulang halaman.');
        }
    }

    /**
     * Pindahkan ulang pemindahan aktif (baik dari ruang fisik maupun dari online) ke ruang fisik baru.
     *
     * @param  list<int>  $lecturerIds
     *
     * @throws DomainException
     */
    public function replaceWithRoom(
        User $keti,
        SessionReschedule $old,
        CarbonInterface $newStart,
        Room $room,
        array $lecturerIds,
        string $idempotencyKey,
    ): SessionReschedule {
        if ($existing = $this->findByKey($keti, $idempotencyKey)) {
            return $existing;
        }

        $meeting = $this->meetings->meetingsOn($old->original_start_at)
            ->first(fn (Meeting $candidate): bool => $candidate->reschedule?->id === $old->id);

        if ($meeting === null) {
            throw new DomainException('MEETING_NOT_FOUND', 'Pemindahan pertemuan ini tidak ditemukan.');
        }

        $session = $meeting->session;
        $newStart = Wita::normalize($newStart);

        try {
            return DB::transaction(function () use ($keti, $session, $old, $newStart, $room, $lecturerIds, $idempotencyKey): SessionReschedule {
                $lockedRoom = Room::query()->whereKey($room->id)->lockForUpdate()->firstOrFail();
                ClassGroup::query()->whereKey($session->participants->modelKeys())->orderBy('id')->lockForUpdate()->get();
                $oldReschedule = SessionReschedule::query()->whereKey($old->id)->lockForUpdate()->firstOrFail();
                $oldReservation = $oldReschedule->reservation_id !== null
                    ? Reservation::query()->whereKey($oldReschedule->reservation_id)->lockForUpdate()->firstOrFail()
                    : null;

                if ($existing = $this->findByKey($keti, $idempotencyKey)) {
                    return $existing;
                }

                $this->releasePreviousReschedule($oldReschedule, $oldReservation, $keti);

                return $this->createMove($keti, $session, $oldReschedule->original_start_at, $newStart, $lockedRoom, $lecturerIds, $idempotencyKey, $oldReschedule);
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            return $this->findByKey($keti, $idempotencyKey)
                ?? throw new DomainException('ROOM_CONFLICT', 'Ruangan sudah lebih dulu dipesan kelas lain untuk jam tersebut.');
        }
    }

    /**
     * Pindahkan ulang pemindahan aktif (baik dari ruang fisik maupun dari online) ke Kuliah Online di jam baru.
     *
     * @param  list<int>  $lecturerIds
     *
     * @throws DomainException
     */
    public function replaceWithOnline(
        User $keti,
        SessionReschedule $old,
        CarbonInterface $newStart,
        array $lecturerIds,
        ?string $note,
        string $idempotencyKey,
    ): SessionReschedule {
        if ($existing = $this->findByKey($keti, $idempotencyKey)) {
            return $existing;
        }

        $meeting = $this->meetings->meetingsOn($old->original_start_at)
            ->first(fn (Meeting $candidate): bool => $candidate->reschedule?->id === $old->id);

        if ($meeting === null) {
            throw new DomainException('MEETING_NOT_FOUND', 'Pemindahan pertemuan ini tidak ditemukan.');
        }

        $session = $meeting->session;
        $newStart = Wita::normalize($newStart);

        try {
            return DB::transaction(function () use ($keti, $session, $old, $newStart, $lecturerIds, $note, $idempotencyKey): SessionReschedule {
                ClassGroup::query()->whereKey($session->participants->modelKeys())->orderBy('id')->lockForUpdate()->get();
                $oldReschedule = SessionReschedule::query()->whereKey($old->id)->lockForUpdate()->firstOrFail();
                $oldReservation = $oldReschedule->reservation_id !== null
                    ? Reservation::query()->whereKey($oldReschedule->reservation_id)->lockForUpdate()->firstOrFail()
                    : null;

                if ($existing = $this->findByKey($keti, $idempotencyKey)) {
                    return $existing;
                }

                $this->releasePreviousReschedule($oldReschedule, $oldReservation, $keti);

                return $this->createOnlineMove($keti, $session, $oldReschedule->original_start_at, $newStart, $lecturerIds, $note, $idempotencyKey, $oldReschedule);
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            return $this->findByKey($keti, $idempotencyKey)
                ?? throw new DomainException('CONFLICT', 'Pertemuan ini sedang diproses, silakan muat ulang halaman.');
        }
    }

    /**
     * Kembalikan pertemuan Online ke ruang & jam luring aslinya jika ruangan, kelas, dan dosen masih bebas.
     *
     * @throws DomainException DATA_INCOMPLETE | INVALID_STATUS_TRANSITION | RESTORE_NOT_POSSIBLE
     */
    public function cancelOnline(SessionReschedule $reschedule, User $actor, ?string $reason = null): SessionReschedule
    {
        if ($actor->isAdmin() && blank($reason)) {
            throw new DomainException('DATA_INCOMPLETE', 'Alasan pembatalan wajib diisi oleh admin.');
        }

        if (! $reschedule->isOnline()) {
            throw new DomainException('MEETING_NOT_FOUND', 'Catatan pemindahan ini bukan mode kuliah online.');
        }

        return DB::transaction(function () use ($reschedule, $actor, $reason): SessionReschedule {
            $originalRoom = Room::query()->whereKey($reschedule->original_room_id)->lockForUpdate()->firstOrFail();
            $locked = SessionReschedule::query()->whereKey($reschedule->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === RescheduleStatus::Cancelled) {
                return $locked;
            }

            if ($locked->status !== RescheduleStatus::Active || ! $locked->isOnline()) {
                throw new DomainException('INVALID_STATUS_TRANSITION', 'Status online hanya bisa dikembalikan selama pertemuan masih aktif.');
            }

            $start = CarbonImmutable::instance($locked->original_start_at);
            $end = CarbonImmutable::instance($locked->original_end_at);

            if ($start->lessThanOrEqualTo(CarbonImmutable::now())) {
                throw new DomainException('RESTORE_NOT_POSSIBLE', 'Jam asli pertemuan sudah lewat sehingga tidak bisa dikembalikan ke ruang luring. Gunakan Pindahkan untuk memilih slot lain.');
            }

            if ($this->availability->blocksFor([$originalRoom->id], $start, $end, lock: true)->get($originalRoom->id, collect())->isNotEmpty()) {
                throw new DomainException('RESTORE_NOT_POSSIBLE', "Ruangan asli {$originalRoom->name} sudah dipakai pada jam itu. Gunakan Pindahkan untuk memilih ruangan lain.");
            }

            $session = $locked->session()->with(['participants', 'lecturers.leadershipTerms'])->firstOrFail();
            ClassGroup::query()->whereKey($session->participants->modelKeys())->orderBy('id')->lockForUpdate()->get();
            $meeting = $this->meetings->find($session, $start) ?? new Meeting($session, $start, $end, $locked);

            try {
                $this->validator->assertClassesFree($meeting, $start, $end, replacing: $locked);
                $this->lecturers->assertFree($session->lecturers, $start, $end, excludeMeeting: $meeting, ignoreRescheduleId: $locked->id);
            } catch (DomainException $exception) {
                throw new DomainException('RESTORE_NOT_POSSIBLE', $exception->getMessage(), $exception->context);
            }

            $locked->forceFill(['status' => RescheduleStatus::Cancelled, 'cancelled_at' => now(), 'cancelled_by' => $actor->id])->save();
            $this->originalBlocks($locked)->update(['is_active' => true]);
            AvailabilityCache::bust();

            $this->audit->log('meeting.online_cancelled', $locked, ['status' => RescheduleStatus::Active->value], ['status' => RescheduleStatus::Cancelled->value], $reason);

            $summary = "{$locked->session_title} kembali luring di {$originalRoom->name}, ".Wita::dayName($start).', '.Wita::date($start).' '.Wita::range($start, $end).'.';
            $classIds = array_map('intval', array_filter(explode(',', $locked->participant_key)));

            if ($actor->id !== $locked->created_by) {
                $this->notifications->notify($locked->creator, 'meeting.online_cancelled', 'Kuliah online dibatalkan', $summary.($reason ? ' Alasan: '.$reason : ''), ['reschedule_id' => $locked->id]);
            }

            if (! $actor->isAdmin()) {
                $this->notifications->notify($this->notifications->admins(), 'meeting.online_cancelled', "Kembali luring: {$locked->session_title}", $summary, ['reschedule_id' => $locked->id]);
            }

            $this->notifications->notify(
                $this->notifications->ketisOf($classIds)->reject(fn (User $user): bool => in_array($user->id, [$actor->id, $locked->created_by], true)),
                'meeting.online_cancelled',
                'Kuliah kembali luring',
                $summary,
                ['reschedule_id' => $locked->id],
            );

            return $locked->fresh(['originalRoom', 'lecturers']);
        });
    }

    /**
     * Selesaikan perkuliahan terjadwal yang sedang berlangsung lebih awal agar ruang segera tersedia.
     *
     * @throws DomainException NOT_CLASS_REPRESENTATIVE | MEETING_NOT_FOUND | MEETING_ALREADY_MOVED | MEETING_NOT_STARTED | MEETING_ALREADY_FINISHED
     */
    public function finishEarly(User $keti, ScheduleSession $session, CarbonInterface $originalDate): RoomBlock
    {
        $session->loadMissing(['participants', 'room']);
        $classGroup = $this->validator->assertParticipant($keti, $session);

        $meeting = $this->meetings->find($session, $originalDate)
            ?? throw new DomainException('MEETING_NOT_FOUND', 'Pertemuan ini tidak ada di jadwal resmi pada tanggal tersebut.');

        if ($meeting->isMoved()) {
            throw new DomainException('MEETING_ALREADY_MOVED', 'Pertemuan ini sudah dipindahkan.');
        }

        $now = Wita::normalize(CarbonImmutable::now());

        if ($now->lessThan($meeting->start)) {
            throw new DomainException('MEETING_NOT_STARTED', 'Pertemuan belum dimulai sehingga belum bisa diselesaikan.');
        }

        if ($now->greaterThanOrEqualTo($meeting->end)) {
            throw new DomainException('MEETING_ALREADY_FINISHED', 'Pertemuan ini sudah selesai.');
        }

        return DB::transaction(function () use ($keti, $session, $classGroup, $meeting, $now): RoomBlock {
            $room = Room::query()->whereKey($session->room_id)->lockForUpdate()->firstOrFail();

            $block = RoomBlock::query()
                ->where('source_type', RoomBlockSource::Schedule)
                ->where('schedule_version_id', $session->schedule_version_id)
                ->where('source_id', $session->id)
                ->where('room_id', $session->room_id)
                ->where('start_at', $meeting->start)
                ->lockForUpdate()
                ->first();

            if ($block === null || ! $block->is_active || $now->greaterThanOrEqualTo($block->end_at)) {
                throw new DomainException('MEETING_ALREADY_FINISHED', 'Pertemuan ini sudah selesai.');
            }

            $before = ['end_at' => $block->end_at->format('Y-m-d H:i:s')];
            $block->update(['end_at' => $now->max($meeting->start)]);
            AvailabilityCache::bust();

            $freshBlock = $block->fresh();

            $this->audit->log(
                'meeting.finished_early',
                $session,
                $before,
                [
                    'end_at' => $freshBlock->end_at->format('Y-m-d H:i:s'),
                    'room_id' => $session->room_id,
                    'class_group_id' => $classGroup->id,
                ],
            );

            $this->notifications->notify(
                $this->notifications->admins(),
                'meeting.finished_early',
                'Perkuliahan selesai lebih cepat: '.$room->name,
                "{$keti->name} ({$classGroup->name}) menyelesaikan perkuliahan {$session->title} di {$room->name} pukul ".Wita::clock($now).'. Ruangan kini tersedia.',
                ['session_id' => $session->id],
            );

            return $freshBlock;
        });
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

            $session = $reschedule->session()->with(['participants', 'lecturers.leadershipTerms'])->firstOrFail();
            ClassGroup::query()->whereKey($session->participants->modelKeys())->orderBy('id')->lockForUpdate()->get();
            $meeting = $this->meetings->find($session, $start) ?? new Meeting($session, $start, $end, $reschedule);

            try {
                $this->validator->assertClassesFree($meeting, $start, $end, replacing: $reschedule);
                $this->lecturers->assertFree($session->lecturers, $start, $end, excludeMeeting: $meeting, ignoreRescheduleId: $reschedule->id);
            } catch (DomainException $exception) {
                throw new DomainException('RESTORE_NOT_POSSIBLE', $exception->getMessage(), $exception->context);
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

    /**
     * Aturan pemindahan selain ruang (aturan 1–7, 9–11), tanpa menyimpan. Dipakai halaman Pindahkan untuk
     * memberi tahu bentrok kelas/dosen sebelum Keti memilih ruang atau mode online.
     *
     * @param  list<int>  $lecturerIds
     *
     * @throws DomainException
     */
    public function check(User $keti, ScheduleSession $session, CarbonInterface $originalDate, CarbonInterface $newStart, array $lecturerIds, ?SessionReschedule $replacing = null): Meeting
    {
        $session->loadMissing(['participants', 'lecturers']);

        return $this->validator->validateMove($keti, $session, $originalDate, Wita::normalize($newStart), $lecturerIds, $replacing)['meeting'];
    }

    /**
     * Rekomendasi slot waktu dan ruangan kosong untuk memindahkan pertemuan kuliah pada tanggal target.
     *
     * @param  list<int>  $lecturerIds
     * @return Collection<int, array{slot: string, start: CarbonImmutable, end: CarbonImmutable, time_range: string, rooms: list<array<string, mixed>>, rooms_count: int}>
     */
    public function recommendations(
        User $keti,
        ScheduleSession $session,
        CarbonInterface $originalDate,
        CarbonInterface $targetDate,
        array $lecturerIds,
        ?SessionReschedule $replacing = null,
        ?int $manualStartMinute = null,
    ): Collection {
        return $this->recommendationFinder->recommendations(
            $keti,
            $session,
            $originalDate,
            $targetDate,
            $lecturerIds,
            $replacing,
            $manualStartMinute,
        );
    }

    private function assertReplaceable(Reservation $reservation, SessionReschedule $reschedule): void
    {
        if ($reschedule->status !== RescheduleStatus::Active || $reservation->status !== ReservationStatus::Booked) {
            throw new DomainException('INVALID_STATUS_TRANSITION', 'Pemindahan hanya bisa diubah selama pertemuan pengganti belum dimulai.');
        }
    }

    private function releasePreviousReschedule(SessionReschedule $oldReschedule, ?Reservation $oldReservation, User $keti): void
    {
        if ($oldReservation !== null) {
            $this->assertReplaceable($oldReservation, $oldReschedule);
            $this->releaseReplacement($oldReservation, $keti, null);
        } elseif ($oldReschedule->status !== RescheduleStatus::Active || $oldReschedule->effectiveStart()->lessThanOrEqualTo(CarbonImmutable::now())) {
            throw new DomainException('INVALID_STATUS_TRANSITION', 'Pertemuan pengganti hanya bisa diubah sebelum jadwal pengganti dimulai.');
        }

        $oldReschedule->forceFill([
            'status' => RescheduleStatus::Replaced,
            'cancelled_at' => now(),
            'cancelled_by' => $keti->id,
        ])->save();
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
     *
     * @return Builder<RoomBlock>
     */
    private function originalBlocks(SessionReschedule $reschedule): Builder
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
        ['classGroup' => $classGroup, 'meeting' => $meeting, 'end' => $newEnd, 'lecturers' => $lecturers] = $this->validator->validateMove($keti, $session, $originalDate, $newStart, $lecturerIds, $replacing);

        $reservation = $this->reservations->createForReschedule($keti, $classGroup, $lockedRoom, $newStart, $newEnd, $idempotencyKey);

        $reschedule = SessionReschedule::create([
            'schedule_session_id' => $session->id,
            'course_offering_id' => $session->course_offering_id,
            'session_title' => $session->title,
            'participant_key' => SessionReschedule::participantKey($session->participants->modelKeys()),
            'original_start_at' => $meeting->start,
            'original_end_at' => $meeting->end,
            'original_room_id' => $session->room_id,
            'mode' => RescheduleMode::Room,
            'reservation_id' => $reservation->id,
            'target_start_at' => $newStart,
            'target_end_at' => $newEnd,
            'status' => RescheduleStatus::Active,
            'created_by' => $keti->id,
            'idempotency_key' => $idempotencyKey,
        ]);

        $reschedule->lecturers()->attach($lecturers->mapWithKeys(fn (Lecturer $lecturer): array => [
            $lecturer->id => ['role' => $session->lecturers->firstWhere('id', $lecturer->id)->pivot->role],
        ])->all());

        $reservation->forceFill(['session_reschedule_id' => $reschedule->id])->save();

        $this->validator->scheduleBlock($session, $meeting->start)->update(['is_active' => false]);
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

    /**
     * @param  list<int>  $lecturerIds
     */
    private function createOnlineMove(
        User $keti,
        ScheduleSession $session,
        CarbonInterface $originalDate,
        ?CarbonImmutable $newStart,
        array $lecturerIds,
        ?string $note,
        string $idempotencyKey,
        ?SessionReschedule $replacing = null,
    ): SessionReschedule {
        $targetStart = $newStart ?? CarbonImmutable::instance($originalDate)->setTimezone(config('app.timezone'))->startOfDay()->addMinutes($session->start_minute);

        ['classGroup' => $classGroup, 'meeting' => $meeting, 'end' => $targetEnd, 'lecturers' => $lecturers] = $this->validator->validateMove(
            $keti,
            $session,
            $originalDate,
            $targetStart,
            $lecturerIds,
            $replacing,
            online: true,
        );

        $trimmedNote = filled($note) ? trim((string) $note) : null;

        $reschedule = SessionReschedule::create([
            'schedule_session_id' => $session->id,
            'course_offering_id' => $session->course_offering_id,
            'session_title' => $session->title,
            'participant_key' => SessionReschedule::participantKey($session->participants->modelKeys()),
            'original_start_at' => $meeting->start,
            'original_end_at' => $meeting->end,
            'original_room_id' => $session->room_id,
            'mode' => RescheduleMode::Online,
            'reservation_id' => null,
            'target_start_at' => $targetStart,
            'target_end_at' => $targetEnd,
            'status' => RescheduleStatus::Active,
            'note' => $trimmedNote,
            'created_by' => $keti->id,
            'idempotency_key' => $idempotencyKey,
        ]);

        $reschedule->lecturers()->attach($lecturers->mapWithKeys(fn (Lecturer $lecturer): array => [
            $lecturer->id => ['role' => $session->lecturers->firstWhere('id', $lecturer->id)->pivot->role],
        ])->all());

        $this->validator->scheduleBlock($session, $meeting->start)->update(['is_active' => false]);
        AvailabilityCache::bust();

        $this->audit->log('meeting.switched_online', $reschedule, null, [
            'original_start_at' => $meeting->start->toDateTimeString(),
            'target_start_at' => $targetStart->toDateTimeString(),
            'lecturer_ids' => $lecturers->modelKeys(),
            'note' => $trimmedNote,
            'replaces' => $replacing?->id,
        ]);

        $sameSlot = $targetStart->equalTo($meeting->start);
        $originalRoomName = $session->room?->name ?? 'ruang terjadwal';
        $summary = $sameSlot
            ? "{$session->title} ".Wita::dayName($meeting->start).', '.Wita::date($meeting->start).' '.Wita::range($meeting->start, $meeting->end)." dialihkan ke Kuliah Online ({$originalRoomName} dikosongkan)."
            : "{$session->title} ".Wita::dayName($meeting->start).', '.Wita::date($meeting->start).' '.Wita::range($meeting->start, $meeting->end)
                .' dipindah ke Kuliah Online, '.Wita::dayName($targetStart).', '.Wita::date($targetStart).' '.Wita::range($targetStart, $targetEnd).'.';

        $this->notifications->notify($this->notifications->admins(), 'meeting.switched_online', "Kuliah Online: {$session->title}", "{$classGroup->name}: {$summary}", ['reschedule_id' => $reschedule->id]);
        $this->notifications->notify(
            $this->notifications->ketisOf($session->participants->modelKeys())->reject(fn (User $user): bool => $user->id === $keti->id),
            'meeting.switched_online',
            'Pertemuan dialihkan ke Online',
            $summary,
            ['reschedule_id' => $reschedule->id],
        );

        return $reschedule->fresh(['originalRoom', 'lecturers']);
    }

    private function findByKey(User $keti, string $idempotencyKey): ?SessionReschedule
    {
        $direct = SessionReschedule::query()
            ->where('created_by', $keti->id)
            ->where('idempotency_key', $idempotencyKey)
            ->with(['reservation.block', 'lecturers', 'originalRoom'])
            ->first();

        if ($direct !== null) {
            return $direct;
        }

        return Reservation::query()
            ->where('created_by', $keti->id)
            ->where('idempotency_key', $idempotencyKey)
            ->whereNotNull('session_reschedule_id')
            ->first()
            ?->sessionReschedule()
            ->with(['reservation.block', 'lecturers', 'originalRoom'])
            ->first();
    }
}
