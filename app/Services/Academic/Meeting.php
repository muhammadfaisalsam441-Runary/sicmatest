<?php

namespace App\Services\Academic;

use App\Models\Academic\SessionReschedule;
use App\Models\Scheduling\ScheduleSession;
use Carbon\CarbonImmutable;

/**
 * Satu kemunculan sesi jadwal resmi pada tanggal tertentu, beserta pemindahannya bila ada.
 */
final class Meeting
{
    public function __construct(
        public readonly ScheduleSession $session,
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        public readonly ?SessionReschedule $reschedule = null,
    ) {}

    public function isMoved(): bool
    {
        return $this->reschedule !== null;
    }

    /**
     * Pertemuan yang sama: sesi dan jam mulai sama.
     */
    public function is(Meeting $other): bool
    {
        return $this->session->id === $other->session->id && $this->start->equalTo($other->start);
    }
}
