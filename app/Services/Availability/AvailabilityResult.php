<?php

namespace App\Services\Availability;

use App\Enums\RoomStatus;
use App\Models\Room;
use App\Models\RoomBlock;
use Carbon\CarbonImmutable;

/**
 * Status satu ruang untuk interval [start, end).
 */
final class AvailabilityResult
{
    public function __construct(
        public readonly Room $room,
        public readonly RoomStatus $status,
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        public readonly ?CarbonImmutable $freeUntil,
        public readonly ?CarbonImmutable $busyUntil,
        public readonly ?string $reason,
        public readonly ?RoomBlock $currentBlock,
        public readonly ?RoomBlock $nextBlock,
        public readonly CarbonImmutable $checkedAt,
    ) {}

    public function badge(): string
    {
        return $this->status->badge();
    }

    public function isAvailable(): bool
    {
        return $this->status->isAvailable();
    }

    /**
     * Sisa menit hingga ruang tidak lagi tersedia, dihitung dari waktu acuan.
     */
    public function minutesRemaining(): ?int
    {
        if (! $this->isAvailable() || $this->freeUntil === null) {
            return null;
        }

        return max(0, (int) $this->start->diffInMinutes($this->freeUntil));
    }

    /**
     * Label lokasi seperti di desain: "AE • Lantai 1" / "Gedung belum didata • Lantai 3" (B16).
     */
    public function locationLabel(): string
    {
        $building = $this->room->building?->name ?? 'Gedung belum didata';

        return $building.' • Lantai '.($this->room->floor ?? '—');
    }
}
