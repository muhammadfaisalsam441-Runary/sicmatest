<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Ruang, kelas, mata kuliah, dosen, dan Jadwal resmi berasal dari Jadwal TA Ganjil 2026/2027 JTIK
     * (database/data/jadwal-ganjil-2026-2027.csv). Akun admin/Keti dan reservasi tetap data simulasi (fiktif).
     */
    public function run(): void
    {
        $this->call([
            RoomSeeder::class,
            AcademicSeeder::class,
            AccountSeeder::class,
            ScheduleSeeder::class,
            ReservationSeeder::class,
        ]);
    }
}
