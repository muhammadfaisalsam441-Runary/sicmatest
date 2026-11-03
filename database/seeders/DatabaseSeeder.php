<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed data demo SICMA (semua nama fiktif).
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
