<?php

namespace App\Http\Controllers\Admin;

use App\Enums\RoomType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BatchRoomRequest;
use App\Http\Requests\Admin\RoomRequest;
use App\Models\Academic\Period;
use App\Models\Room\Building;
use App\Models\Room\Room;
use App\Models\Room\RoomClosure;
use App\Services\Room\RoomService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Kelola Ruang: daftar, tambah, ubah, hapus, batch update, dan penutupan ruang.
 */
class RoomController extends Controller
{
    /**
     * @var list<string>
     */
    private const SORTS = ['name', 'type', 'capacity'];

    public function index(Request $request): View
    {
        $keyword = trim((string) $request->query('q'));
        $filter = (string) $request->query('filter', 'semua');
        $buildingFilter = trim((string) $request->query('gedung'));
        $perPage = in_array((int) $request->query('per_page'), [15, 30, 50, 100], true) ? (int) $request->query('per_page') : 30;
        $sort = in_array((string) $request->query('sort'), self::SORTS, true) ? (string) $request->query('sort') : 'name';
        $dir = $request->query('dir') === 'desc' ? 'desc' : 'asc';

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

        $now = now();

        $rooms = $this->filteredQuery($keyword, $filter, $buildingFilter, $type, $sort, $dir, $now)
            ->paginate($perPage)
            ->withQueryString();

        $activeClosures = RoomClosure::query()
            ->with('room')
            ->where('start_at', '<=', $now)
            ->where('end_at', '>', $now)
            ->orderBy('start_at')
            ->get();

        $buildings = Building::query()->orderBy('name')->get();
        $roomsPayload = $rooms->getCollection()->mapWithKeys(fn (Room $r): array => [$r->id => [
            'id' => $r->id,
            'name' => $r->name,
            'code' => $r->code,
            'building' => $r->building?->name ?? '',
            'floor' => $r->floor ?? '',
            'type' => $r->type->value,
            'capacity' => $r->capacity ?? '',
        ]])->all();

        return view('admin.rooms.index', [
            'rooms' => $rooms,
            'roomsPayload' => $roomsPayload,
            'totalRooms' => Room::query()->count(),
            'periodName' => Period::query()->active()->value('name'),
            'keyword' => $keyword,
            'filter' => $filter,
            'buildingFilter' => $buildingFilter,
            'perPage' => $perPage,
            'sort' => $sort,
            'dir' => $dir,
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

    public function batchUpdate(BatchRoomRequest $request, RoomService $rooms): RedirectResponse
    {
        $count = $rooms->batchUpdate(
            array_map('intval', $request->validated('room_ids')),
            $request->validated('action'),
            $request->safe()->only(['building', 'type', 'facilities', 'capacity']),
        );

        return back()->with('toast', [
            'type' => 'success',
            'title' => "Berhasil memperbarui {$count} ruang",
        ]);
    }

    public function bulkUpdate(Request $request, RoomService $rooms): RedirectResponse
    {
        $validated = $request->validate([
            'rooms' => ['required', 'array', 'min:1'],
            'rooms.*.id' => ['required', 'integer', Rule::exists('rooms', 'id')->whereNull('deleted_at')],
            'rooms.*.name' => ['required', 'string', 'max:60'],
            'rooms.*.type' => ['required', Rule::enum(RoomType::class)],
            'rooms.*.building' => ['nullable', 'string', 'max:60'],
            'rooms.*.floor' => ['nullable', 'integer', 'min:0', 'max:20'],
            'rooms.*.capacity' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $count = $rooms->bulkUpdate($validated['rooms']);

        return back()->with('toast', [
            'type' => 'success',
            'title' => "Berhasil menyimpan {$count} ruang",
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

    public function export(Request $request): StreamedResponse
    {
        $keyword = trim((string) $request->query('q'));
        $filter = (string) $request->query('filter', 'semua');
        $buildingFilter = trim((string) $request->query('gedung'));
        $sort = in_array((string) $request->query('sort'), self::SORTS, true) ? (string) $request->query('sort') : 'name';
        $dir = $request->query('dir') === 'desc' ? 'desc' : 'asc';
        $type = match ($filter) {
            'kuliah' => RoomType::Classroom,
            'lab' => RoomType::Lab,
            default => null,
        };
        $now = now();

        $rooms = $this->filteredQuery($keyword, $filter, $buildingFilter, $type, $sort, $dir, $now)->get();

        return response()->streamDownload(function () use ($rooms): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Kode', 'Nama Ruang', 'Gedung', 'Lantai', 'Tipe', 'Kapasitas', 'Fasilitas', 'Status']);

            foreach ($rooms as $room) {
                $status = $room->closures->isNotEmpty() ? 'Ditutup' : 'Tersedia';
                fputcsv($handle, [
                    $room->code,
                    $room->name,
                    $room->building?->name ?? 'Belum didata',
                    $room->floor ?? '',
                    $room->type->label(),
                    $room->capacity ?? '',
                    $room->facilities->pluck('name')->implode(', '),
                    $status,
                ]);
            }

            fclose($handle);
        }, 'data-ruang-'.now()->toDateString().'.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * @return Builder<Room>
     */
    private function filteredQuery(
        string $keyword,
        string $filter,
        string $buildingFilter,
        ?RoomType $type,
        string $sort,
        string $dir,
        CarbonInterface $now,
    ): Builder {
        return Room::query()
            ->with(['building', 'facilities', 'closures' => fn ($q) => $q->where('start_at', '<=', $now)->where('end_at', '>', $now)])
            ->when($keyword !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('name', 'like', "%{$keyword}%")
                ->orWhere('code', 'like', "%{$keyword}%")
                ->orWhereHas('building', fn (Builder $query) => $query->where('name', 'like', "%{$keyword}%"))
                ->orWhereHas('facilities', fn (Builder $query) => $query->where('name', 'like', "%{$keyword}%"))
            ))
            ->when($filter === 'verifikasi', fn (Builder $query) => $query->where(fn (Builder $q) => $q->whereNull('capacity')->orWhereNull('building_id')))
            ->when($filter === 'ditutup', fn (Builder $query) => $query->whereHas('closures', fn ($q) => $q->where('start_at', '<=', $now)->where('end_at', '>', $now)))
            ->when($type !== null, fn (Builder $query) => $query->where('type', $type))
            ->when($buildingFilter !== '', function (Builder $query) use ($buildingFilter) {
                if ($buildingFilter === 'belum_didata') {
                    $query->whereNull('building_id');
                } else {
                    $query->whereHas('building', fn (Builder $q) => $q->where('name', $buildingFilter)->orWhere('code', $buildingFilter));
                }
            })
            ->when($sort === 'type', fn (Builder $query) => $query->orderBy('type', $dir)->orderBy('name'))
            ->when($sort === 'capacity', fn (Builder $query) => $query->orderBy('capacity', $dir)->orderBy('name'))
            ->when($sort === 'name', fn (Builder $query) => $query->orderBy('name', $dir));
    }
}
