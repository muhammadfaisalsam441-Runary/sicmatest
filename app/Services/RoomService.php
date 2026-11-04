<?php

namespace App\Services;

use App\Enums\ReservationStatus;
use App\Enums\RoomType;
use App\Exceptions\DomainException;
use App\Models\Building;
use App\Models\Facility;
use App\Models\Room;
use App\Models\ScheduleSession;
use App\Support\AvailabilityCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Data master ruang: tambah, ubah, hapus (soft delete). Semua perubahan tercatat di audit log (B18).
 */
class RoomService
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * Simpan ruang baru atau perubahan ruang.
     *
     * @param  array{name: string, code: string, type: string, capacity?: int|null, floor?: int|null, building?: string|null, is_bookable?: bool}  $data
     * @param  list<string>  $facilities
     */
    public function save(?Room $room, array $data, array $facilities): Room
    {
        return DB::transaction(function () use ($room, $data, $facilities): Room {
            $before = $room?->only(['name', 'code', 'type', 'capacity', 'floor', 'building_id', 'is_bookable']);

            $room ??= new Room(['is_active' => true]);
            $room->fill([
                'name' => $data['name'],
                'code' => $data['code'],
                'type' => RoomType::from($data['type']),
                'capacity' => $data['capacity'] ?? null,
                'floor' => $data['floor'] ?? null,
                'building_id' => $this->buildingId($data['building'] ?? null),
                'is_bookable' => $data['is_bookable'] ?? true,
            ])->save();

            $room->facilities()->sync(
                collect($facilities)->map(fn (string $name): int => Facility::query()->firstOrCreate(['name' => $name])->id)->all()
            );

            AvailabilityCache::bust();

            $this->audit->log(
                $before === null ? 'room.created' : 'room.updated',
                $room,
                $before,
                $room->only(['name', 'code', 'type', 'capacity', 'floor', 'building_id', 'is_bookable']),
            );

            return $room;
        });
    }

    /**
     * Hapus ruang (soft delete). Ditolak bila masih dipakai reservasi aktif atau jadwal resmi,
     * agar reservasi dan jadwal tidak hilang diam-diam (B10).
     *
     * @throws DomainException ROOM_IN_USE
     */
    public function delete(Room $room, ?string $reason = null): void
    {
        $activeReservations = $room->reservations()
            ->whereIn('status', [ReservationStatus::Booked, ReservationStatus::InUse])
            ->where('end_at', '>', now())
            ->count();

        if ($activeReservations > 0) {
            throw new DomainException('ROOM_IN_USE', "{$room->name} masih punya {$activeReservations} reservasi aktif. Batalkan reservasinya dulu dengan alasan.");
        }

        $officialSessions = ScheduleSession::query()
            ->whereBelongsTo($room)
            ->whereHas('version', fn ($query) => $query->official())
            ->count();

        if ($officialSessions > 0) {
            throw new DomainException('ROOM_IN_USE', "{$room->name} dipakai {$officialSessions} sesi jadwal resmi. Pindahkan sesinya lewat draf jadwal lalu tetapkan sebelum menghapus ruang.");
        }

        DB::transaction(function () use ($room, $reason): void {
            $this->audit->log('room.deleted', $room, $room->only(['name', 'code', 'capacity', 'building_id']), null, $reason);
            $room->forceFill(['is_active' => false])->save();
            $room->delete();
            AvailabilityCache::bust();
        });
    }

    private function buildingId(?string $name): ?int
    {
        if ($name === null || $name === '') {
            return null;
        }

        $building = Building::query()->where('name', $name)->orWhere('code', Str::upper(Str::slug($name)))->first();

        return ($building ?? Building::create(['name' => $name, 'code' => Str::upper(Str::slug($name))]))->id;
    }
}
