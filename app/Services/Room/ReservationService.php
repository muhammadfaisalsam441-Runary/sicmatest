<?php

namespace App\Services\Room;

use App\Enums\ReservationStatus;
use App\Enums\RoomBlockSource;
use App\Exceptions\DomainException;
use App\Models\Academic\ClassGroup;
use App\Models\Reservation\Reservation;
use App\Models\Room\Room;
use App\Models\Room\RoomBlock;
use App\Models\User\User;
use App\Services\Account\AuditLogger;
use App\Services\Account\NotificationService;
use App\Support\AvailabilityCache;
use App\Support\Wita;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Siklus hidup reservasi: create (siapa cepat dia dapat), start, finish, cancel.
 *
 * Pemeriksaan bentrok dan penyimpanan berjalan dalam satu transaksi dengan row lock pada ruang (B07),
 * kelas diambil dari akun Keti di server (B02), dan klik ulang aman berkat idempotency key (B08).
 */
class ReservationService
{
    public function __construct(
        private AvailabilityService $availability,
        private AuditLogger $audit,
        private NotificationService $notifications,
    ) {}

    /**
     * Pesan ruang atas nama kelas Keti.
     *
     * @throws DomainException ACCOUNT_NOT_APPROVED | NOT_CLASS_REPRESENTATIVE | ROOM_CLOSED | ROOM_CONFLICT |
     *                         OUTSIDE_OPERATING_HOURS | INVALID_DURATION | INVALID_TIME | CAPACITY_EXCEEDED | TOO_MANY_ACTIVE_RESERVATIONS |
     *                         CLASS_TIME_CONFLICT
     */
    public function create(User $keti, Room $room, CarbonInterface $start, CarbonInterface $end, string $purpose, ?int $participants, string $idempotencyKey): Reservation
    {
        $start = $this->normalize($start);
        $end = $this->normalize($end);

        if ($existing = $this->findByIdempotencyKey($keti, $idempotencyKey)) {
            return $existing;
        }

        // Dibaca di luar transaksi agar baris kelas bisa dikunci sebelum snapshot terbentuk.
        $classGroupId = $keti->activeClassGroup()?->id;

        try {
            $reservation = DB::transaction(function () use ($keti, $room, $start, $end, $purpose, $participants, $idempotencyKey, $classGroupId): Reservation {
                // Lock ruang dan kelas harus menjadi perintah pertama: pada REPEATABLE READ, snapshot transaksi
                // terbentuk oleh pembacaan biasa pertama, sehingga pemeriksaan sesudah lock melihat data terbaru.
                // Lock kelas mencegah dua pesanan kelas yang sama (di ruang berbeda) lolos bersamaan dari
                // batas reservasi aktif dan pemeriksaan bentrok jadwal kelas.
                $lockedRoom = Room::query()->whereKey($room->id)->lockForUpdate()->firstOrFail();

                if ($classGroupId !== null) {
                    ClassGroup::query()->whereKey($classGroupId)->lockForUpdate()->first();
                }

                if ($existing = $this->findByIdempotencyKey($keti, $idempotencyKey)) {
                    return $existing;
                }

                $classGroup = $this->assertCanReserve($keti);

                if ($classGroup->id !== $classGroupId) {
                    // Keterhubungan kelas berubah di tengah permintaan; kunci yang diambil bukan milik kelas ini.
                    throw new DomainException('ACCOUNT_NOT_APPROVED', 'Kelas akunmu baru saja berubah. Muat ulang halaman lalu coba lagi.');
                }

                $this->assertAcademicBookingDay($purpose, $start);
                $this->assertValidRequest($lockedRoom, $start, $end, $participants, $classGroup->id);
                $this->assertClassFree($classGroup, $start, $end);
                $this->assertRoomFree($lockedRoom, $start, $end);

                $reservation = Reservation::create([
                    'room_id' => $lockedRoom->id,
                    'class_group_id' => $classGroup->id,
                    'created_by' => $keti->id,
                    'start_at' => $start,
                    'end_at' => $end,
                    'participants' => $participants,
                    'purpose' => $purpose,
                    'status' => ReservationStatus::Booked,
                    'idempotency_key' => $idempotencyKey,
                ]);

                RoomBlock::create([
                    'room_id' => $lockedRoom->id,
                    'start_at' => $start,
                    'end_at' => $end,
                    'source_type' => RoomBlockSource::Reservation,
                    'source_id' => $reservation->id,
                    'is_active' => true,
                ]);

                AvailabilityCache::bust();

                $this->audit->log('reservation.created', $reservation, null, $reservation->only(['room_id', 'class_group_id', 'start_at', 'end_at', 'purpose', 'status']));

                $this->notifications->notify(
                    $keti,
                    'reservation.booked',
                    'Ruangan berhasil dipesan',
                    "{$lockedRoom->name} dipesan untuk {$classGroup->name}, ".Wita::date($start).' '.Wita::range($start, $end).'.',
                    ['reservation_id' => $reservation->id],
                );

                $this->notifications->notify(
                    $this->notifications->admins(),
                    'reservation.booked',
                    "Reservasi baru: {$lockedRoom->name}",
                    "{$classGroup->name} memesan {$lockedRoom->name} pada ".Wita::date($start).', '.Wita::range($start, $end)." untuk {$purpose}.",
                    ['reservation_id' => $reservation->id],
                );

                return $reservation;
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            // Klik ulang yang datang bersamaan: reservasi dengan kunci yang sama sudah tersimpan.
            return $this->findByIdempotencyKey($keti, $idempotencyKey)
                ?? throw new DomainException('ROOM_CONFLICT', 'Ruangan sudah lebih dulu dipesan kelas lain untuk jam tersebut.');
        }

        return $reservation->fresh(['room', 'classGroup']);
    }

    /**
     * Mulai gunakan: Dipesan → Digunakan dalam jendela check-in. Menekan dua kali tidak mengubah waktu mulai.
     */
    public function start(Reservation $reservation, User $actor): Reservation
    {
        return DB::transaction(function () use ($reservation): Reservation {
            $reservation = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();

            if ($reservation->status === ReservationStatus::InUse) {
                return $reservation;
            }

            $this->assertTransition($reservation, ReservationStatus::Booked, ReservationStatus::InUse);
            $this->assertAcademicBookingDay($reservation->purpose, $reservation->start_at);

            $now = CarbonImmutable::now();
            $opensAt = CarbonImmutable::instance($reservation->start_at)->subMinutes((int) config('sicma.reservation.checkin_open_minutes_before'));
            $closesAt = CarbonImmutable::instance($reservation->start_at)->addMinutes((int) config('sicma.reservation.checkin_grace_minutes'));

            if ($now->lessThan($opensAt) || $now->greaterThan($closesAt) || $now->greaterThanOrEqualTo($reservation->end_at)) {
                throw new DomainException('CHECKIN_WINDOW_CLOSED', 'Mulai gunakan hanya bisa ditekan '.Wita::clock($opensAt, false).'–'.Wita::clock($closesAt->min($reservation->end_at)).'.', [
                    'opens_at' => $opensAt->toIso8601String(),
                    'closes_at' => $closesAt->toIso8601String(),
                ]);
            }

            $before = $reservation->only(['status', 'actual_start_at']);
            $reservation->forceFill([
                'status' => ReservationStatus::InUse,
                'actual_start_at' => $now,
                'lock_version' => $reservation->lock_version + 1,
            ])->save();

            AvailabilityCache::bust();

            $this->audit->log('reservation.started', $reservation, $before, $reservation->only(['status', 'actual_start_at']));

            return $reservation;
        });
    }

    /**
     * Selesai menggunakan: Digunakan → Selesai. Blok ruang dipendekkan ke waktu selesai aktual (B13).
     */
    public function finish(Reservation $reservation, User $actor): Reservation
    {
        return DB::transaction(function () use ($reservation): Reservation {
            $reservation = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();

            if ($reservation->status === ReservationStatus::Finished) {
                return $reservation;
            }

            $this->assertTransition($reservation, ReservationStatus::InUse, ReservationStatus::Finished);

            $now = CarbonImmutable::now();
            $before = $reservation->only(['status', 'actual_end_at']);

            $reservation->forceFill([
                'status' => ReservationStatus::Finished,
                'actual_end_at' => $now,
                'lock_version' => $reservation->lock_version + 1,
            ])->save();

            $block = $reservation->block()->first();

            if ($block !== null && $now->lessThan($block->end_at)) {
                $block->update(['end_at' => $now->max($block->start_at)]);
            }

            AvailabilityCache::bust();

            $this->audit->log('reservation.finished', $reservation, $before, $reservation->only(['status', 'actual_end_at']));

            $this->notifications->notify(
                $reservation->creator,
                'reservation.finished',
                'Pemakaian sudah tercatat',
                'Terima kasih. Pemakaian '.$reservation->room->name.' selesai pukul '.Wita::clock($now).'.',
                ['reservation_id' => $reservation->id],
            );

            $this->notifications->notify(
                $this->notifications->admins(),
                'reservation.finished',
                'Penggunaan '.$reservation->room->name.' selesai',
                $reservation->creator->name.' ('.$reservation->classGroup->name.') menyelesaikan penggunaan '.$reservation->room->name.' pukul '.Wita::clock($now).'.',
                ['reservation_id' => $reservation->id],
            );

            return $reservation;
        });
    }

    /**
     * Batalkan reservasi berstatus Dipesan. Slot terbuka lagi, riwayat tetap tersimpan (B12).
     */
    public function cancel(Reservation $reservation, User $actor, ?string $reason = null): Reservation
    {
        if ($actor->isAdmin() && blank($reason)) {
            throw new DomainException('DATA_INCOMPLETE', 'Alasan pembatalan wajib diisi oleh admin.');
        }

        return DB::transaction(function () use ($reservation, $actor, $reason): Reservation {
            $reservation = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();

            if ($reservation->status === ReservationStatus::Cancelled) {
                return $reservation;
            }

            $this->assertTransition($reservation, ReservationStatus::Booked, ReservationStatus::Cancelled);

            $before = $reservation->only(['status']);
            $reservation->forceFill([
                'status' => ReservationStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
                'cancel_reason' => $reason,
                'lock_version' => $reservation->lock_version + 1,
            ])->save();

            $reservation->block()->update(['is_active' => false]);

            AvailabilityCache::bust();

            $this->audit->log('reservation.cancelled', $reservation, $before, $reservation->only(['status', 'cancelled_at', 'cancelled_by']), $reason);

            if ($actor->id !== $reservation->created_by) {
                $this->notifications->notify(
                    $reservation->creator,
                    'reservation.cancelled',
                    'Reservasi dibatalkan admin',
                    $reservation->room->name.' '.Wita::date($reservation->start_at).' '.Wita::range($reservation->start_at, $reservation->end_at).' dibatalkan. Alasan: '.$reason,
                    ['reservation_id' => $reservation->id],
                );
            } else {
                $this->notifications->notify(
                    $this->notifications->admins(),
                    'reservation.cancelled',
                    'Reservasi dibatalkan: '.$reservation->room->name,
                    $reservation->classGroup->name.' membatalkan reservasi '.Wita::date($reservation->start_at).', '.Wita::range($reservation->start_at, $reservation->end_at).'.',
                    ['reservation_id' => $reservation->id],
                );
            }

            return $reservation;
        });
    }

    /**
     * Perpanjang pemakaian yang sedang berlangsung selama tidak bentrok dan masih dalam batas durasi/jam operasional.
     */
    public function extend(Reservation $reservation, User $actor, ?int $minutes = null): Reservation
    {
        $minutes ??= (int) config('sicma.reservation.extend_minutes');

        return DB::transaction(function () use ($reservation, $minutes): Reservation {
            Room::query()->whereKey($reservation->room_id)->lockForUpdate()->first();
            $reservation = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            ClassGroup::query()->whereKey($reservation->class_group_id)->lockForUpdate()->first();

            if ($reservation->status !== ReservationStatus::InUse) {
                throw new DomainException('INVALID_STATUS_TRANSITION', 'Hanya pemakaian yang sedang berlangsung yang bisa diperpanjang.');
            }

            $this->assertAcademicBookingDay($reservation->purpose, $reservation->start_at);

            $start = CarbonImmutable::instance($reservation->start_at);
            $currentEnd = CarbonImmutable::instance($reservation->end_at);
            $newEnd = $currentEnd->addMinutes($minutes);
            $max = (int) config('sicma.reservation.max_minutes');
            [, $close] = $this->availability->operatingWindow($start);

            if ((int) $start->diffInMinutes($newEnd) > $max) {
                throw new DomainException('INVALID_DURATION', "Total pemakaian maksimal {$max} menit.");
            }

            if ($newEnd->greaterThan($close)) {
                throw new DomainException('OUTSIDE_OPERATING_HOURS', 'Perpanjangan melewati jam operasional ('.Wita::clock($close).').');
            }

            $conflicts = $this->availability->blocksFor([$reservation->room_id], $currentEnd, $newEnd, lock: true)
                ->get($reservation->room_id, collect())
                ->reject(fn (RoomBlock $block): bool => $block->source_type === RoomBlockSource::Reservation && $block->source_id === $reservation->id);

            if ($conflicts->isNotEmpty()) {
                throw new DomainException('ROOM_CONFLICT', 'Tidak bisa diperpanjang: ruangan dipakai mulai '.Wita::clock($conflicts->min('start_at')).'.');
            }

            $this->assertClassFree($reservation->classGroup, $currentEnd, $newEnd, ignoreReservationId: $reservation->id, hint: 'Tidak bisa diperpanjang.');

            $before = ['end_at' => $currentEnd->toDateTimeString()];
            $reservation->forceFill(['end_at' => $newEnd, 'lock_version' => $reservation->lock_version + 1])->save();
            $reservation->block()->update(['end_at' => $newEnd]);

            AvailabilityCache::bust();

            $this->audit->log('reservation.extended', $reservation, $before, ['end_at' => $newEnd->toDateTimeString()]);

            return $reservation;
        });
    }

    /**
     * Batasi booking perkuliahan ke hari kuliah, termasuk untuk reservasi lama.
     */
    /**
     * Kegiatan yang paling akhir dipakai kelas ini, untuk mengisi pilihan bawaan saat memesan.
     */
    public function lastPurpose(ClassGroup $classGroup): ?string
    {
        return Reservation::query()
            ->where('class_group_id', $classGroup->id)
            ->whereIn('purpose', config('sicma.reservation.purposes'))
            ->latest('created_at')
            ->latest('id')
            ->value('purpose');
    }

    /**
     * Apakah kegiatan akademik (Kuliah Pengganti, Praktikum Tambahan) boleh dipilih pada tanggal ini.
     */
    public static function allowsAcademicPurposes(CarbonInterface $start): bool
    {
        return in_array($start->dayOfWeekIso, config('sicma.scheduler.days'), true);
    }

    private function assertAcademicBookingDay(string $purpose, CarbonInterface $start): void
    {
        $academicPurposes = array_map(fn (string $value): string => Str::lower(Str::squish($value)), config('sicma.reservation.academic_purposes'));
        if (in_array(Str::lower(Str::squish($purpose)), $academicPurposes, true)
            && ! self::allowsAcademicPurposes($start)) {
            throw new DomainException('INVALID_TEACHING_DAY', 'Kuliah Pengganti dan Praktikum Tambahan hanya boleh dilaksanakan Senin–Jumat. Pilih hari kuliah atau kegiatan umum yang sesuai.');
        }
    }

    /**
     * Kelas aktif Keti dari server, bukan dari request.
     */
    private function assertCanReserve(User $keti): ClassGroup
    {
        if (! $keti->isKeti()) {
            throw new DomainException('NOT_CLASS_REPRESENTATIVE', 'Hanya ketua tingkat yang dapat memesan ruangan.');
        }

        if (! $keti->isApprovedKeti()) {
            throw new DomainException('ACCOUNT_NOT_APPROVED', 'Akun Keti belum disetujui atau belum terhubung ke kelas aktif.');
        }

        return $keti->activeClassGroup();
    }

    private function assertValidRequest(Room $room, CarbonImmutable $start, CarbonImmutable $end, ?int $participants, int $classGroupId): void
    {
        if (! $room->is_active || ! $room->is_bookable) {
            throw new DomainException('ROOM_CLOSED', 'Ruangan ini sedang tidak dapat dipesan.');
        }

        $duration = (int) $start->diffInMinutes($end, false);
        $min = (int) config('sicma.reservation.min_minutes');
        $max = (int) config('sicma.reservation.max_minutes');

        if ($duration < $min || $duration > $max) {
            throw new DomainException('INVALID_DURATION', "Durasi pemakaian harus {$min}–{$max} menit.", ['min' => $min, 'max' => $max]);
        }

        if ($start->lessThan(CarbonImmutable::now()->subMinutes(5))) {
            throw new DomainException('INVALID_TIME', 'Waktu mulai sudah lewat. Pilih waktu lain.');
        }

        if ($start->greaterThan(CarbonImmutable::now()->addDays((int) config('sicma.reservation.max_days_ahead'))->endOfDay())) {
            throw new DomainException('INVALID_TIME', 'Ruangan hanya bisa dipesan paling lambat '.config('sicma.reservation.max_days_ahead').' hari ke depan.');
        }

        [$open, $close] = $this->availability->operatingWindow($start);

        if ($start->lessThan($open) || $end->greaterThan($close)) {
            throw new DomainException('OUTSIDE_OPERATING_HOURS', 'Ruangan hanya bisa dipesan pukul '.Wita::clock($open, false).'–'.Wita::clock($close).'.');
        }

        if ($participants !== null && $room->capacity !== null && $participants > $room->capacity) {
            throw new DomainException('CAPACITY_EXCEEDED', "Kapasitas {$room->name} hanya {$room->capacity} kursi.", ['capacity' => $room->capacity]);
        }

        $activeCount = Reservation::query()
            ->where('class_group_id', $classGroupId)
            ->whereIn('status', [ReservationStatus::Booked, ReservationStatus::InUse])
            ->where('end_at', '>', now())
            ->count();

        if ($activeCount >= (int) config('sicma.reservation.max_active_per_class')) {
            throw new DomainException('TOO_MANY_ACTIVE_RESERVATIONS', 'Kelasmu sudah memiliki '.$activeCount.' reservasi aktif. Selesaikan atau batalkan salah satunya dulu.');
        }
    }

    /**
     * Satu kelas tidak boleh memegang dua reservasi aktif yang waktunya beririsan, walau di ruang berbeda.
     */
    private function assertClassFree(
        ClassGroup $classGroup,
        CarbonImmutable $start,
        CarbonImmutable $end,
        ?int $ignoreReservationId = null,
        string $hint = 'Batalkan pesanan itu atau pilih jam lain.',
    ): void {
        $clash = Reservation::query()
            ->whereBelongsTo($classGroup)
            ->whereIn('status', [ReservationStatus::Booked, ReservationStatus::InUse])
            ->where('start_at', '<', $end)
            ->where('end_at', '>', $start)
            ->when($ignoreReservationId !== null, fn ($query) => $query->whereKeyNot($ignoreReservationId))
            ->with('room')
            ->orderBy('start_at')
            ->first();

        if ($clash === null) {
            return;
        }

        throw new DomainException(
            'CLASS_TIME_CONFLICT',
            "Kelas {$classGroup->name} sudah memesan {$clash->room->name} pukul ".Wita::range($clash->start_at, $clash->end_at).'. '.$hint,
            ['reservation_id' => $clash->id],
        );
    }

    /**
     * Ruang harus bebas sepanjang durasi dari jadwal resmi, reservasi aktif, dan penutupan (B03, B05, B06).
     */
    private function assertRoomFree(Room $room, CarbonImmutable $start, CarbonImmutable $end): void
    {
        if (! $this->availability->hasOfficialScheduleOn($start)) {
            throw new DomainException('SCHEDULE_UNCERTAIN', 'Jadwal resmi belum ditetapkan untuk tanggal ini. Ketersediaan ruang belum dapat dipastikan.');
        }

        $conflicts = $this->availability->blocksFor([$room->id], $start, $end, lock: true)->get($room->id, collect());

        if ($conflicts->isEmpty()) {
            return;
        }

        $details = [
            'room_id' => $room->id,
            'conflict_until' => CarbonImmutable::instance($conflicts->max('end_at'))->toIso8601String(),
        ];

        $closure = $conflicts->firstWhere('source_type', RoomBlockSource::Closure);
        if ($closure !== null) {
            throw new DomainException('ROOM_CLOSED', 'Ruangan sedang ditutup sampai '.Wita::stamp($closure->end_at).'.', $details);
        }

        // Pesan menyebut sumber bentrok: kuliah terjadwal atau reservasi kelas lain.
        $schedule = $conflicts->where('source_type', RoomBlockSource::Schedule)->sortBy('start_at')->first();
        if ($schedule !== null) {
            throw new DomainException('ROOM_CONFLICT', 'Ada kuliah terjadwal di ruangan ini pukul '.Wita::range($schedule->start_at, $schedule->end_at).'. Pilih jam atau ruangan lain.', $details + ['source' => 'schedule']);
        }

        $reservation = $conflicts->sortBy('start_at')->first();

        throw new DomainException('ROOM_CONFLICT', 'Ruangan sudah lebih dulu dipesan kelas lain untuk jam tersebut ('.Wita::range($reservation->start_at, $reservation->end_at).').', $details + ['source' => 'reservation']);
    }

    private function assertTransition(Reservation $reservation, ReservationStatus $from, ReservationStatus $to): void
    {
        if ($reservation->status !== $from) {
            throw new DomainException('INVALID_STATUS_TRANSITION', "Reservasi berstatus {$reservation->status->label()} tidak bisa diubah menjadi {$to->label()}.");
        }
    }

    private function findByIdempotencyKey(User $keti, string $key): ?Reservation
    {
        return Reservation::query()
            ->where('created_by', $keti->id)
            ->where('idempotency_key', $key)
            ->first();
    }

    private function normalize(CarbonInterface $time): CarbonImmutable
    {
        return CarbonImmutable::instance($time)->setTimezone(config('app.timezone'))->startOfMinute();
    }
}
