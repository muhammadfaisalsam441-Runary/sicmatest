<?php

namespace Database\Factories;

use App\Models\Academic\ClassGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClassGroup>
 */
class ClassGroupFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('TEKOM-?-20##')),
            'name' => 'Tekom '.fake()->randomLetter(),
            'study_program' => 'Teknik Komputer',
            'cohort_year' => 2025,
            'student_count' => fake()->numberBetween(25, 45),
            'is_active' => true,
        ];
    }

    /**
     * Kelas yang sudah tidak aktif.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }
}
