<?php

use App\Http\Controllers\Admin\ClosureController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\KetiAccountController;
use App\Http\Controllers\Admin\NotificationController;
use App\Http\Controllers\Admin\ProfileController;
use App\Http\Controllers\Admin\ReservationController;
use App\Http\Controllers\Admin\RoomController;
use App\Http\Controllers\Admin\UsageLogController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Panel Admin
|--------------------------------------------------------------------------
|
| Prefix "/admin", nama "admin.", middleware auth, role:admin,super_admin
| (lihat bootstrap/app.php).
|
*/

Route::get('/', DashboardController::class)->name('dashboard');

Route::get('/ruang', [RoomController::class, 'index'])->name('rooms.index');
Route::post('/ruang', [RoomController::class, 'store'])->name('rooms.store');
Route::put('/ruang/{room}', [RoomController::class, 'update'])->name('rooms.update');
Route::delete('/ruang/{room}', [RoomController::class, 'destroy'])->name('rooms.destroy');

Route::post('/penutupan-ruang', [ClosureController::class, 'store'])->name('closures.store');
Route::delete('/penutupan-ruang/{closure}', [ClosureController::class, 'destroy'])->name('closures.destroy');

Route::get('/reservasi', [ReservationController::class, 'index'])->name('reservations.index');
Route::post('/reservasi/{reservation}/batal', [ReservationController::class, 'cancel'])->name('reservations.cancel');

Route::get('/log-pemakaian', [UsageLogController::class, 'index'])->name('usage-logs.index');
Route::get('/log-pemakaian/unduh', [UsageLogController::class, 'export'])->name('usage-logs.export');

Route::get('/akun-keti', [KetiAccountController::class, 'index'])->name('keti-accounts.index');
Route::post('/akun-keti/{application}/setujui', [KetiAccountController::class, 'approve'])->name('keti-accounts.approve');
Route::post('/akun-keti/{application}/perbaiki', [KetiAccountController::class, 'requestRevision'])->name('keti-accounts.revise');
Route::post('/akun-keti/{application}/tolak', [KetiAccountController::class, 'reject'])->name('keti-accounts.reject');
Route::post('/akun-keti/pengguna/{user}/nonaktifkan', [KetiAccountController::class, 'deactivate'])->name('keti-accounts.deactivate');

Route::get('/notifikasi', [NotificationController::class, 'index'])->name('notifications');
Route::post('/notifikasi/baca-semua', [NotificationController::class, 'readAll'])->name('notifications.read-all');
Route::post('/notifikasi/{notification}/baca', [NotificationController::class, 'read'])->name('notifications.read');

Route::get('/profil', [ProfileController::class, 'show'])->name('profile');
Route::put('/profil', [ProfileController::class, 'update'])->name('profile.update');
Route::put('/profil/kata-sandi', [ProfileController::class, 'password'])->name('profile.password');
