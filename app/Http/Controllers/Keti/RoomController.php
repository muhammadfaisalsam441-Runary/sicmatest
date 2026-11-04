<?php

namespace App\Http\Controllers\Keti;

use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Models\Building;
use App\Models\Reservation;
use App\Models\Room;
use App\Services\AgendaDescriber;
use App\Services\Availability\SearchCriteria;
use App\Services\AvailabilityService;
use App\Support\RoomCardPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class RoomController extends Controller
{
    /**
     * Ruangan JTIK: semua ruang dengan filter gedung, lantai, dan status.
     */
    public function index(Request $request, AvailabilityService $availability): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:60'],
            'gedung' => ['nullable', 'string', 'max:20'],
            'lantai' => ['nullable', 'integer', 'min:0', 'max:20'],
            'status' => ['nullable', 'in:tersedia,digunakan,tutup'],
        ]);

        $now = CarbonImmutable::now()->startOfMinute();
        $results = $availability->search(new SearchCriteria(
            start: $now,
            durationMinutes: 1,
            keyword: $filters['q'] ?? null,
            building: ($filters['gedung'] ?? null) === 'lainnya' ? 'none' : ($filters['gedung'] ?? null),
            floor: isset($filters['lantai']) ? (int) $filters['lantai'] : null,
            status: match ($filters['status'] ?? null) {
                'tersedia' => 'available',
                'digunakan' => 'busy',
                'tutup' => 'closed',
                default => null,
            },
            sort: SearchCriteria::SORT_NAME,
        ));

        return view('keti.rooms.index', [
            'rooms' => $results->map(RoomCardPresenter::card(...))->values()->all(),
            'totalRooms' => Room::query()->where('is_active', true)->count(),
            'buildings' => Building::query()->orderBy('name')->get(),
            'floors' => Room::query()->whereNotNull('floor')->distinct()->orderBy('floor')->pluck('floor'),
            'filters' => $filters,
            'checkedAt' => $now,
        ]);
    }

    /**
     * Detail ruang + panel "Gunakan untuk kelasmu".
     */
    public function show(Request $request, Room $room, AvailabilityService $availability, AgendaDescriber $agenda): View
    {
        abort_unless($room->is_active, 404);

        $request->validate([
            'mulai' => ['nullable', 'date'],
            'durasi' => ['nullable', 'integer', 'min:'.config('sicma.reservation.min_minutes'), 'max:'.config('sicma.reservation.max_minutes')],
        ]);

        $now = CarbonImmutable::now()->startOfMinute();
        $start = $request->filled('mulai') ? CarbonImmutable::parse($request->query('mulai'))->startOfMinute() : $now;
        $start = $start->lessThan($now) ? $now : $start;
        $duration = (int) $request->query('durasi', config('sicma.reservation.default_minutes'));
        $room->load(['building', 'facilities']);

        $current = $availability->forRoom($room, $now);
        $requested = $availability->forRoom($room, $start, $start->addMinutes($duration));
        $user = $request->user();

        return view('keti.rooms.show', [
            'room' => $room,
            'current' => $current,
            'requested' => $requested,
            'start' => $start,
            'end' => $start->addMinutes($duration),
            'duration' => $duration,
            'isNow' => $start->equalTo($now),
            'currentAgenda' => $current->currentBlock ? $agenda->describe($current->currentBlock) : null,
            'nextAgenda' => $current->nextBlock ? $agenda->describe($current->nextBlock) : null,
            'classGroup' => $user->activeClassGroup(),
            'isFavorite' => $user->favoriteRooms()->whereKey($room->id)->exists(),
            'history' => Reservation::query()
                ->whereBelongsTo($room)
                ->whereDate('start_at', $now->toDateString())
                ->whereIn('status', [ReservationStatus::InUse, ReservationStatus::Finished])
                ->with('classGroup')
                ->orderByDesc('start_at')
                ->get(),
            'idempotencyKey' => (string) Str::uuid(),
        ]);
    }
}
