<?php

namespace Database\Factories;

use App\Models\Lecturer\LeadershipTerm;
use App\Models\Lecturer\Lecturer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeadershipTerm>
 */
class LeadershipTermFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'lecturer_id' => Lecturer::factory(),
            'position' => 'Ketua Jurusan',
            'valid_from' => '2026-01-01',
            'valid_to' => null,
        ];
    }
}
