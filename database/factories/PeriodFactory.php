<?php

namespace Database\Factories;

use App\Models\Academic\Period;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Period>
 */
class PeriodFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Ganjil 2026/2027',
            'start_date' => '2026-09-01',
            'end_date' => '2027-01-15',
            'timezone' => 'Asia/Makassar',
            'uts_start' => '2026-10-26',
            'uts_end' => '2026-10-30',
            'uas_start' => '2027-01-04',
            'uas_end' => '2027-01-15',
            'is_active' => true,
        ];
    }
}
