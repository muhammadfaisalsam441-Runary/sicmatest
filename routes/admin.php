<?php

use App\Http\Controllers\Admin\AcademicController;
use App\Http\Controllers\Admin\ClosureController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DraftController;
use App\Http\Controllers\Admin\ImportController;
use App\Http\Controllers\Admin\KetiAccountController;
use App\Http\Controllers\Admin\LecturerController;
use App\Http\Controllers\Admin\NotificationController;
use App\Http\Controllers\Admin\ProfileController;
use App\Http\Controllers\Admin\ReservationController;
use App\Http\Controllers\Admin\RoomController;
use App\Http\Controllers\Admin\ScheduleController;
use App\Http\Controllers\Admin\SchedulerController;
use App\Http\Controllers\Admin\TeachingAssignmentController;
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
Route::post('/ruang/batch', [RoomController::class, 'batchUpdate'])->name('rooms.batch');
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

Route::get('/jadwal', [ScheduleController::class, 'index'])->name('schedules.index');
Route::get('/jadwal/templat', [ScheduleController::class, 'template'])->name('schedules.template');

Route::get('/jadwal/impor', [ImportController::class, 'create'])->name('imports.create');
Route::post('/jadwal/impor', [ImportController::class, 'store'])->middleware('throttle:10,1')->name('imports.store');
Route::get('/jadwal/impor/{import}', [ImportController::class, 'show'])->name('imports.show');
Route::post('/jadwal/impor/{import}/simpan', [ImportController::class, 'commit'])->name('imports.commit');

Route::post('/draf', [DraftController::class, 'store'])->name('drafts.store');
Route::get('/draf/{version}', [DraftController::class, 'show'])->name('drafts.show');
Route::get('/draf/{version}/unduh/excel', [DraftController::class, 'excel'])->name('drafts.excel');
Route::get('/draf/{version}/unduh/pdf', [DraftController::class, 'pdf'])->name('drafts.pdf');
Route::put('/draf/{version}/sesi/{session}', [DraftController::class, 'updateSession'])->scopeBindings()->name('drafts.sessions.update');
Route::post('/draf/{version}/sesi/{session}/kunci', [DraftController::class, 'toggleLock'])->scopeBindings()->name('drafts.sessions.lock');
Route::post('/draf/{version}/periksa', [DraftController::class, 'validateDraft'])->name('drafts.validate');
Route::post('/draf/{version}/tetapkan', [DraftController::class, 'publish'])->can('publish-schedule')->name('drafts.publish');
Route::get('/draf/{version}/berhasil', [DraftController::class, 'published'])->name('drafts.published');

Route::get('/akademik', [AcademicController::class, 'index'])->name('academic.index');
Route::put('/akademik/semester/{period}', [AcademicController::class, 'updatePeriod'])->name('academic.periods.update');
Route::post('/akademik/semester/{period}/libur', [AcademicController::class, 'storeException'])->name('academic.exceptions.store');
Route::delete('/akademik/libur/{exception}', [AcademicController::class, 'destroyException'])->name('academic.exceptions.destroy');
Route::put('/akademik/semester/{period}/slot', [AcademicController::class, 'updateSlots'])->name('academic.slots.update');
Route::post('/akademik/mata-kuliah', [AcademicController::class, 'storeCourse'])->name('academic.courses.store');
Route::put('/akademik/mata-kuliah/{course}', [AcademicController::class, 'updateCourse'])->name('academic.courses.update');
Route::delete('/akademik/mata-kuliah/{course}', [AcademicController::class, 'destroyCourse'])->name('academic.courses.destroy');
Route::post('/akademik/kelas', [AcademicController::class, 'storeClassGroup'])->name('academic.classes.store');
Route::post('/akademik/kelas/batch', [AcademicController::class, 'batchUpdateClassGroups'])->name('academic.classes.batch');
Route::put('/akademik/kelas/massal', [AcademicController::class, 'bulkUpdateClasses'])->name('academic.classes.bulk-update');
Route::put('/akademik/kelas/{classGroup}', [AcademicController::class, 'updateClassGroup'])->name('academic.classes.update');
Route::delete('/akademik/kelas/{classGroup}', [AcademicController::class, 'destroyClassGroup'])->name('academic.classes.destroy');
Route::post('/akademik/penawaran', [AcademicController::class, 'storeOffering'])->name('academic.offerings.store');
Route::post('/akademik/penawaran/batch-delete', [AcademicController::class, 'batchDeleteOfferings'])->name('academic.offerings.batch-delete');
Route::delete('/akademik/penawaran/{offering}', [AcademicController::class, 'destroyOffering'])->name('academic.offerings.destroy');

Route::get('/dosen', [LecturerController::class, 'index'])->name('lecturers.index');
Route::post('/dosen', [LecturerController::class, 'store'])->name('lecturers.store');
Route::post('/dosen/batch', [LecturerController::class, 'batchUpdate'])->name('lecturers.batch');
Route::put('/dosen/{lecturer}', [LecturerController::class, 'update'])->name('lecturers.update');
Route::post('/dosen/impor', [LecturerController::class, 'import'])->name('lecturers.import');

Route::get('/pembagian-mengajar', [TeachingAssignmentController::class, 'index'])->name('teaching.index');
Route::get('/pembagian-mengajar/sks-otomatis', [TeachingAssignmentController::class, 'previewPhases'])->name('teaching.phases.preview');
Route::post('/pembagian-mengajar/sks-otomatis', [TeachingAssignmentController::class, 'applyPhases'])->name('teaching.phases.apply');
Route::post('/pembagian-mengajar', [TeachingAssignmentController::class, 'store'])->name('teaching.store');
Route::post('/pembagian-mengajar/batch', [TeachingAssignmentController::class, 'batchStore'])->name('teaching.batch');
Route::put('/pembagian-mengajar/{assignment}', [TeachingAssignmentController::class, 'update'])->name('teaching.update');
Route::post('/pembagian-mengajar/{assignment}/kunci', [TeachingAssignmentController::class, 'toggleLock'])->name('teaching.lock');
Route::delete('/pembagian-mengajar/{assignment}', [TeachingAssignmentController::class, 'destroy'])->name('teaching.destroy');

Route::get('/susun-jadwal', [SchedulerController::class, 'create'])->name('scheduler.create');
Route::post('/susun-jadwal/periksa', [SchedulerController::class, 'check'])->name('scheduler.check');
Route::post('/susun-jadwal', [SchedulerController::class, 'store'])->middleware('throttle:10,1')->name('scheduler.store');
Route::get('/susun-jadwal/riwayat', [SchedulerController::class, 'history'])->name('scheduler.history');
Route::get('/susun-jadwal/proses/{job}', [SchedulerController::class, 'show'])->name('scheduler.show');
Route::get('/susun-jadwal/proses/{job}/status', [SchedulerController::class, 'status'])->name('scheduler.status');
Route::post('/susun-jadwal/proses/{job}/batal', [SchedulerController::class, 'cancel'])->name('scheduler.cancel');
