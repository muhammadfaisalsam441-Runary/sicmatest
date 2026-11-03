<?php

use App\Http\Controllers\Auth\ApplicationStatusController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisterKetiController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Akses Akun Bersama
|--------------------------------------------------------------------------
|
| Satu halaman masuk untuk Keti dan Admin ("Email atau NIM"), daftar Keti,
| verifikasi email (kode 6 digit), status pengajuan, lupa & atur ulang sandi.
|
*/

Route::middleware('guest')->group(function (): void {
    Route::get('/masuk', [LoginController::class, 'show'])->name('login');
    Route::post('/masuk', [LoginController::class, 'store'])->middleware('throttle:5,1')->name('login.store');
    Route::get('/daftar', [RegisterKetiController::class, 'create'])->name('register');
    Route::post('/daftar', [RegisterKetiController::class, 'store'])->middleware('throttle:10,1')->name('register.store');
    Route::get('/lupa-sandi', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('/lupa-sandi', [PasswordResetController::class, 'email'])->middleware('throttle:5,1')->name('password.email');
    Route::get('/lupa-sandi/terkirim', [PasswordResetController::class, 'sent'])->name('password.sent');
    Route::get('/atur-ulang-sandi/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('/atur-ulang-sandi', [PasswordResetController::class, 'update'])->name('password.update');
    Route::get('/kata-sandi-diperbarui', [PasswordResetController::class, 'done'])->name('password.done');
});

Route::middleware('auth')->group(function (): void {
    Route::get('/verifikasi-email', [EmailVerificationController::class, 'show'])->name('verification.notice');
    Route::post('/verifikasi-email', [EmailVerificationController::class, 'verify'])->middleware('throttle:10,1')->name('verification.verify');
    Route::post('/verifikasi-email/kirim-ulang', [EmailVerificationController::class, 'resend'])->middleware('throttle:6,1')->name('verification.send');
    Route::get('/verifikasi-email/ubah', [EmailVerificationController::class, 'editEmail'])->name('verification.email.edit');
    Route::put('/verifikasi-email/ubah', [EmailVerificationController::class, 'updateEmail'])->name('verification.email.update');
    Route::get('/pengajuan', [ApplicationStatusController::class, 'show'])->name('keti.application');
    Route::put('/pengajuan', [ApplicationStatusController::class, 'resubmit'])->name('keti.application.resubmit');
    Route::post('/keluar', [LoginController::class, 'destroy'])->name('logout');
});
