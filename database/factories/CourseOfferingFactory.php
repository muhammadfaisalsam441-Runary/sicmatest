<?php

namespace Database\Factories;

use App\Models\ClassGroup;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\Period;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CourseOffering>
 */
class CourseOfferingFactory extends Factory
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
            'course_id' => Course::factory(),
            'class_group_id' => ClassGroup::factory(),
        ];
    }
}
