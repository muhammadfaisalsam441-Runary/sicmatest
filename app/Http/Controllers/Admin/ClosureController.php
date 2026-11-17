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
        $isRecurring = $request->validated('mode') === 'recurring';
        $cancelImpacted = $request->boolean('cancel_impacted');
        $reason = (string) $request->validated('reason');

        if ($isRecurring) {
            $startDate = CarbonImmutable::parse((string) $request->validated('start_date'));
            $endDate = CarbonImmutable::parse((string) $request->validated('end_date'));
            $startTime = (string) $request->validated('start_time');
            $endTime = (string) $request->validated('end_time');
            $daysOfWeek = array_map('intval', (array) $request->validated('days_of_week'));

            $windows = $closures->buildRecurringWindows($startDate, $endDate, $startTime, $endTime, $daysOfWeek);
            $impact = $closures->impactForWindows($room, $windows);
        } else {
            $start = CarbonImmutable::parse((string) $request->validated('start_at'));
            $end = CarbonImmutable::parse((string) $request->validated('end_at'));
            $impact = $closures->impact($room, $start, $end);
        }

        if ($impact->isNotEmpty() && ! $request->boolean('confirm_impact')) {
            return back()
                ->withInput()
                ->with('closure_impact', $impact->map(fn ($reservation): array => [
                    'class' => $reservation->classGroup->name,
                    'time' => Wita::date($reservation->start_at).' '.Wita::range($reservation->start_at, $reservation->end_at),
                    'purpose' => $reservation->purpose,
                ])->all());
        }

        if ($isRecurring) {
            $closures->createRecurring($room, $startDate, $endDate, $startTime, $endTime, $daysOfWeek, $reason, $request->user(), $cancelImpacted);
        } else {
            $closures->create($room, $start, $end, $reason, $request->user(), $cancelImpacted);
        }

        $impactNote = $impact->isEmpty()
            ? 'Tidak ada reservasi yang terdampak.'
            : ($cancelImpacted ? $impact->count().' reservasi terdampak telah dibatalkan otomatis.' : $impact->count().' kelas terdampak sudah diberi tahu. Reservasinya tidak dibatalkan otomatis.');

        return to_route('admin.rooms.index')->with('toast', [
            'type' => 'success',
            'title' => $isRecurring ? "Aturan penutupan {$room->name} dibuat" : "Ruang {$room->name} ditutup",
            'message' => $impactNote,
        ]);
    }

    public function destroy(Request $request, RoomClosure $closure, RoomClosureService $closures): RedirectResponse
    {
        $closures->remove($closure, $request->user());

        return to_route('admin.rooms.index')->with('toast', ['type' => 'success', 'title' => 'Penutupan ruang dihapus']);
    }
}
