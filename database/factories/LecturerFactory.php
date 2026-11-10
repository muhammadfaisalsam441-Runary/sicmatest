<?php

namespace Database\Factories;

use App\Enums\LecturerHomebase;
use App\Enums\LecturerProfileStatus;
use App\Models\Lecturer\Lecturer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lecturer>
 */
class LecturerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'nidn' => fake()->unique()->numerify('00########'),
            'homebase' => LecturerHomebase::Jtik,
            'profile_status' => LecturerProfileStatus::Verified,
            'is_active' => true,
            'source_name' => null,
        ];
    }

    /**
     * Dosen dari sumber jadwal yang profilnya belum diverifikasi.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'nidn' => null,
            'homebase' => null,
            'profile_status' => LecturerProfileStatus::Unverified,
            'source_name' => $attributes['name'],
        ]);
    }
}
