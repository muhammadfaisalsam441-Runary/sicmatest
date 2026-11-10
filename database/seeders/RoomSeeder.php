<?php

namespace Database\Seeders;

use App\Enums\RoomType;
use App\Models\Room\Building;
use App\Models\Room\Facility;
use App\Models\Room\Room;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Ruang yang dipakai di Jadwal TA Ganjil 2026/2027 JTIK (21 ruang). Gedung AE dikenali dari nama ruang;
 * ruang lain (lab, "Pasca 1") belum didata gedungnya. Lantai, kapasitas, dan fasilitas tidak ada di sumber
 * sehingga disimpan NULL / kosong ("Perlu verifikasi") dan dilengkapi admin di Kelola Ruang (B16).
 */
class RoomSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Daftar fasilitas untuk formulir Kelola Ruang; belum dipasang ke ruang mana pun.
        foreach (['Proyektor', 'AC', 'Stopkontak', 'Papan tulis', 'Komputer'] as $name) {
            Facility::create(['name' => $name]);
        }

        $ae = Building::create(['code' => 'AE', 'name' => 'AE', 'floors_count' => null]);

        $names = ScheduleSourceData::rows()->pluck('room')->unique()->sort(SORT_NATURAL)->values();

        foreach ($names as $name) {
            Room::create([
                'building_id' => Str::startsWith($name, 'AE ') ? $ae->id : null,
                'code' => ScheduleSourceData::roomCode($name),
                'name' => $name,
                'floor' => null,
                'capacity' => null,
                'type' => Str::startsWith($name, 'Lab ') ? RoomType::Lab : RoomType::Classroom,
                'is_bookable' => true,
                'is_active' => true,
            ]);
        }
    }
}
