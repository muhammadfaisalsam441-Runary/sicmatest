<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Format waktu dan tanggal seperti di desain: "19:00 WITA", "14.15 WITA", "2 Okt 2026".
 */
class Wita
{
    /**
     * @var list<string>
     */
    private const SHORT_MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Ags', 'Sep', 'Okt', 'Nov', 'Des'];

    /**
     * @var list<string>
     */
    private const LONG_MONTHS = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    /**
     * @var list<string>
     */
    private const DAYS = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'];

    /**
     * Jam dengan titik dua untuk kartu ruang: "19:00 WITA".
     */
    public static function clock(?CarbonInterface $time, bool $withZone = true): string
    {
        if ($time === null) {
            return '—';
        }

        return self::local($time)->format('H:i').($withZone ? ' WITA' : '');
    }

    /**
     * Jam dengan titik untuk keterangan waktu: "14.15 WITA".
     */
    public static function time(?CarbonInterface $time, bool $withZone = true): string
    {
        if ($time === null) {
            return '—';
        }

        return self::local($time)->format('H.i').($withZone ? ' WITA' : '');
    }

    /**
     * Tanggal singkat: "2 Okt 2026".
     */
    public static function date(?CarbonInterface $date): string
    {
        if ($date === null) {
            return '—';
        }

        $date = self::local($date);

        return $date->day.' '.self::SHORT_MONTHS[$date->month - 1].' '.$date->year;
    }

    /**
     * Tanggal panjang: "2 Oktober 2026".
     */
    public static function longDate(?CarbonInterface $date): string
    {
        if ($date === null) {
            return '—';
        }

        $date = self::local($date);

        return $date->day.' '.self::LONG_MONTHS[$date->month - 1].' '.$date->year;
    }

    /**
     * Nama hari: "Kamis".
     */
    public static function dayName(CarbonInterface|int $date): string
    {
        $isoDay = $date instanceof CarbonInterface ? self::local($date)->dayOfWeekIso : $date;

        return self::DAYS[$isoDay - 1];
    }

    /**
     * Tanggal dan jam: "2 Okt 2026 • 14.15 WITA".
     */
    public static function stamp(?CarbonInterface $time): string
    {
        if ($time === null) {
            return '—';
        }

        return self::date($time).' • '.self::time($time);
    }

    /**
     * Rentang jam: "15:00–16:40 WITA".
     */
    public static function range(CarbonInterface $start, CarbonInterface $end): string
    {
        return self::clock($start, false).'–'.self::clock($end);
    }

    /**
     * Menit sejak tengah malam menjadi "07:30".
     */
    public static function minutes(?int $minutes): string
    {
        if ($minutes === null) {
            return '—';
        }

        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /**
     * Durasi singkat: "4 jam 45 menit", "45 menit".
     */
    public static function duration(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return trim(($hours > 0 ? "{$hours} jam " : '').($rest > 0 || $hours === 0 ? "{$rest} menit" : ''));
    }

    private static function local(CarbonInterface $time): CarbonInterface
    {
        return $time->copy()->setTimezone(config('app.timezone'));
    }
}
