<?php

namespace App\Http\Controllers\Admin;

use App\Enums\RoomType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RoomRequest;
use App\Models\Building;
use App\Models\Facility;
use App\Models\Period;
use App\Models\Room;
use App\Models\RoomClosure;
use App\Services\RoomService;
use App\Support\AvailabilityCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Kelola Ruang: daftar, tambah, ubah, hapus, batch update, dan penutupan ruang.
 */
class RoomController extends Controller
{
    public function index(Request $request): View
    {
        $keyword = trim((string) $request->query('q'));
        $filter = (string) $request->query('filter', 'semua');
        $buildingFilter = trim((string) $request->query('gedung'));
        $perPage = in_array((int) $request->query('per_page'), [15, 30, 50, 100], true) ? (int) $request->query('per_page') : 30;

        // Backward compatibility dengan ?jenis=
        if ($request->has('jenis')) {
            $jenis = (string) $request->query('jenis');
            if ($jenis === 'classroom') {
                $filter = 'kuliah';
            } elseif ($jenis === 'lab') {
                $filter = 'lab';
            }
        }

        $type = match ($filter) {
            'kuliah' => RoomType::Classroom,
            'lab' => RoomType::Lab,
            default => null,
        };

        $query = Room::query()
            ->with(['building', 'facilities', 'closures' => fn ($q) => $q->where('end_at', '>', now())])
            ->when($keyword !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('name', 'like', "%{$keyword}%")
                ->orWhere('code', 'like', "%{$keyword}%")
                ->orWhereHas('building', fn (Builder $query) => $query->where('name', 'like', "%{$keyword}%"))
                ->orWhereHas('facilities', fn (Builder $query) => $query->where('name', 'like', "%{$keyword}%"))
            ))
            ->when($filter === 'verifikasi', fn (Builder $query) => $query->where(fn (Builder $q) => $q->whereNull('capacity')->orWhereNull('building_id')))
            ->when($filter === 'ditutup', fn (Builder $query) => $query->whereHas('closures', fn ($q) => $q->where('end_at', '>', now())))
            ->when($type !== null, fn (Builder $query) => $query->where('type', $type))
            ->when($buildingFilter !== '', function (Builder $query) use ($buildingFilter) {
                if ($buildingFilter === 'belum_didata') {
                    $query->whereNull('building_id');
                } else {
                    $query->whereHas('building', fn (Builder $q) => $q->where('name', $buildingFilter)->orWhere('code', $buildingFilter));
                }
            })
            ->orderBy('name');

        $rooms = $query->paginate($perPage)->withQueryString();

        $activeClosures = RoomClosure::query()
            ->with('room')
            ->where('end_at', '>', now())
            ->orderBy('start_at')
            ->get();

        $buildings = Building::query()->orderBy('name')->get();

        return view('admin.rooms.index', [
            'rooms' => $rooms,
            'totalRooms' => Room::query()->count(),
            'periodName' => Period::query()->active()->value('name'),
            'keyword' => $keyword,
            'filter' => $filter,
            'buildingFilter' => $buildingFilter,
            'perPage' => $perPage,
            'type' => $type,
            'unverifiedCount' => Room::query()->where(fn (Builder $q) => $q->whereNull('capacity')->orWhereNull('building_id'))->count(),
            'classroomCount' => Room::query()->where('type', RoomType::Classroom)->count(),
            'labCount' => Room::query()->where('type', RoomType::Lab)->count(),
            'closedRoomsCount' => $activeClosures->pluck('room_id')->unique()->count(),
            'buildings' => $buildings,
            'allRooms' => Room::query()->orderBy('name')->pluck('name', 'id'),
            'closures' => $activeClosures,
        ]);
    }

    public function batchUpdate(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'string', 'in:set_building,set_type,add_facilities'],
            'room_ids' => ['required', 'array', 'min:1'],
            'room_ids.*' => ['integer', 'exists:rooms,id'],
            'building' => ['nullable', 'string', 'max:60'],
            'type' => ['nullable', Rule::enum(RoomType::class)],
            'facilities' => ['nullable', 'string', 'max:255'],
        ]);

        $ids = $validated['room_ids'];
        $count = count($ids);

        match ($validated['action']) {
            'set_building' => (function () use ($ids, $validated): void {
                $buildingName = trim((string) ($validated['building'] ?? ''));
                $buildingId = $buildingName !== '' ? Building::firstOrCreate(['name' => $buildingName, 'code' => Str::upper(Str::slug($buildingName))])->id : null;
                Room::query()->whereIn('id', $ids)->update(['building_id' => $buildingId]);
            })(),
            'set_type' => (function () use ($ids, $validated): void {
                if (! empty($validated['type'])) {
                    Room::query()->whereIn('id', $ids)->update(['type' => $validated['type']]);
                }
            })(),
            'add_facilities' => (function () use ($ids, $validated): void {
                $facilityNames = collect(explode(',', (string) ($validated['facilities'] ?? '')))
                    ->map(fn (string $n): string => Str::of($n)->trim()->ucfirst()->toString())
                    ->filter()
                    ->unique();
                if ($facilityNames->isNotEmpty()) {
                    $facilityIds = $facilityNames->map(fn (string $n): int => Facility::firstOrCreate(['name' => $n])->id)->all();
                    foreach (Room::query()->whereIn('id', $ids)->get() as $room) {
                        $room->facilities()->syncWithoutDetaching($facilityIds);
                    }
                }
            })(),
        };

        AvailabilityCache::bust();

        return back()->with('toast', [
            'type' => 'success',
            'title' => "Berhasil memperbarui {$count} ruang",
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
