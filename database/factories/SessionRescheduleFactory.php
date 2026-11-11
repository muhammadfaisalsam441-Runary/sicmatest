<?php

namespace Database\Factories;

use App\Enums\RescheduleStatus;
use App\Models\Academic\SessionReschedule;
use App\Models\Reservation\Reservation;
use App\Models\Room\Room;
use App\Models\Scheduling\ScheduleSession;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SessionReschedule>
 */
class SessionRescheduleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'schedule_session_id' => ScheduleSession::factory(),
            'course_offering_id' => null,
            'session_title' => 'Pemrograman Web',
            'participant_key' => '',
            'original_start_at' => '2026-11-09 07:30',
            'original_end_at' => '2026-11-09 10:00',
            'original_room_id' => Room::factory(),
            'reservation_id' => Reservation::factory(),
            'status' => RescheduleStatus::Active,
            'created_by' => User::factory(),
        ];
    }
}
