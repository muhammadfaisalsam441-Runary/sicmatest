<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminCancelReservationRequest;
use App\Models\Reservation\Reservation;
use App\Services\Room\ReservationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Riwayat Reservasi. Reservasi tidak perlu persetujuan; admin hanya dapat membatalkan dengan alasan.
 */
class ReservationController extends Controller
{
    /**
     * @var array<string, string>
     */
    public const FILTERS = ['semua' => 'Semua', 'akan-datang' => 'Akan datang', 'selesai' => 'Selesai', 'dibatalkan' => 'Dibatalkan'];

    public function index(Request $request): View
    {
        $filter = array_key_exists((string) $request->query('status'), self::FILTERS) ? (string) $request->query('status') : 'semua';
        $keyword = trim((string) $request->query('q'));

        $reservations = Reservation::query()
            ->with(['room', 'classGroup', 'creator', 'canceller'])
            ->when($keyword !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereHas('room', fn (Builder $query) => $query->where('name', 'like', "%{$keyword}%"))
                ->orWhereHas('classGroup', fn (Builder $query) => $query->where('name', 'like', "%{$keyword}%"))))
            ->when($filter === 'akan-datang', fn (Builder $query) => $query->whereIn('status', [ReservationStatus::Booked, ReservationStatus::InUse]))
            ->when($filter === 'selesai', fn (Builder $query) => $query->where('status', ReservationStatus::Finished))
            ->when($filter === 'dibatalkan', fn (Builder $query) => $query->where('status', ReservationStatus::Cancelled))
            ->orderByDesc('start_at')
            ->paginate(15)
            ->withQueryString();

        return view('admin.reservations.index', compact('reservations', 'filter', 'keyword'));
    }

    public function cancel(AdminCancelReservationRequest $request, Reservation $reservation, ReservationService $service): RedirectResponse
    {
        $service->cancel($reservation, $request->user(), $request->validated('reason'));

        return back()->with('toast', [
            'type' => 'success',
            'title' => "Reservasi {$reservation->room->name} dibatalkan",
            'message' => 'Slot terbuka lagi untuk kelas lain. Ketua tingkat sudah diberi tahu.',
        ]);
    }
}
