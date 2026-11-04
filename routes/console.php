<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Pekerjaan terjadwal SICMA (jalankan `php artisan schedule:work` atau cron `schedule:run` tiap menit)
|--------------------------------------------------------------------------
*/

Schedule::command('sicma:flag-overdue')->everyFiveMinutes()->withoutOverlapping();
