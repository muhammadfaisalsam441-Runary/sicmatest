<?php

namespace App\Services;

use App\Enums\RoomBlockSource;
use App\Models\Reservation;
use App\Models\RoomBlock;
use App\Models\RoomClosure;
use App\Models\ScheduleSession;
use App\Support\Wita;
use Illuminate\Support\Collection;

/**
 * Keterangan agenda di balik sebuah blok ruang untuk Portal Keti & Panel Admin
 * (nama mata kuliah/kelas). Jangan dipakai di Portal Publik (B19).
 */
class AgendaDescriber
{
    /**
     * @param  Collection<int, RoomBlock>  $blocks
     * @return Collection<int, array{title: string, class: string|null, range: string, kind: string}>
     */
    public function describeMany(Collection $blocks): Collection
    {
        if ($blocks->isEmpty()) {
            return collect();
        }

        $scheduleIds = $blocks->where('source_type', RoomBlockSource::Schedule)->pluck('source_id')->filter()->unique()->all();
        $reservationIds = $blocks->where('source_type', RoomBlockSource::Reservation)->pluck('source_id')->filter()->unique()->all();
        $closureIds = $blocks->where('source_type', RoomBlockSource::Closure)->pluck('source_id')->filter()->unique()->all();

        $sessions = $scheduleIds === [] ? collect() : ScheduleSession::query()->with('participants')->whereKey($scheduleIds)->get()->keyBy('id');
        $reservations = $reservationIds === [] ? collect() : Reservation::query()->with('classGroup')->whereKey($reservationIds)->get()->keyBy('id');
        $closures = $closureIds === [] ? collect() : RoomClosure::query()->whereKey($closureIds)->get()->keyBy('id');

        return $blocks->mapWithKeys(function (RoomBlock $block) use ($sessions, $reservations, $closures): array {
            $range = Wita::clock($block->start_at, false).'–'.Wita::clock($block->end_at, false);

            return [$block->id => match ($block->source_type) {
                RoomBlockSource::Schedule => [
                    'title' => $sessions->get($block->source_id)?->title ?? 'Kuliah terjadwal',
                    'class' => $sessions->get($block->source_id)?->participants->pluck('name')->implode(' + '),
                    'range' => $range,
                    'kind' => 'schedule',
                ],
                RoomBlockSource::Reservation => [
                    'title' => $reservations->get($block->source_id)?->purpose ?? 'Reservasi',
                    'class' => $reservations->get($block->source_id)?->classGroup?->name,
                    'range' => $range,
                    'kind' => 'reservation',
                ],
                RoomBlockSource::Closure => [
                    'title' => 'Ditutup: '.($closures->get($block->source_id)?->reason ?? 'Penutupan ruang'),
                    'class' => null,
                    'range' => $range,
                    'kind' => 'closure',
                ],
            }];
        });
    }

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
