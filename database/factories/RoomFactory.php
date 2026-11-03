<?php

namespace Database\Factories;

use App\Enums\RoomType;
use App\Models\Building;
use App\Models\Room;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Room>
 */
class RoomFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'building_id' => Building::factory(),
            'code' => fake()->unique()->bothify('R-###??'),
            'name' => fake()->unique()->bothify('Ruang ###'),
            'floor' => fake()->numberBetween(1, 3),
            'capacity' => fake()->numberBetween(30, 60),
            'type' => RoomType::Classroom,
            'is_bookable' => true,
            'is_active' => true,
        ];
    }

    /**
     * Ruang yang belum terdata gedungnya.
     */
    public function withoutBuilding(): static
    {
        return $this->state(fn (array $attributes) => ['building_id' => null]);
    }
}
