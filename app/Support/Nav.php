<?php

namespace App\Support;

use Illuminate\Support\Facades\Route;

/**
 * Bantuan navigasi untuk layout: URL aman untuk route yang mungkin belum dibuat.
 */
class Nav
{
    /**
     * URL route bernama, atau "#" bila route belum terdaftar.
     *
     * @param  array<string, mixed>  $parameters
     */
    public static function url(string $name, array $parameters = []): string
    {
        return Route::has($name) ? route($name, $parameters) : '#';
    }

    /**
     * Route aktif cocok dengan salah satu pola.
     */
    public static function active(string ...$patterns): bool
    {
        return request()->routeIs(...$patterns);
    }
}
