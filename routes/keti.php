<?php

use App\Http\Controllers\Keti\ActivityController;
use App\Http\Controllers\Keti\FavoriteController;
use App\Http\Controllers\Keti\MyClassController;
use App\Http\Controllers\Keti\NotificationController;
use App\Http\Controllers\Keti\ProfileController;
use App\Http\Controllers\Keti\ReservationController;
use App\Http\Controllers\Keti\RoomController;
use App\Http\Controllers\Keti\RoomSearchController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Portal Keti
|--------------------------------------------------------------------------
|
| Prefix "/keti", nama "keti.", middleware auth, verified, role:keti, keti.approved
| (lihat bootstrap/app.php).
|
*/

Route::get('/cari-ruangan', RoomSearchController::class)->name('search');
Route::get('/my-class', MyClassController::class)->name('my-class');

Route::get('/ruangan', [RoomController::class, 'index'])->name('rooms.index');
Route::get('/ruangan/{room}', [RoomController::class, 'show'])->name('rooms.show');

Route::post('/reservasi', [ReservationController::class, 'store'])->middleware('throttle:20,1')->name('reservations.store');
Route::get('/reservasi/gagal', [ReservationController::class, 'failed'])->name('reservations.failed');
Route::get('/reservasi/{reservation}', [ReservationController::class, 'show'])->name('reservations.show');
Route::get('/reservasi/{reservation}/status', [ReservationController::class, 'status'])->name('reservations.status');
Route::post('/reservasi/{reservation}/mulai', [ReservationController::class, 'start'])->name('reservations.start');
Route::post('/reservasi/{reservation}/selesai', [ReservationController::class, 'finish'])->name('reservations.finish');
Route::post('/reservasi/{reservation}/batal', [ReservationController::class, 'cancel'])->name('reservations.cancel');
Route::post('/reservasi/{reservation}/perpanjang', [ReservationController::class, 'extend'])->name('reservations.extend');
Route::post('/reservasi/{reservation}/lapor', [ReservationController::class, 'report'])->name('reservations.report');

Route::get('/favorit', [FavoriteController::class, 'index'])->name('favorites.index');
Route::put('/favorit/{room}', [FavoriteController::class, 'store'])->name('favorites.store');
Route::delete('/favorit/{room}', [FavoriteController::class, 'destroy'])->name('favorites.destroy');

Route::get('/aktivitas', ActivityController::class)->name('activity');

Route::get('/notifikasi', [NotificationController::class, 'index'])->name('notifications');
Route::post('/notifikasi/baca-semua', [NotificationController::class, 'readAll'])->name('notifications.read-all');
Route::post('/notifikasi/{notification}/baca', [NotificationController::class, 'read'])->name('notifications.read');

Route::get('/profil', ProfileController::class)->name('profile');
