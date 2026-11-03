<?php

namespace Database\Factories;

use App\Models\Room;
use App\Models\RoomClosure;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoomClosure>
 */
class RoomClosureFactory extends Factory
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
            'end_at' => now()->startOfHour()->addHours(2),
            'reason' => 'Perawatan ruang',
            'created_by' => User::factory()->admin(),
        ];
    }
}
