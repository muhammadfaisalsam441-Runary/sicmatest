<?php

namespace App\Http\Controllers\Keti;

use App\Http\Controllers\Controller;
use App\Models\Room\Room;
use App\Services\Room\AvailabilityService;
use App\Support\RoomCardPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FavoriteController extends Controller
{
    public function index(Request $request, AvailabilityService $availability): View
    {
        $request->validate(['status' => ['nullable', 'in:tersedia,digunakan,tutup']]);

        $rooms = $request->user()->favoriteRooms()->with(['building', 'facilities'])->where('is_active', true)->orderBy('name')->get();
        $results = $availability->forRooms($rooms, CarbonImmutable::now());
        $cards = $results->map(RoomCardPresenter::card(...));

        $filtered = match ($request->query('status')) {
            'tersedia' => $cards->where('badge', 'Tersedia'),
            'digunakan' => $cards->where('badge', 'Digunakan'),
            'tutup' => $cards->where('badge', 'Tutup'),
            default => $cards,
        };

        return view('keti.favorites', [
            'rooms' => $filtered->values()->all(),
            'counts' => [
                'all' => $cards->count(),
                'available' => $cards->where('badge', 'Tersedia')->count(),
                'busy' => $cards->where('badge', 'Digunakan')->count(),
                'closed' => $cards->where('badge', 'Tutup')->count(),
            ],
            'status' => $request->query('status'),
        ]);
    }

    public function store(Request $request, Room $room): RedirectResponse
    {
        $request->user()->favoriteRooms()->syncWithoutDetaching([$room->id]);

        return back()->with('toast', ['type' => 'success', 'title' => 'Ditambahkan ke favorit', 'message' => $room->name.' tersimpan di Ruangan Favorit.']);
    }

    public function destroy(Request $request, Room $room): RedirectResponse
    {
        $request->user()->favoriteRooms()->detach($room->id);

        return back()->with('toast', ['type' => 'success', 'title' => 'Favorit dihapus', 'message' => $room->name.' dihapus dari Ruangan Favorit.']);
    }
}
