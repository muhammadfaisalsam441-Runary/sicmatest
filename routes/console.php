<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Pekerjaan terjadwal SICMA (jalankan `php artisan schedule:work` atau cron `schedule:run` tiap menit)
|--------------------------------------------------------------------------
*/

Schedule::command('sicma:flag-overdue')->everyFiveMinutes()->withoutOverlapping();
