<?php

namespace App\Http\Controllers\Keti;

use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Models\Reservation\Reservation;
use App\Services\Academic\ClassScheduleService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MyClassController extends Controller
{
    public function __invoke(Request $request, ClassScheduleService $schedules): View
    {
        $request->validate(['tanggal' => ['nullable', 'date_format:Y-m-d']]);

        $classGroup = $request->user()->activeClassGroup();
        $date = CarbonImmutable::parse($request->query('tanggal', now()->toDateString()))->startOfDay();
        $weekStart = $date->startOfWeek();

        return view('keti.my-class', [
            'classGroup' => $classGroup,
            'date' => $date,
            'days' => collect(config('sicma.scheduler.days'))->map(fn (int $day): CarbonImmutable => $weekStart->addDays($day - 1)),
            'sessions' => $schedules->sessionsFor($classGroup, $date),
            'reservations' => Reservation::query()
                ->whereBelongsTo($classGroup)
                ->whereDate('start_at', $date->toDateString())
                ->where('status', '!=', ReservationStatus::Cancelled)
                ->with('room')
                ->orderBy('start_at')
                ->get(),
        ]);
    }
}
