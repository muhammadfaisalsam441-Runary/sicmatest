<?php

namespace App\Http\Controllers\Admin;

use App\Enums\RoomType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RoomRequest;
use App\Models\Period;
use App\Models\Room;
use App\Models\RoomClosure;
use App\Services\RoomService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Kelola Ruang: daftar, tambah, ubah, hapus, dan penutupan ruang.
 */
class RoomController extends Controller
{
    public function index(Request $request): View
    {
        $keyword = trim((string) $request->query('q'));
        $type = RoomType::tryFrom((string) $request->query('jenis'));

        $rooms = Room::query()
            ->with(['building', 'facilities'])
            ->when($keyword !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('name', 'like', "%{$keyword}%")
                ->orWhereHas('building', fn (Builder $query) => $query->where('name', 'like', "%{$keyword}%"))))
            ->when($type !== null, fn (Builder $query) => $query->where('type', $type))
            ->get()
            ->sortBy('name', SORT_NATURAL)
            ->values();

        return view('admin.rooms.index', [
            'rooms' => $rooms,
            'totalRooms' => Room::query()->count(),
            'periodName' => Period::query()->active()->value('name'),
            'keyword' => $keyword,
            'type' => $type,
            'allRooms' => Room::query()->orderBy('name')->pluck('name', 'id'),
            'closures' => RoomClosure::query()
                ->with('room')
                ->where('end_at', '>', now())
                ->orderBy('start_at')
                ->get(),
        ]);
    }

    public function store(RoomRequest $request, RoomService $rooms): RedirectResponse
    {
        $room = $rooms->save(null, $request->validated(), $request->facilityNames());

        return to_route('admin.rooms.index')->with('toast', ['type' => 'success', 'title' => "Ruang {$room->name} berhasil disimpan", 'message' => 'Ruang baru langsung muncul di daftar.']);
    }

    public function update(RoomRequest $request, Room $room, RoomService $rooms): RedirectResponse
    {
        $rooms->save($room, $request->validated(), $request->facilityNames());

        return to_route('admin.rooms.index')->with('toast', ['type' => 'success', 'title' => "Ruang {$room->name} berhasil disimpan", 'message' => 'Perubahan langsung dipakai di aplikasi ketua tingkat.']);
    }

    public function destroy(Request $request, Room $room, RoomService $rooms): RedirectResponse
    {
        $rooms->delete($room, $request->string('reason')->trim()->toString() ?: null);

        return to_route('admin.rooms.index')->with('toast', ['type' => 'success', 'title' => "Ruang {$room->name} dihapus"]);
    }
}
