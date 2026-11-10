<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Data ruang + waktu yang dikirim tombol "Pesan" ke modal konfirmasi pemesanan (event open-booking).
 * Hanya berisi data ruang dan waktu; kelas pemesan tetap ditentukan server dari akun Keti (B02).
 */
final class BookingPayload
{
    /**
     * @return array{roomId: int, roomName: string, location: string|null, capacity: int|null, startAt: string, duration: int, dateLabel: string, rangeLabel: string, durationLabel: string}
     */
    public static function make(int $roomId, string $roomName, ?string $location, ?int $capacity, CarbonInterface $start, int $duration): array
    {
        $start = CarbonImmutable::instance($start)->startOfMinute();
        $end = $start->addMinutes($duration);

        return [
            'roomId' => $roomId,
            'roomName' => $roomName,
            'location' => $location,
            'capacity' => $capacity,
            'startAt' => $start->format('Y-m-d H:i'),
            'duration' => $duration,
            'dateLabel' => Wita::longDate($start),
            'rangeLabel' => Wita::range($start, $end),
            'durationLabel' => config('sicma.reservation.duration_options')[$duration] ?? Wita::duration($duration),
        ];
    }
}
