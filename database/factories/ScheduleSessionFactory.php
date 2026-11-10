<?php

namespace Database\Factories;

use App\Enums\SessionMode;
use App\Enums\SessionPhase;
use App\Models\Room\Room;
use App\Models\Scheduling\ScheduleSession;
use App\Models\Scheduling\ScheduleVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduleSession>
 */
class ScheduleSessionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'schedule_version_id' => ScheduleVersion::factory(),
            'course_offering_id' => null,
            'title' => ucfirst(fake()->words(2, true)),
            'mode' => SessionMode::Offline,
            'day_of_week' => 1,
            'start_minute' => 450,
            'end_minute' => 600,
            'room_id' => Room::factory(),
            'phase' => SessionPhase::Full,
            'is_locked' => false,
        ];
    }

    /**
     * Sesi kuliah online (tidak memblokir ruang).
     */
    public function online(): static
    {
        return $this->state(fn (array $attributes) => [
            'mode' => SessionMode::Online,
            'room_id' => null,
        ]);
    }

    /**
     * Sesi belum terjadwal, mis. baris impor "Hubungi Dosen".
     */
    public function unscheduled(): static
    {
        return $this->state(fn (array $attributes) => [
            'mode' => SessionMode::Unscheduled,
            'day_of_week' => null,
            'start_minute' => null,
            'end_minute' => null,
            'room_id' => null,
            'source_raw' => ['waktu' => 'Hubungi Dosen'],
        ]);
    }
}
