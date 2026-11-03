<?php

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

Route::view('/cari-ruangan', 'keti.search')->name('search');
