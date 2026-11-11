<?php

namespace App\Services\Room;

use App\Enums\ReservationStatus;
use App\Enums\RoomType;
use App\Enums\ScheduleVersionStatus;
use App\Exceptions\DomainException;
use App\Models\Room\Building;
use App\Models\Room\Facility;
use App\Models\Room\Room;
use App\Models\Scheduling\ScheduleSession;
use App\Services\Account\AuditLogger;
use App\Support\AvailabilityCache;
use Illuminate\Database\Eloquent\Builder;
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
     * Aksi massal Kelola Ruang. Dijalankan dalam satu transaksi dan setiap ruang yang berubah dicatat di audit log (B17).
     *
     * @param  list<int>  $roomIds
     * @param  array{building?: string|null, type?: string|null, facilities?: string|null, capacity?: int|null}  $data
     * @return int Jumlah ruang yang diproses
     */
    public function batchUpdate(array $roomIds, string $action, array $data): int
    {
        return DB::transaction(function () use ($roomIds, $action, $data): int {
            $rooms = Room::query()->whereIn('id', $roomIds)->with('facilities')->lockForUpdate()->get();
            $facilityIds = [];

            if ($action === 'add_facilities') {
                $facilityIds = collect(explode(',', (string) ($data['facilities'] ?? '')))
                    ->map(fn (string $name): string => Str::of($name)->squish()->ucfirst()->toString())
                    ->filter()
                    ->unique()
                    ->map(fn (string $name): int => Facility::query()->firstOrCreate(['name' => $name])->id)
                    ->all();
            }

            $buildingId = $action === 'set_building' ? $this->buildingId(trim((string) ($data['building'] ?? '')) ?: null) : null;

            foreach ($rooms as $room) {
                $before = $room->only(['building_id', 'type', 'capacity']) + ['facilities' => $room->facilities->pluck('name')->sort()->values()->all()];

                match ($action) {
                    'set_building' => $room->forceFill(['building_id' => $buildingId])->save(),
                    'set_type' => $room->forceFill(['type' => RoomType::from((string) $data['type'])])->save(),
                    'set_capacity' => $room->forceFill(['capacity' => (int) $data['capacity']])->save(),
                    'add_facilities' => $room->facilities()->syncWithoutDetaching($facilityIds),
                };

                $room->load('facilities');
                $after = $room->only(['building_id', 'type', 'capacity']) + ['facilities' => $room->facilities->pluck('name')->sort()->values()->all()];
                $this->audit->log('room.batch_'.$action, $room, $before, $after);
            }

            AvailabilityCache::bust();

            return $rooms->count();
        });
    }

    /**
     * Edit massal langsung di tabel (Inline Grid).
     *
     * @param  list<array{id: int, name: string, type: string, building?: string|null, floor?: int|string|null, capacity?: int|string|null}>  $items
     * @return int Jumlah ruang yang disimpan
     */
    public function bulkUpdate(array $items): int
    {
        return DB::transaction(function () use ($items): int {
            $updatedCount = 0;

            foreach ($items as $item) {
                $room = Room::query()->find($item['id']);
                if (! $room) {
                    continue;
                }

                $before = $room->only(['name', 'code', 'type', 'capacity', 'floor', 'building_id']);
                $name = Str::squish((string) $item['name']);
                $buildingName = Str::squish((string) ($item['building'] ?? '')) ?: null;
                $floor = is_numeric($item['floor'] ?? null) ? (int) $item['floor'] : null;
                $capacity = is_numeric($item['capacity'] ?? null) && (int) $item['capacity'] > 0
                    ? (int) $item['capacity']
                    : null;

                $code = $room->code;
                if ($name !== $room->name) {
                    $baseCode = Str::upper(Str::slug($name));
                    $code = $baseCode !== '' ? $baseCode : $room->code;
                    if (Room::withTrashed()->where('code', $code)->where('id', '!=', $room->id)->exists()) {
                        $code = $code.'-'.$room->id;
                    }
                }

                $room->forceFill([
                    'name' => $name,
                    'code' => $code,
                    'type' => RoomType::from((string) $item['type']),
                    'building_id' => $this->buildingId($buildingName),
                    'floor' => $floor,
                    'capacity' => $capacity,
                ])->save();

                $after = $room->only(['name', 'code', 'type', 'capacity', 'floor', 'building_id']);
                if ($before !== $after) {
                    $this->audit->log('room.bulk_updated', $room, $before, $after);
                }

                $updatedCount++;
            }

            AvailabilityCache::bust();

            return $updatedCount;
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

        // Jadwal resmi, jadwal lama yang masih berlaku sampai effective_to, dan draf yang belum ditetapkan.
        $sessions = ScheduleSession::query()
            ->whereBelongsTo($room)
            ->whereHas('version', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('status', ScheduleVersionStatus::Official)
                ->orWhere(fn (Builder $query) => $query->where('status', ScheduleVersionStatus::Superseded)
                    ->where(fn (Builder $query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', now()->toDateString())))
                ->orWhereIn('status', [ScheduleVersionStatus::Draft, ScheduleVersionStatus::Ready])))
            ->with('version:id,version_no,status')
            ->get(['id', 'schedule_version_id']);

        if ($sessions->isNotEmpty()) {
            $versions = $sessions->pluck('version')->unique('id')->sortBy('version_no')
                ->map(fn ($version): string => match ($version->status) {
                    ScheduleVersionStatus::Official => "jadwal resmi v{$version->version_no}",
                    ScheduleVersionStatus::Superseded => "jadwal v{$version->version_no} (masih berlaku)",
                    default => "draf v{$version->version_no}",
                })->implode(', ');

            throw new DomainException('ROOM_IN_USE', "{$room->name} masih dipakai {$sessions->count()} sesi di {$versions}. Pindahkan sesinya lebih dulu sebelum menghapus ruang.");
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
