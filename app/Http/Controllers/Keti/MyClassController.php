<?php

namespace App\Http\Controllers\Keti;

use App\Enums\ReservationStatus;
use App\Enums\SessionMode;
use App\Http\Controllers\Controller;
use App\Models\Reservation\Reservation;
use App\Models\Scheduling\ScheduleSession;
use App\Services\Academic\ClassScheduleService;
use App\Services\Academic\MeetingLocator;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MyClassController extends Controller
{
    public function __invoke(Request $request, ClassScheduleService $schedules, MeetingLocator $locator): View
    {
        $request->validate(['tanggal' => ['nullable', 'date_format:Y-m-d']]);

        $classGroup = $request->user()->activeClassGroup();
        $dateParam = $request->query('tanggal');
        if ($dateParam === null) {
            $now = CarbonImmutable::now();
            $date = $now->isWeekend() ? $now->next(CarbonImmutable::MONDAY)->startOfDay() : $now->startOfDay();
        } else {
            $date = CarbonImmutable::parse($dateParam)->startOfDay();
        }
        $weekStart = $date->startOfWeek();
        $now = CarbonImmutable::now();

        $reservations = Reservation::query()
            ->whereDate('start_at', $date->toDateString())
            ->where('status', '!=', ReservationStatus::Cancelled)
            ->with(['room', 'sessionReschedule'])
            ->orderBy('start_at')
            ->get();

        return view('keti.my-class', [
            'classGroup' => $classGroup,
            'date' => $date,
            'days' => collect(config('sicma.scheduler.days'))->map(fn (int $day): CarbonImmutable => $weekStart->addDays($day - 1)),
            'meetings' => $schedules->sessionsFor($classGroup, $date)->map(function (ScheduleSession $session) use ($date, $now, $locator): array {
                $start = $date->addMinutes($session->start_minute);
                $end = $date->addMinutes($session->end_minute);
                $reschedule = $session->blocksRoom() ? $locator->activeRescheduleFor($session, $start) : null;

                return [
                    'session' => $session,
                    'start' => $start,
                    'end' => $end,
                    'reschedule' => $reschedule?->load('reservation.room'),
                    'status' => match (true) {
                        $session->mode === SessionMode::Online => 'online',
                        $reschedule !== null => 'moved',
                        $end->lessThanOrEqualTo($now) => 'finished',
                        $start->lessThanOrEqualTo($now) => 'ongoing',
                        default => 'upcoming',
                    },
                ];
            }),
            // Pengganti pertemuan kuliah yang diikuti kelas ini, termasuk yang dipindah Keti kelas gabungan lain.
            'replacements' => $reservations->filter(fn (Reservation $reservation): bool => $reservation->sessionReschedule !== null
                && in_array((string) $classGroup->id, explode(',', $reservation->sessionReschedule->participant_key), true))->values(),
            'reservations' => $reservations->filter(fn (Reservation $reservation): bool => $reservation->session_reschedule_id === null
                && $reservation->class_group_id === $classGroup->id)->values(),
        ]);
    }
}
