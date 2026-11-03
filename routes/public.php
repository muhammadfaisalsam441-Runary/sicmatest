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

if (app()->environment(['local', 'testing'])) {
    Route::view('/_design', 'design.public')->name('design');
    Route::view('/_design/auth', 'design.auth')->name('design.auth');
    Route::view('/_design/keti', 'design.keti')->name('design.keti');
    Route::view('/_design/admin', 'design.admin')->name('design.admin');
}
