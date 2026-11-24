<?php

namespace App\Support;

use App\Enums\RoomBlockSource;
use App\Enums\RoomStatus;
use App\Models\Room\RoomBlock;
use App\Services\Room\AvailabilityResult;
use App\Services\Room\RoomAgendaDescriber;
use Illuminate\Support\Collection;

/**
 * Mengubah hasil AvailabilityService menjadi data kartu ruang.
 * Data ini aman untuk Portal Publik: dapat menampilkan nama mata kuliah/kegiatan serta kelas,
 * namun tidak pernah memuat identitas pribadi pemesan (nama, NIM, atau email) (B19).
 */
class RoomCardPresenter
{
    /**
     * Mengubah koleksi hasil AvailabilityService menjadi daftar data kartu ruang dalam satu batch query agenda.
     *
     * @param  Collection<int, AvailabilityResult>  $results
     * @return list<array{id: int, name: string, badge: string, location: string, capacity: int|null, facilities: list<string>, time_label: string|null, time: string|null, note: string|null, remaining: string|null, status: string, agenda_title: string|null, agenda_class: string|null, agenda_range: string|null, agenda_is_upcoming: bool}>
     */
    public static function cards(Collection $results, ?RoomAgendaDescriber $describer = null): array
    {
        $blocksByKey = $results->map(fn (AvailabilityResult $result): ?RoomBlock => self::agendaBlock($result));
        $blocks = $blocksByKey->filter()->values();
        $agendas = $blocks->isEmpty()
            ? collect()
            : ($describer ?? app(RoomAgendaDescriber::class))->describeMany($blocks);

        return $results
            ->map(function (AvailabilityResult $result, int|string $key) use ($blocksByKey, $agendas): array {
                $block = $blocksByKey->get($key);

                return self::card($result, $block === null ? null : $agendas->get($block->id));
            })
            ->values()
            ->all();
    }

    /**
     * @param  array{title?: string|null, class?: string|null, range?: string|null, kind?: string}|int|string|null  $agenda  Data agenda, atau key koleksi saat dipanggil via Collection::map(RoomCardPresenter::card(...)).
     * @return array{id: int, name: string, badge: string, location: string, capacity: int|null, facilities: list<string>, time_label: string|null, time: string|null, note: string|null, remaining: string|null, status: string, agenda_title: string|null, agenda_class: string|null, agenda_range: string|null, agenda_is_upcoming: bool}
     */
    public static function card(AvailabilityResult $result, array|int|string|null $agenda = null): array
    {
        if (! is_array($agenda)) {
            $agenda = null;
        }

        if ($agenda === null) {
            $block = self::agendaBlock($result);

            if ($block !== null) {
                $agenda = app(RoomAgendaDescriber::class)->describe($block);
            }
        }

        $isAvailable = $result->isAvailable() || $result->status === RoomStatus::Uncertain;
        $isClosed = $result->status === RoomStatus::Closed;
        $minutes = $result->minutesRemaining();

        return [
            'id' => $result->room->id,
            'name' => $result->room->name,
            'badge' => $result->badge(),
            'location' => $result->locationLabel(),
            'capacity' => $result->room->capacity,
            'facilities' => $result->room->relationLoaded('facilities') ? $result->room->facilities->pluck('name')->all() : [],
            'time_label' => match (true) {
                $isAvailable => 'Tersedia sampai',
                $isClosed && $result->busyUntil !== null => 'Buka kembali',
                $isClosed => 'Status',
                default => 'Selesai pukul',
            },
            'time' => match (true) {
                $isAvailable => Wita::clock($result->freeUntil),
                $isClosed && $result->busyUntil !== null => Wita::clock($result->busyUntil),
                $isClosed => 'Ditutup',
                default => self::busyTime($result),
            },
            'note' => self::publicNote($result),
            'remaining' => $minutes === null ? null : Wita::duration($minutes).' lagi',
            'status' => $result->status->value,
            'agenda_title' => $agenda['title'] ?? null,
            'agenda_class' => $agenda['class'] ?? null,
            'agenda_range' => $agenda['range'] ?? null,
            'agenda_is_upcoming' => $result->currentBlock === null && $agenda !== null,
        ];
    }

    private static function agendaBlock(AvailabilityResult $result): ?RoomBlock
    {
        $sources = [RoomBlockSource::Schedule, RoomBlockSource::Reservation];

        if ($result->currentBlock !== null && in_array($result->currentBlock->source_type, $sources, true)) {
            return $result->currentBlock;
        }

        if ($result->currentBlock === null
            && $result->nextBlock !== null
            && in_array($result->status, [RoomStatus::Available, RoomStatus::Closed, RoomStatus::Uncertain], true)
            && in_array($result->nextBlock->source_type, $sources, true)) {
            return $result->nextBlock;
        }

        return null;
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
