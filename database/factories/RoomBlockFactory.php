<?php

namespace Database\Factories;

use App\Enums\RoomBlockSource;
use App\Models\Room;
use App\Models\RoomBlock;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoomBlock>
 */
class RoomBlockFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'room_id' => Room::factory(),
            'start_at' => now()->startOfHour(),
            'end_at' => now()->startOfHour()->addHour(),
            'source_type' => RoomBlockSource::Reservation,
            'source_id' => fake()->numberBetween(1, 100000),
            'schedule_version_id' => null,
            'is_active' => true,
        ];
    }

    /**
     * Blok yang sudah tidak berlaku.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }
}
