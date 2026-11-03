<?php

namespace App\Http\Controllers\Keti;

use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Services\ClassScheduleService;
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
            'days' => collect(range(0, 4))->map(fn (int $offset): CarbonImmutable => $weekStart->addDays($offset)),
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
