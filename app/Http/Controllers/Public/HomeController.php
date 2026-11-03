<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Services\Availability\SearchCriteria;
use App\Services\AvailabilityService;
use App\Support\AvailabilityCache;
use App\Support\RoomCardPresenter;
use Carbon\CarbonImmutable;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function __invoke(AvailabilityService $availability): View
    {
        $now = CarbonImmutable::now()->startOfMinute();

        $data = AvailabilityCache::remember('home:'.$now->format('YmdHi'), function () use ($availability, $now): array {
            $available = $availability->search(new SearchCriteria(start: $now, durationMinutes: 1, status: 'available'));

            return [
                'availableCount' => $available->count(),
                'preview' => $available->take(3)->map(RoomCardPresenter::card(...))->values()->all(),
                'checkedAt' => $now,
            ];
        });

        return view('public.home', $data);
    }
}
