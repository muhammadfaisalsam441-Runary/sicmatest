<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ClosureRequest;
use App\Models\Room\Room;
use App\Models\Room\RoomClosure;
use App\Services\Room\RoomClosureService;
use App\Support\Wita;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Penutupan ruang dengan pratinjau dampak: reservasi aktif yang beririsan harus dikonfirmasi admin dulu.
 */
class ClosureController extends Controller
{
    public function store(ClosureRequest $request, RoomClosureService $closures): RedirectResponse
    {
        $room = Room::query()->findOrFail($request->integer('room_id'));
        $start = CarbonImmutable::parse($request->validated('start_at'));
        $end = CarbonImmutable::parse($request->validated('end_at'));

        $impact = $closures->impact($room, $start, $end);

        if ($impact->isNotEmpty() && ! $request->boolean('confirm_impact')) {
            return back()
                ->withInput()
                ->with('closure_impact', $impact->map(fn ($reservation): array => [
                    'class' => $reservation->classGroup->name,
                    'time' => Wita::date($reservation->start_at).' '.Wita::range($reservation->start_at, $reservation->end_at),
                    'purpose' => $reservation->purpose,
                ])->all());
        }

        $closures->create($room, $start, $end, $request->validated('reason'), $request->user());

        return to_route('admin.rooms.index')->with('toast', [
            'type' => 'success',
            'title' => "Ruang {$room->name} ditutup",
            'message' => $impact->isEmpty() ? 'Tidak ada reservasi yang terdampak.' : $impact->count().' kelas terdampak sudah diberi tahu. Reservasinya tidak dibatalkan otomatis.',
        ]);
    }

    public function destroy(Request $request, RoomClosure $closure, RoomClosureService $closures): RedirectResponse
    {
        $closures->remove($closure, $request->user());

        return to_route('admin.rooms.index')->with('toast', ['type' => 'success', 'title' => 'Penutupan ruang dihapus']);
    }
}
