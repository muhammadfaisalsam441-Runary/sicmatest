<?php

use App\Models\Academic\ClassGroup;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Pekerjaan terjadwal SICMA (jalankan `php artisan schedule:work` atau cron `schedule:run` tiap menit)
|--------------------------------------------------------------------------
*/

Schedule::command('sicma:flag-overdue')->everyFiveMinutes()->withoutOverlapping();

Artisan::command('sicma:fix-class-codes', function () {
    $count = 0;
    foreach (ClassGroup::all() as $class) {
        $cleanCode = ClassGroup::formatCode($class->name, $class->cohort_year, $class->id);
        if ($class->code !== $cleanCode) {
            $class->update(['code' => $cleanCode]);
            $count++;
        }
    }
    $this->info("Berhasil merapikan {$count} kode kelas.");
})->purpose('Rapikan kode kelas agar tidak ada duplikasi tahun');
