<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Services\AdminDashboardService;
use App\Services\AvailabilityService;
use App\Services\OverdueReservationService;
use Illuminate\View\View;

/**
 * Dashboard "Ruang JTIK hari ini".
 */
class DashboardController extends Controller
{
    public function __invoke(AvailabilityService $availability, AdminDashboardService $dashboard, OverdueReservationService $overdue): View
    {
        $now = now();

        return view('admin.dashboard', [
            'now' => $now,
            'summary' => $availability->summary($now),
            'progress' => $dashboard->scheduleProgress(),
            'roomStatuses' => $dashboard->roomStatuses($now),
            'notStarted' => $overdue->notStarted(),
            'overrunning' => $overdue->overrunning(),
            'latestReservations' => Reservation::query()
                ->with(['room', 'classGroup'])
                ->latest()
                ->limit(5)
                ->get(),
        ]);
    }
}
