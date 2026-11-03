<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Portal Publik
|--------------------------------------------------------------------------
|
| Halaman tanpa login: Beranda, Ruangan, Panduan, Bantuan (Fase 5).
| Tidak boleh menampilkan data pribadi (B19).
|
*/

Route::view('/', 'public.home')->name('home');
