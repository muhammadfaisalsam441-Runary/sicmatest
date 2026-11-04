<?php

namespace App\Services;

use App\Enums\RoomBlockSource;
use App\Enums\SessionPhase;
use App\Models\Period;
use App\Models\RoomBlock;
use App\Models\ScheduleSession;
use App\Models\ScheduleVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Mengubah sesi mingguan sebuah versi jadwal menjadi blok ruang per tanggal (room_blocks).
 *
 * Dipakai SchedulePublisher saat menetapkan jadwal (Fase 8) dan seeder demo.
 */
class ScheduleBlockBuilder
{
    /**
     * Bangun ulang semua blok jadwal milik versi ini dalam satu transaksi.
     *
     * - Hanya sesi luring yang punya ruang, hari, dan jam (B14, B15).
     * - Sesi gabungan beberapa kelas tetap satu sesi → satu blok per kemunculan (B14).
     * - Tanggal pada calendar_exceptions periode dilewati.
     * - Fase sebelum/sesudah UTS dibatasi oleh tanggal UTS; jika tanggal UTS belum diketahui,
     *   sesi tetap diblok sepanjang periode agar tidak ada pemesanan yang bentrok.
     *
     * @return int Jumlah blok yang dibuat.
     */
    public function rebuild(ScheduleVersion $version): int
    {
        $version->loadMissing(['period.calendarExceptions', 'sessions']);

        $rows = $this->occurrences($version);

        DB::transaction(function () use ($version, $rows): void {
            RoomBlock::query()
                ->whereBelongsTo($version, 'scheduleVersion')
                ->where('source_type', RoomBlockSource::Schedule)
                ->delete();

            foreach (array_chunk($rows, 500) as $chunk) {
                RoomBlock::query()->insert($chunk);
            }
        });

        return count($rows);
    }

    /**
     * Kemunculan blok ruang per tanggal untuk versi ini (tanpa menyimpan). Dipakai juga ScheduleValidator
     * untuk memeriksa bentrok draf dengan reservasi (B10).
     *
     * @return list<array{room_id: int, start_at: string, end_at: string, source_type: string, source_id: int, schedule_version_id: int, is_active: bool, created_at: string, updated_at: string}>
     */
    public function occurrences(ScheduleVersion $version): array
    {
        $period = $version->period;

        if ($period->start_date === null || $period->end_date === null) {
            return [];
        }

        $skippedDates = $period->calendarExceptions
            ->mapWithKeys(fn ($exception): array => [$exception->date->toDateString() => true]);

        $from = CarbonImmutable::parse($version->effective_from->toDateString());
        $to = CarbonImmutable::parse(($version->effective_to ?? $period->end_date)->toDateString());

        if ($from->lessThan($period->start_date)) {
            $from = CarbonImmutable::parse($period->start_date->toDateString());
        }

        if ($to->greaterThan($period->end_date)) {
            $to = CarbonImmutable::parse($period->end_date->toDateString());
        }

        $timestamp = now()->format('Y-m-d H:i:s');
        $rows = [];

        foreach ($version->sessions as $session) {
            if (! $session->blocksRoom()) {
                continue;
            }

            for ($date = $this->firstWeekday($from, $session->day_of_week); $date->lessThanOrEqualTo($to); $date = $date->addWeek()) {
                if ($skippedDates->has($date->toDateString()) || ! $this->phaseCovers($session, $date, $period)) {
                    continue;
                }

                $rows[] = [
                    'room_id' => $session->room_id,
                    'start_at' => $date->addMinutes($session->start_minute)->format('Y-m-d H:i:s'),
                    'end_at' => $date->addMinutes($session->end_minute)->format('Y-m-d H:i:s'),
                    'source_type' => RoomBlockSource::Schedule->value,
                    'source_id' => $session->id,
                    'schedule_version_id' => $version->id,
                    'is_active' => true,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            }
        }

        return $rows;
    }

    /**
     * Tanggal pertama pada atau setelah $from yang jatuh pada hari ISO tersebut (1 = Senin).
     */
    private function firstWeekday(CarbonImmutable $from, int $dayOfWeekIso): CarbonImmutable
    {
        return $from->addDays(($dayOfWeekIso - $from->dayOfWeekIso + 7) % 7);
    }

    private function phaseCovers(ScheduleSession $session, CarbonImmutable $date, Period $period): bool
    {
        return match ($session->phase) {
            SessionPhase::Full => true,
            SessionPhase::BeforeUts => $period->uts_start === null || $date->lessThan($period->uts_start),
            SessionPhase::AfterUts => $period->uts_end === null || $date->greaterThan($period->uts_end),
        };
    }
}
