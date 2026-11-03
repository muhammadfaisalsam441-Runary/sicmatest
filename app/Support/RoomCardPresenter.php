<?php

namespace App\Support;

use App\Enums\RoomStatus;
use App\Services\Availability\AvailabilityResult;

/**
 * Mengubah hasil AvailabilityService menjadi data kartu ruang.
 * Data ini aman untuk Portal Publik: tidak memuat nama pemesan, NIM, email, atau kelas (B19).
 */
class RoomCardPresenter
{
    /**
     * @return array{id: int, name: string, badge: string, location: string, capacity: int|null, facilities: list<string>, time_label: string|null, time: string|null, note: string|null, remaining: string|null, status: string}
     */
    public static function card(AvailabilityResult $result): array
    {
        $isAvailable = $result->isAvailable() || $result->status === RoomStatus::Uncertain;
        $minutes = $result->minutesRemaining();

        return [
            'id' => $result->room->id,
            'name' => $result->room->name,
            'badge' => $result->badge(),
            'location' => $result->locationLabel(),
            'capacity' => $result->room->capacity,
            'facilities' => $result->room->relationLoaded('facilities') ? $result->room->facilities->pluck('name')->all() : [],
            'time_label' => $isAvailable ? 'Tersedia sampai' : 'Selesai pukul',
            'time' => $isAvailable ? Wita::clock($result->freeUntil) : self::busyTime($result),
            'note' => self::publicNote($result),
            'remaining' => $minutes === null ? null : Wita::duration($minutes).' lagi',
            'status' => $result->status->value,
        ];
    }

    private static function busyTime(AvailabilityResult $result): string
    {
        if ($result->status === RoomStatus::Closed && $result->currentBlock === null) {
            return '—';
        }

        return Wita::clock($result->busyUntil);
    }

    /**
     * Keterangan kecil tanpa data pribadi.
     */
    private static function publicNote(AvailabilityResult $result): ?string
    {
        return match ($result->status) {
            RoomStatus::Available => null,
            RoomStatus::Uncertain, RoomStatus::Closed => $result->reason,
            default => $result->reason ?? $result->status->label(),
        };
    }
}
