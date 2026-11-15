<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Siarkan pembaruan ketersediaan ruang secara realtime saat cache versi berubah.
 */
class RoomAvailabilityUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public int $version,
        public string $updatedAt,
    ) {}

    /**
     * Tentukan channel publik tujuan siaran pembaruan ketersediaan ruang.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel((string) config('sicma.realtime.channel', 'sicma.availability')),
        ];
    }

    /**
     * Nama event yang diterima oleh listener Reverb/Echo di klien.
     */
    public function broadcastAs(): string
    {
        return (string) config('sicma.realtime.event', 'availability.updated');
    }

    /**
     * Muatan ringkas pembaruan versi ketersediaan ruang.
     *
     * @return array{version: int, updated_at: string}
     */
    public function broadcastWith(): array
    {
        return [
            'version' => $this->version,
            'updated_at' => $this->updatedAt,
        ];
    }
}
