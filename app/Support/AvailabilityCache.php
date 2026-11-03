<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Cache status ruang Portal Publik (Fase 5). Versi cache dinaikkan setiap ada reservasi,
 * penutupan, atau penetapan jadwal sehingga data lama langsung tidak dipakai.
 */
class AvailabilityCache
{
    private const VERSION_KEY = 'sicma:availability:version';

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function remember(string $key, Closure $callback): mixed
    {
        $version = Cache::get(self::VERSION_KEY, 1);

        return Cache::remember("sicma:availability:v{$version}:{$key}", (int) config('sicma.public_portal.cache_seconds'), $callback);
    }

    /**
     * Tandai semua cache status ruang kedaluwarsa setelah transaksi berhasil.
     */
    public static function bust(): void
    {
        DB::afterCommit(function (): void {
            Cache::add(self::VERSION_KEY, 1);
            Cache::increment(self::VERSION_KEY);
        });
    }
}
