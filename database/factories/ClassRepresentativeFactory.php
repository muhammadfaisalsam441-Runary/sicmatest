<?php

namespace Database\Factories;

use App\Models\ClassGroup;
use App\Models\ClassRepresentative;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClassRepresentative>
 */
class ClassRepresentativeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'class_group_id' => ClassGroup::factory(),
            'valid_from' => now()->subMonth()->toDateString(),
            'valid_to' => null,
        ];
    }
}
