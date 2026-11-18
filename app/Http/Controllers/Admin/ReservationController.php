<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminCancelReservationRequest;
use App\Models\Reservation\Reservation;
use App\Services\Room\ReservationService;
use App\Support\CsvStream;
use App\Support\Wita;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Riwayat Reservasi. Reservasi tidak perlu persetujuan; admin hanya dapat membatalkan dengan alasan.
 */
class ReservationController extends Controller
{
    /**
     * @var array<string, string>
     */
    public const FILTERS = ['semua' => 'Semua', 'akan-datang' => 'Akan datang', 'selesai' => 'Selesai', 'dibatalkan' => 'Dibatalkan'];

    /**
     * @var list<string>
     */
    private const SORTS = ['start_at', 'status'];

    public function index(Request $request): View
    {
        $filter = array_key_exists((string) $request->query('status'), self::FILTERS) ? (string) $request->query('status') : 'semua';
        $keyword = trim((string) $request->query('q'));
        $perPage = in_array((int) $request->query('per_page'), [15, 30, 50, 100], true) ? (int) $request->query('per_page') : 15;
        $sort = in_array((string) $request->query('sort'), self::SORTS, true) ? (string) $request->query('sort') : 'start_at';
        $dir = $request->query('dir') === 'asc' ? 'asc' : 'desc';

        $reservations = $this->filteredQuery($filter, $keyword, $sort, $dir)
            ->paginate($perPage)
            ->withQueryString();

        return view('admin.reservations.index', compact('reservations', 'filter', 'keyword', 'perPage', 'sort', 'dir'));
    }

    public function export(Request $request): StreamedResponse
    {
        $filter = array_key_exists((string) $request->query('status'), self::FILTERS) ? (string) $request->query('status') : 'semua';
        $keyword = trim((string) $request->query('q'));
        $sort = in_array((string) $request->query('sort'), self::SORTS, true) ? (string) $request->query('sort') : 'start_at';
        $dir = $request->query('dir') === 'asc' ? 'asc' : 'desc';

        $reservations = $this->filteredQuery($filter, $keyword, $sort, $dir)->get();

        $rows = $reservations->map(function (Reservation $reservation): array {
            $statusLabel = $reservation->status === ReservationStatus::Booked
                ? 'Akan datang'
                : $reservation->status->label();

            return [
                $reservation->room->name,
                $reservation->classGroup->name,
                $reservation->creator->name,
                Wita::date($reservation->start_at),
                Wita::clock($reservation->start_at, false),
                Wita::clock($reservation->end_at, false),
                $reservation->purpose,
                $statusLabel,
                $reservation->cancel_reason ?? '',
            ];
        });

        return CsvStream::download(
            'riwayat-reservasi-'.now()->toDateString().'.csv',
            [
                'Ruang',
                'Kelas',
                'Pemesan',
                'Tanggal',
                'Jam Mulai (WITA)',
                'Jam Selesai (WITA)',
                'Keperluan',
                'Status',
                'Alasan Batal',
            ],
            $rows,
        );
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

    /**
     * @return Builder<Reservation>
     */
    private function filteredQuery(string $filter, string $keyword, string $sort, string $dir): Builder
    {
        return Reservation::query()
            ->with(['room', 'classGroup', 'creator', 'canceller', 'sessionReschedule.lecturers'])
            ->when($keyword !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereHas('room', fn (Builder $query) => $query->where('name', 'like', "%{$keyword}%"))
                ->orWhereHas('classGroup', fn (Builder $query) => $query->where('name', 'like', "%{$keyword}%"))))
            ->when($filter === 'akan-datang', fn (Builder $query) => $query->whereIn('status', [ReservationStatus::Booked, ReservationStatus::InUse]))
            ->when($filter === 'selesai', fn (Builder $query) => $query->where('status', ReservationStatus::Finished))
            ->when($filter === 'dibatalkan', fn (Builder $query) => $query->where('status', ReservationStatus::Cancelled))
            ->when($sort === 'status', fn (Builder $query) => $query->orderBy('status', $dir)->orderByDesc('start_at'))
            ->when($sort === 'start_at', fn (Builder $query) => $query->orderBy('start_at', $dir));
    }
}
