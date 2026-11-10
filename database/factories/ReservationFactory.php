<?php

namespace Database\Factories;

use App\Enums\ReservationStatus;
use App\Models\Academic\ClassGroup;
use App\Models\Reservation\Reservation;
use App\Models\Room\Room;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Reservation>
 */
class ReservationFactory extends Factory
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
            'class_group_id' => ClassGroup::factory(),
            'created_by' => User::factory(),
            'start_at' => now()->addHour()->startOfHour(),
            'end_at' => now()->addHours(2)->startOfHour(),
            'participants' => fake()->numberBetween(10, 40),
            'purpose' => 'Kerja kelompok',
            'status' => ReservationStatus::Booked,
            'idempotency_key' => (string) Str::uuid(),
        ];
    }
}
