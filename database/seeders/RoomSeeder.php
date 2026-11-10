<?php

namespace Database\Seeders;

use App\Enums\RoomType;
use App\Models\Room\Building;
use App\Models\Room\Facility;
use App\Models\Room\Room;
use Illuminate\Database\Seeder;

/**
 * DATA DEMO — 24 ruang seperti desain: 10 gedung AE, 4 gedung Pinisi, 10 tanpa gedung.
 * Nilai yang belum diketahui (lantai, kapasitas, jumlah lantai gedung) sengaja NULL (B16).
 */
class RoomSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $facilityIds = collect(['Proyektor', 'AC', 'Stopkontak', 'Papan tulis', 'Komputer'])
            ->mapWithKeys(fn (string $name): array => [$name => Facility::create(['name' => $name])->id]);

        $ae = Building::create(['code' => 'AE', 'name' => 'AE', 'floors_count' => null]);
        $pinisi = Building::create(['code' => 'PINISI', 'name' => 'Pinisi', 'floors_count' => null]);

        // [gedung, kode, nama, lantai, kapasitas, jenis, fasilitas]
        $rooms = [
            [$ae, 'AE-101', 'AE 101', 1, 35, RoomType::Classroom, ['Proyektor']],
            [$ae, 'AE-102', 'AE 102', 1, 40, RoomType::Classroom, ['Proyektor', 'AC']],
            [$ae, 'AE-103', 'AE 103', 1, 40, RoomType::Classroom, ['Papan tulis']],
            [$ae, 'AE-104', 'AE 104', 1, null, RoomType::Classroom, []],
            [$ae, 'AE-105', 'AE 105', 1, 40, RoomType::Classroom, ['Proyektor']],
            [$ae, 'AE-106', 'AE 106', 1, 45, RoomType::Classroom, ['Proyektor', 'AC']],
            [$ae, 'AE-107', 'AE 107', 1, 45, RoomType::Classroom, ['Proyektor', 'AC', 'Stopkontak']],
            [$ae, 'AE-209', 'AE 209', 2, 80, RoomType::Classroom, ['Proyektor', 'AC']],
            [$ae, 'AE-213', 'AE 213', 2, 50, RoomType::Classroom, ['Proyektor']],
            [$ae, 'AE-215', 'AE 215', 2, 50, RoomType::Classroom, ['Papan tulis']],
            [$pinisi, 'PINISI-C1-R2', 'C1-R2', 1, 40, RoomType::Classroom, ['Proyektor']],
            [$pinisi, 'PINISI-C1-R5', 'C1-R5', 1, 40, RoomType::Classroom, ['Proyektor', 'AC']],
            [$pinisi, 'PINISI-C2-R3', 'C2-R3', 2, 45, RoomType::Classroom, ['Proyektor']],
            [$pinisi, 'PINISI-C2-R4', 'C2-R4', 2, 45, RoomType::Classroom, []],
            [null, 'LAB-ICT-04', 'Lab ICT 4', 3, 30, RoomType::Lab, ['Komputer', 'AC']],
            [null, 'LAB-ICT-05', 'Lab ICT 5', 3, 30, RoomType::Lab, ['Komputer', 'AC']],
            [null, 'LAB-ICT-10', 'Lab ICT 10', 3, 35, RoomType::Lab, ['Komputer', 'AC']],
            [null, 'LAB-ICT-11', 'Lab ICT 11', 3, 35, RoomType::Lab, ['Komputer', 'AC']],
            [null, 'LAB-EMBEDDED', 'Lab Embedded', null, 30, RoomType::Lab, ['Stopkontak']],
            [null, 'LAB-JARINGAN', 'Lab Jaringan', null, 30, RoomType::Lab, ['Komputer', 'Stopkontak']],
            [null, 'LAB-ANIMASI', 'Lab Animasi', null, null, RoomType::Lab, ['Komputer']],
            [null, 'LAB-SISTEM-CERDAS', 'Lab Sistem Cerdas', null, 30, RoomType::Lab, ['Komputer', 'AC']],
            [null, 'LAB-PEMROGRAMAN', 'Lab Pemrograman', null, 35, RoomType::Lab, ['Komputer', 'AC']],
            [null, 'BENGKEL-IT', 'Bengkel IT', null, null, RoomType::Workshop, ['Stopkontak']],
        ];

        foreach ($rooms as [$building, $code, $name, $floor, $capacity, $type, $facilities]) {
            $room = Room::create([
                'building_id' => $building?->id,
                'code' => $code,
                'name' => $name,
                'floor' => $floor,
                'capacity' => $capacity,
                'type' => $type,
                'is_bookable' => true,
                'is_active' => true,
            ]);

            $room->facilities()->attach($facilityIds->only($facilities)->values());
        }
    }
}
