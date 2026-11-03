<?php

namespace App\Services;

use App\Enums\RoomBlockSource;
use App\Models\Reservation;
use App\Models\RoomBlock;
use App\Models\RoomClosure;
use App\Models\ScheduleSession;
use App\Support\Wita;

/**
 * Keterangan agenda di balik sebuah blok ruang untuk Portal Keti & Panel Admin
 * (nama mata kuliah/kelas). Jangan dipakai di Portal Publik (B19).
 */
class AgendaDescriber
{
    /**
     * @return array{title: string, class: string|null, range: string, kind: string}
     */
    public function describe(RoomBlock $block): array
    {
        $range = Wita::clock($block->start_at, false).'–'.Wita::clock($block->end_at, false);

        return match ($block->source_type) {
            RoomBlockSource::Schedule => $this->schedule($block, $range),
            RoomBlockSource::Reservation => $this->reservation($block, $range),
            RoomBlockSource::Closure => [
                'title' => 'Ditutup: '.(RoomClosure::query()->find($block->source_id)?->reason ?? 'Penutupan ruang'),
                'class' => null,
                'range' => $range,
                'kind' => 'closure',
            ],
        };
    }

    /**
     * @return array{title: string, class: string|null, range: string, kind: string}
     */
    private function schedule(RoomBlock $block, string $range): array
    {
        $session = ScheduleSession::query()->with('participants')->find($block->source_id);

        return [
            'title' => $session?->title ?? 'Kuliah terjadwal',
            'class' => $session?->participants->pluck('name')->implode(' + '),
            'range' => $range,
            'kind' => 'schedule',
        ];
    }

    /**
     * @return array{title: string, class: string|null, range: string, kind: string}
     */
    private function reservation(RoomBlock $block, string $range): array
    {
        $reservation = Reservation::query()->with('classGroup')->find($block->source_id);

        return [
            'title' => $reservation?->purpose ?? 'Reservasi',
            'class' => $reservation?->classGroup?->name,
            'range' => $range,
            'kind' => 'reservation',
        ];
    }
}
