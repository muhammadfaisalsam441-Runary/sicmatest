<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Room\Building;
use App\Models\Room\Room;
use App\Services\Room\AvailabilityService;
use App\Services\Room\SearchCriteria;
use App\Support\AvailabilityCache;
use App\Support\RoomCardPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class RoomController extends Controller
{
    public function __invoke(Request $request, AvailabilityService $availability): View
    {
        $keyword = Str::limit(trim((string) $request->query('q')), 60, '') ?: null;
        $building = $request->query('gedung');
        $status = match ($request->query('status')) {
            'tersedia' => 'available',
            'digunakan' => 'busy',
            'tutup' => 'closed',
            default => null,
        };

        $buildings = Building::query()->withCount(['rooms' => fn ($query) => $query->where('is_active', true)])->orderBy('name')->get();
        $selectedBuilding = is_numeric($building) ? $buildings->firstWhere('id', (int) $building) : null;
        $buildingFilter = $building === 'lainnya' ? 'none' : $selectedBuilding?->id;
        $now = CarbonImmutable::now()->startOfMinute();

        $data = AvailabilityCache::remember('rooms:'.md5(json_encode([$keyword, $buildingFilter, $status, $now->format('YmdHi')])), function () use ($availability, $now, $keyword, $buildingFilter, $status): array {
            $results = $availability->search(new SearchCriteria(
                start: $now,
                durationMinutes: 1,
                keyword: $keyword,
                building: $buildingFilter,
                status: $status,
                sort: SearchCriteria::SORT_NAME,
            ));

            return [
                'rooms' => $results->map(RoomCardPresenter::card(...))->values()->all(),
                'summary' => $availability->summary($now),
            ];
        });

        $buildingLabel = match (true) {
            $building === 'lainnya' => 'Gedung belum didata',
            $selectedBuilding !== null => 'Gedung '.$selectedBuilding->name,
            default => 'Semua gedung',
        };

        $title = match (true) {
            $keyword !== null => 'Hasil pencarian “'.$keyword.'”',
            $status === 'available' => 'Ruangan tersedia saat ini',
            $status === 'busy' => 'Ruangan sedang digunakan',
            $status === 'closed' => 'Ruangan tutup saat ini',
            $selectedBuilding !== null => 'Ruangan di gedung '.$selectedBuilding->name,
            $building === 'lainnya' => 'Ruangan lainnya',
            default => 'Semua ruangan JTIK',
        };

        return view('public.rooms', [
            ...$data,
            'checkedAt' => $now,
            'title' => $title,
            'keyword' => $keyword,
            'building' => $building,
            'buildingLabel' => $buildingLabel,
            'status' => $request->query('status'),
            'buildings' => $buildings,
            'roomsWithoutBuilding' => Room::query()->where('is_active', true)->whereNull('building_id')->count(),
        ]);
    }
}
