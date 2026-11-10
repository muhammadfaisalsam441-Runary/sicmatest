<?php

namespace App\Http\Controllers\Keti;

use App\Http\Controllers\Controller;
use App\Http\Requests\Keti\RoomCalendarRequest;
use App\Models\Room\Building;
use App\Services\Room\AvailabilityService;
use App\Services\Room\SearchCriteria;
use Carbon\CarbonImmutable;
use Illuminate\View\View;

class RoomCalendarController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(RoomCalendarRequest $request, AvailabilityService $availability): View
    {
        $today = CarbonImmutable::today();
        $duration = (int) ($request->validated('durasi') ?? config('sicma.reservation.default_minutes'));
        $building = $request->validated('gedung');
        $days = collect(range(0, (int) config('sicma.reservation.max_days_ahead')))
            ->map(fn (int $offset): CarbonImmutable => $today->addDays($offset))
            ->filter(fn (CarbonImmutable $day): bool => in_array($day->dayOfWeekIso, config('sicma.scheduler.days'), true))
            ->values();
        [, $close] = $availability->operatingWindow($today);
        $earliest = CarbonImmutable::now()->startOfMinute()->addMinutes($duration)->greaterThan($close) ? $today->addDay() : $today;
        $defaultDate = $days->first(fn (CarbonImmutable $day): bool => $day->greaterThanOrEqualTo($earliest)) ?? $days->last();
        $date = CarbonImmutable::parse($request->validated('tanggal') ?? $defaultDate->toDateString());

        return view('keti.rooms.calendar', [
            'date' => $date,
            'showingNextTeachingDay' => $request->validated('tanggal') === null && $date->greaterThan($today),
            'today' => $today,
            'days' => $days,
            'duration' => $duration,
            'filters' => $request->validated(),
            'buildings' => Building::query()->orderBy('name')->get(),
            'scheduleConfirmed' => $availability->hasOfficialScheduleOn($date),
            'rows' => $availability->dailySlots(new SearchCriteria(
                start: $date,
                durationMinutes: $duration,
                keyword: $request->validated('q'),
                building: $building === 'lainnya' ? 'none' : $building,
                minCapacity: $request->validated('orang') === null ? null : (int) $request->validated('orang'),
            )),
        ]);
    }
}
