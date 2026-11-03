<?php

namespace Database\Factories;

use App\Enums\ScheduleSource;
use App\Enums\ScheduleVersionStatus;
use App\Models\Period;
use App\Models\ScheduleVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduleVersion>
 */
class ScheduleVersionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'period_id' => Period::factory(),
            'version_no' => fake()->unique()->numberBetween(1, 999),
            'status' => ScheduleVersionStatus::Draft,
            'source' => ScheduleSource::Manual,
            'effective_from' => '2026-09-01',
            'effective_to' => null,
        ];
    }

    /**
     * Versi berstatus Jadwal resmi.
     */
    public function official(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ScheduleVersionStatus::Official,
            'published_at' => now(),
        ]);
    }
}
