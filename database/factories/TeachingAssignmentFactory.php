<?php

namespace Database\Factories;

use App\Enums\SessionPhase;
use App\Enums\TeachingRole;
use App\Models\CourseOffering;
use App\Models\Lecturer;
use App\Models\TeachingAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TeachingAssignment>
 */
class TeachingAssignmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'course_offering_id' => CourseOffering::factory(),
            'lecturer_id' => Lecturer::factory(),
            'role' => TeachingRole::Lead,
            'credited_sks' => null,
            'phase' => SessionPhase::Full,
            'is_locked' => false,
        ];
    }
}
