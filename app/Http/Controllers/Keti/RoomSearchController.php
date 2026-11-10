<?php

namespace App\Http\Controllers\Keti;

use App\Http\Controllers\Controller;
use App\Http\Requests\Keti\RoomSearchRequest;
use App\Models\Room\Building;
use App\Services\Room\AvailabilityService;
use App\Services\Room\SearchCriteria;
use App\Support\RoomCardPresenter;
use App\Support\Wita;
use Illuminate\View\View;
use Throwable;

class RoomSearchController extends Controller
{
    public function __invoke(RoomSearchRequest $request, AvailabilityService $availability): View
    {
        $start = $request->start();
        $duration = $request->duration();
        $buildings = Building::query()->orderBy('name')->get();
        $building = $request->validated('gedung');
        $sort = match ($request->validated('urutan')) {
            'nama' => SearchCriteria::SORT_NAME,
            'kapasitas' => SearchCriteria::SORT_CAPACITY,
            default => SearchCriteria::SORT_LONGEST,
        };

        $loadFailed = false;
        $rooms = [];

        try {
            $rooms = $availability->search(new SearchCriteria(
                start: $start,
                durationMinutes: $duration,
                keyword: $request->validated('q'),
                building: $building === 'lainnya' ? 'none' : $building,
                minCapacity: $request->validated('orang') === null ? null : (int) $request->validated('orang'),
                status: 'available',
                sort: $sort,
            ))->map(RoomCardPresenter::card(...))->values()->all();
        } catch (Throwable $exception) {
            report($exception);
            $loadFailed = true;
        }

        $buildingLabel = match (true) {
            $building === 'lainnya' => 'Gedung belum didata',
            is_numeric($building) => $buildings->firstWhere('id', (int) $building)?->name ?? 'Semua gedung',
            default => 'Semua gedung',
        };

        return view('keti.search', [
            'rooms' => $rooms,
            'loadFailed' => $loadFailed,
            'start' => $start,
            'duration' => $duration,
            'buildings' => $buildings,
            'filters' => $request->validated(),
            'isNow' => ($request->validated('mulai') ?? 'sekarang') === 'sekarang' && ($request->validated('tanggal') ?? now()->toDateString()) === now()->toDateString(),
            'criteriaLabel' => 'Untuk '.Wita::duration($duration).' • '.$buildingLabel.' • '.match ($sort) {
                SearchCriteria::SORT_NAME => 'Nama ruangan',
                SearchCriteria::SORT_CAPACITY => 'Kapasitas terbesar',
                default => 'Waktu terlama',
            },
            'showFilter' => $request->query('tab') === 'filter',
        ]);
    }
}
