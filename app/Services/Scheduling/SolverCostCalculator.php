<?php

namespace App\Services\Scheduling;

/**
 * Penghitung biaya preferensi kandidat slot jadwal (preferensi sebelumnya, pola durasi, dan profil angkatan).
 */
class SolverCostCalculator
{
    /**
     * Hitung total penalti penempatan kandidat sesi pada hari, jam mulai, dan ruang tertentu.
     *
     * @param  array<string, mixed>  $session
     */
    public function cost(array $session, int $day, int $start, ?int $room): int
    {
        $preferred = $session['preferred'] ?? null;
        $pattern = ($preferred === null || $preferred['start'] !== $start)
            ? $this->slotPatternCost((int) ($session['duration'] ?? 0), $day, $start)
                + $this->cohortPatternCost($session['cohort_profile'] ?? null, $start)
            : 0;

        return ($preferred === null ? $day : (int) ($preferred['day'] !== $day) * 6
            + (int) ($preferred['start'] !== $start) * 3 + (int) ($preferred['room'] !== $room) * 2) + $pattern;
    }

    /**
     * Penalti tambahan sesuai profil angkatan (mis. mengulang pagi, maba siang).
     */
    public function cohortPatternCost(?string $cohortProfile, int $start): int
    {
        return match ($cohortProfile) {
            'ulang_senior' => $start < 720 ? 0 : ($start < 890 ? 2 : 5),
            'maba' => $start < 890 ? 0 : 4,
            default => 0,
        };
    }

    /**
     * Penalti pola jam mulai terhadap blok slot sesuai durasi SKS sesi.
     */
    public function slotPatternCost(int $duration, int $day, int $start): int
    {
        if ($duration >= 190) {
            return match ($start) {
                450 => 0,
                790 => 1,
                default => 6,
            };
        }

        if ($duration >= 140) {
            $primary = $day === 5 ? [450, 550, 555, 890] : [450, 605, 890];

            return in_array($start, $primary, true) ? 0 : ($start === 790 ? 2 : 6);
        }

        if ($duration >= 90) {
            $primary = $day === 5 ? [450, 605, 790] : [450, 550, 555, 660, 790];

            return in_array($start, $primary, true) ? 0 : (in_array($start, [890, 940], true) ? 2 : 6);
        }

        return 0;
    }
}
