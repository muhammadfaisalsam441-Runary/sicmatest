<?php

namespace Database\Seeders;

use App\Enums\ReservationStatus;
use App\Enums\RoomBlockSource;
use App\Enums\UserRole;
use App\Models\Reservation\Reservation;
use App\Models\Room\Room;
use App\Models\Room\RoomBlock;
use App\Models\Room\RoomClosure;
use App\Models\User\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * DATA DEMO — reservasi berbagai status relatif terhadap waktu seeding, satu penutupan ruang,
 * dan beberapa notifikasi. Setiap reservasi ditempatkan di ruang yang bebas dari room_blocks aktif.
 */
class ReservationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $now = CarbonImmutable::now()->startOfMinute();
        $now = $now->setTime($now->hour, intdiv($now->minute, 5) * 5);

        $admin = User::query()->where('role', UserRole::Admin)->firstOrFail();
        $dimas = $this->keti('dimas.pratama@sicma.test');
        $nadia = $this->keti('nadia.putri@sicma.test');
        $rizky = $this->keti('rizky.ramadhan@sicma.test');

        $closureRoom = Room::query()->where('code', 'LAB-ANIMASI')->firstOrFail();
        $closure = RoomClosure::create([
            'room_id' => $closureRoom->id,
            'start_at' => $now->startOfDay()->setTime(7, 30),
            'end_at' => $now->addDays(3)->setTime(17, 30),
            'reason' => 'Perawatan perangkat komputer',
            'created_by' => $admin->id,
        ]);
        RoomBlock::create([
            'room_id' => $closureRoom->id,
            'start_at' => $closure->start_at,
            'end_at' => $closure->end_at,
            'source_type' => RoomBlockSource::Closure,
            'source_id' => $closure->id,
            'is_active' => true,
        ]);

        $inUseStart = $now->subMinutes(30);
        $inUse = $this->reserve($dimas, ['AE-106', 'AE-105', 'AE-215', 'LAB-ICT-04'], $inUseStart, $inUseStart->addHour(), 'Diskusi kelompok Pemrograman Web', ReservationStatus::InUse, [
            'actual_start_at' => $inUseStart->addMinutes(5),
        ]);

        $bookedStart = $now->addHours(2)->startOfHour();
        $this->reserve($nadia, ['AE-105', 'AE-103', 'PINISI-C2-R4'], $bookedStart, $bookedStart->addHour(), 'Latihan presentasi Desain Antarmuka', ReservationStatus::Booked);

        $tomorrow = $now->addDay()->setTime(10, 0);
        $this->reserve($rizky, ['AE-215', 'AE-213', 'PINISI-C2-R4'], $tomorrow, $tomorrow->addMinutes(90), 'Kelas pengganti Struktur Data', ReservationStatus::Booked);

        $yesterday = $now->subDay()->setTime(13, 0);
        $this->reserve($dimas, ['AE-107', 'AE-105', 'AE-215'], $yesterday, $yesterday->addHour(), 'Belajar bersama Jaringan Komputer', ReservationStatus::Finished, [
            'actual_start_at' => $yesterday->addMinutes(5),
            'actual_end_at' => $yesterday->addMinutes(50),
        ]);

        $twoDaysAgo = $now->subDays(2)->setTime(15, 40);
        $this->reserve($nadia, ['PINISI-C2-R4', 'AE-215'], $twoDaysAgo, $twoDaysAgo->addHour(), 'Rapat kelas PTIK B', ReservationStatus::Finished, [
            'actual_start_at' => $twoDaysAgo->addMinutes(5),
            'actual_end_at' => $twoDaysAgo->addMinutes(50),
        ]);

        $cancelledStart = $now->addHours(4)->startOfHour();
        $this->reserve($rizky, ['AE-102', 'AE-215', 'PINISI-C2-R4'], $cancelledStart, $cancelledStart->addHour(), 'Kerja kelompok Basis Data', ReservationStatus::Cancelled, [
            'cancelled_at' => $now->subHour(),
            'cancelled_by' => $rizky->id,
            'cancel_reason' => 'Kegiatan dipindahkan ke minggu depan.',
        ]);

        $dimas->appNotifications()->create([
            'type' => 'reservation.booked',
            'title' => 'Ruangan berhasil dipesan',
            'body' => "{$inUse->room->name} dipesan untuk Tekom C pukul {$inUse->start_at->format('H:i')}–{$inUse->end_at->format('H:i')} WITA.",
            'data' => ['reservation_id' => $inUse->id],
        ]);

        $admin->appNotifications()->create([
            'type' => 'keti_application.submitted',
            'title' => 'Pengajuan Keti baru',
            'body' => 'Andi Saputra mengajukan akun Keti untuk PTIK A.',
            'data' => [],
        ]);
    }

    private function keti(string $email): User
    {
        return User::query()
            ->where('email', $email)
            ->with('classRepresentatives')
            ->firstOrFail();
    }

    /**
     * Buat reservasi beserta blok ruangnya di ruang pertama yang bebas.
     *
     * @param  list<string>  $preferredRoomCodes
     * @param  array<string, mixed>  $attributes
     */
    private function reserve(User $keti, array $preferredRoomCodes, CarbonImmutable $start, CarbonImmutable $end, string $purpose, ReservationStatus $status, array $attributes = []): Reservation
    {
        $room = $this->firstFreeRoom($preferredRoomCodes, $start, $end);
        $classGroupId = $keti->classRepresentatives->firstOrFail()->class_group_id;

        $reservation = Reservation::create([
            'room_id' => $room->id,
            'class_group_id' => $classGroupId,
            'created_by' => $keti->id,
            'start_at' => $start,
            'end_at' => $end,
            'participants' => 30,
            'purpose' => $purpose,
            'status' => $status,
            'idempotency_key' => (string) Str::uuid(),
            ...$attributes,
        ]);

        RoomBlock::create([
            'room_id' => $room->id,
            'start_at' => $start,
            'end_at' => $reservation->actual_end_at ?? $end,
            'source_type' => RoomBlockSource::Reservation,
            'source_id' => $reservation->id,
            'is_active' => $status !== ReservationStatus::Cancelled,
        ]);

        return $reservation;
    }

    /**
     * @param  list<string>  $preferredRoomCodes
     */
    private function firstFreeRoom(array $preferredRoomCodes, CarbonImmutable $start, CarbonImmutable $end): Room
    {
        $preferred = Room::query()->whereIn('code', $preferredRoomCodes)->get()
            ->sortBy(fn (Room $room): int => array_search($room->code, $preferredRoomCodes, true));

        $candidates = $preferred->concat(
            Room::query()->where('is_bookable', true)->whereNotIn('code', $preferredRoomCodes)->orderBy('code')->get()
        );

        foreach ($candidates as $room) {
            $isBusy = RoomBlock::query()
                ->whereBelongsTo($room)
                ->active()
                ->overlapping($start, $end)
                ->exists();

            if (! $isBusy) {
                return $room;
            }
        }

        throw new RuntimeException('Tidak ada ruang bebas untuk data demo reservasi.');
    }
}
