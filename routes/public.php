<?php

use App\Http\Controllers\Public\GuideController;
use App\Http\Controllers\Public\HelpController;
use App\Http\Controllers\Public\HomeController;
use App\Http\Controllers\Public\RoomController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Portal Publik
|--------------------------------------------------------------------------
|
| Halaman tanpa login: Beranda, Ruangan, Panduan, Bantuan.
| Tidak boleh menampilkan data pribadi (B19).
|
*/

Route::get('/', HomeController::class)->name('home');
Route::get('/ruangan', RoomController::class)->name('public.rooms');
Route::get('/panduan', GuideController::class)->name('public.guide');
Route::get('/bantuan', HelpController::class)->name('public.help');
