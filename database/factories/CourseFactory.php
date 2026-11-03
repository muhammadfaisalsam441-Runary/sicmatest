<?php

namespace Database\Factories;

use App\Models\Course;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Course>
 */
class CourseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('TK####')),
            'name' => ucfirst(fake()->words(3, true)),
            'sks' => fake()->randomElement([2, 3]),
            'curriculum' => null,
        ];
    }
}
