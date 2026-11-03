<?php

namespace App\Http\Controllers\Keti;

use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Models\Reservation;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();
        $classGroup = $user->activeClassGroup();

        return view('keti.profile', [
            'user' => $user,
            'classGroup' => $classGroup,
            'usageToday' => Reservation::query()
                ->whereBelongsTo($classGroup)
                ->whereDate('start_at', now()->toDateString())
                ->whereIn('status', [ReservationStatus::Booked, ReservationStatus::InUse, ReservationStatus::Finished])
                ->count(),
            'favoriteCount' => $user->favoriteRooms()->count(),
        ]);
    }
}
