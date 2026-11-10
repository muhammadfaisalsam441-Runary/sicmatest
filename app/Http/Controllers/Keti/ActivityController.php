<?php

namespace App\Http\Controllers\Keti;

use App\Http\Controllers\Controller;
use App\Models\Reservation\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ActivityController extends Controller
{
    public function __invoke(Request $request): View
    {
        $request->validate([
            'cakupan' => ['nullable', 'in:semua,saya'],
            'tanggal' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $scope = $request->query('cakupan', 'semua');
        $date = CarbonImmutable::parse($request->query('tanggal', now()->toDateString()));
        $classGroup = $request->user()->activeClassGroup();

        $base = Reservation::query()->whereDate('start_at', $date->toDateString());

        return view('keti.activity', [
            'scope' => $scope,
            'date' => $date,
            'reservations' => (clone $base)
                ->when($scope === 'saya', fn ($query) => $query->whereBelongsTo($classGroup))
                ->with(['room', 'classGroup', 'creator'])
                ->orderByDesc('start_at')
                ->get(),
            'myCount' => (clone $base)->whereBelongsTo($classGroup)->count(),
            'classGroup' => $classGroup,
        ]);
    }
}
