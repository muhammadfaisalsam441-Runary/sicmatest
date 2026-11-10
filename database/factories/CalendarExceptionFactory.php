<?php

namespace Database\Factories;

use App\Enums\CalendarExceptionType;
use App\Models\Academic\CalendarException;
use App\Models\Academic\Period;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CalendarException>
 */
class CalendarExceptionFactory extends Factory
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
            'date' => '2026-12-25',
            'type' => CalendarExceptionType::Holiday,
            'note' => 'Hari libur',
        ];
    }
}
