<?php

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

Route::view('/', 'admin.dashboard')->name('dashboard');
