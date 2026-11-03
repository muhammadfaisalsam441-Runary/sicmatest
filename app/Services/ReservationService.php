<?php

namespace App\Services;

use App\Enums\ReservationStatus;
use App\Enums\RoomBlockSource;
use App\Exceptions\DomainException;
use App\Models\ClassGroup;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\User;
use App\Support\AvailabilityCache;
use App\Support\Wita;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

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
     *                         OUTSIDE_OPERATING_HOURS | INVALID_DURATION | INVALID_TIME | CAPACITY_EXCEEDED | TOO_MANY_ACTIVE_RESERVATIONS
     */
    public function create(User $keti, Room $room, CarbonInterface $start, CarbonInterface $end, string $purpose, ?int $participants, string $idempotencyKey): Reservation
    {
        $start = $this->normalize($start);
        $end = $this->normalize($end);

        if ($existing = $this->findByIdempotencyKey($keti, $idempotencyKey)) {
            return $existing;
        }

        try {
            $reservation = DB::transaction(function () use ($keti, $room, $start, $end, $purpose, $participants, $idempotencyKey): Reservation {
                // Lock ruang harus menjadi perintah pertama: pada REPEATABLE READ, snapshot transaksi
                // terbentuk oleh pembacaan pertama, sehingga pemeriksaan sesudah lock melihat data terbaru.
                $lockedRoom = Room::query()->whereKey($room->id)->lockForUpdate()->firstOrFail();

                if ($existing = $this->findByIdempotencyKey($keti, $idempotencyKey)) {
                    return $existing;
                }

                $classGroup = $this->assertCanReserve($keti);

                $this->assertValidRequest($lockedRoom, $start, $end, $participants, $classGroup->id);
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

            if ($reservation->status !== ReservationStatus::InUse) {
                throw new DomainException('INVALID_STATUS_TRANSITION', 'Hanya pemakaian yang sedang berlangsung yang bisa diperpanjang.');
            }

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

            $before = ['end_at' => $currentEnd->toDateTimeString()];
            $reservation->forceFill(['end_at' => $newEnd, 'lock_version' => $reservation->lock_version + 1])->save();
            $reservation->block()->update(['end_at' => $newEnd]);

            AvailabilityCache::bust();

            $this->audit->log('reservation.extended', $reservation, $before, ['end_at' => $newEnd->toDateTimeString()]);

            return $reservation;
        });
    }

    /**
     * Kelas aktif Keti dari server, bukan dari request (B02, B20).
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
     * Ruang harus bebas sepanjang durasi dari jadwal resmi, reservasi aktif, dan penutupan (B03, B05, B06).
     */
    private function assertRoomFree(Room $room, CarbonImmutable $start, CarbonImmutable $end): void
    {
        $conflicts = $this->availability->blocksFor([$room->id], $start, $end, lock: true)->get($room->id, collect());

        if ($conflicts->isEmpty()) {
            return;
        }

        if ($conflicts->contains('source_type', RoomBlockSource::Closure)) {
            throw new DomainException('ROOM_CLOSED', 'Ruangan sedang ditutup pada jam tersebut.');
        }

        throw new DomainException('ROOM_CONFLICT', 'Ruangan sudah lebih dulu dipesan kelas lain untuk jam tersebut.', [
            'room_id' => $room->id,
            'conflict_until' => CarbonImmutable::instance($conflicts->max('end_at'))->toIso8601String(),
        ]);
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
