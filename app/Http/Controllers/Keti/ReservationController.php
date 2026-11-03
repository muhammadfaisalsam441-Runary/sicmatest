<?php

namespace App\Http\Controllers\Keti;

use App\Enums\ReservationStatus;
use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Keti\ReportIssueRequest;
use App\Http\Requests\Keti\StoreReservationRequest;
use App\Models\Reservation;
use App\Models\Room;
use App\Services\AuditLogger;
use App\Services\NotificationService;
use App\Services\ReservationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ReservationController extends Controller
{
    public function __construct(private ReservationService $reservations) {}

    /**
     * Pesan ruang. Kelas diambil dari akun Keti di server, bukan dari request (B02).
     */
    public function store(StoreReservationRequest $request): RedirectResponse|JsonResponse
    {
        $data = $request->validated();
        $start = CarbonImmutable::parse($data['start_at']);

        try {
            $reservation = $this->reservations->create(
                $request->user(),
                Room::query()->findOrFail($data['room_id']),
                $start,
                $start->addMinutes((int) $data['duration']),
                $data['purpose'],
                isset($data['participants']) ? (int) $data['participants'] : null,
                $data['idempotency_key'],
            );
        } catch (DomainException $exception) {
            if ($request->expectsJson()) {
                return response()->json($exception->toArray(), 409);
            }

            return redirect()->route('keti.reservations.failed')
                ->with('reservation_error', ['code' => $exception->errorCode, 'message' => $exception->getMessage()]);
        }

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'reservation_id' => $reservation->id], 201);
        }

        return redirect()->route('keti.reservations.show', $reservation);
    }

    public function failed(): View
    {
        return view('keti.reservations.failed', [
            'error' => session('reservation_error', ['code' => 'UNKNOWN', 'message' => 'Ketersediaan ruang belum dapat dikonfirmasi. Pilih ruangan berstatus Tersedia atau coba lagi.']),
        ]);
    }

    public function show(Request $request, Reservation $reservation): View
    {
        Gate::authorize('view', $reservation);
        $reservation->load(['room.building', 'classGroup', 'creator']);

        return view('keti.reservations.show', [
            'reservation' => $reservation,
            'recent' => $reservation->status === ReservationStatus::Finished
                ? Reservation::query()->whereBelongsTo($reservation->classGroup)->where('status', ReservationStatus::Finished)->with('room')->latest('actual_end_at')->limit(5)->get()
                : collect(),
            'canManage' => $request->user()->can('manage', $reservation),
        ]);
    }

    /**
     * Status ringkas untuk polling 30 detik di halaman penggunaan aktif.
     */
    public function status(Reservation $reservation): JsonResponse
    {
        Gate::authorize('view', $reservation);

        return response()->json([
            'status' => $reservation->status->value,
            'end_at' => $reservation->end_at->toIso8601String(),
            'lock_version' => $reservation->lock_version,
        ]);
    }

    public function start(Request $request, Reservation $reservation): RedirectResponse
    {
        Gate::authorize('manage', $reservation);
        $this->reservations->start($reservation, $request->user());

        return redirect()->route('keti.reservations.show', $reservation)
            ->with('toast', ['type' => 'success', 'title' => 'Penggunaan dimulai', 'message' => 'Waktu pemakaianmu mulai tercatat.']);
    }

    public function finish(Request $request, Reservation $reservation): RedirectResponse
    {
        Gate::authorize('manage', $reservation);
        $this->reservations->finish($reservation, $request->user());

        return redirect()->route('keti.reservations.show', $reservation);
    }

    public function cancel(Request $request, Reservation $reservation): RedirectResponse
    {
        Gate::authorize('manage', $reservation);
        $this->reservations->cancel($reservation, $request->user(), $request->string('reason')->limit(250)->toString() ?: null);

        return redirect()->route('keti.reservations.show', $reservation);
    }

    public function extend(Request $request, Reservation $reservation): RedirectResponse
    {
        Gate::authorize('manage', $reservation);
        $this->reservations->extend($reservation, $request->user());

        return redirect()->route('keti.reservations.show', $reservation)
            ->with('toast', ['type' => 'success', 'title' => 'Perubahan tersimpan', 'message' => 'Batas penggunaan diperpanjang '.config('sicma.reservation.extend_minutes').' menit.']);
    }

    public function report(ReportIssueRequest $request, Reservation $reservation, NotificationService $notifications, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validated();
        $reservation->load(['room', 'classGroup']);

        $audit->log('facility.issue_reported', $reservation->room, null, ['issue' => $data['issue'], 'note' => $data['note'] ?? null, 'reservation_id' => $reservation->id]);
        $notifications->notify(
            $notifications->admins(),
            'facility.issue_reported',
            'Laporan kendala '.$reservation->room->name,
            $data['issue'].' • dilaporkan '.$reservation->classGroup->name.(filled($data['note'] ?? null) ? ' • '.$data['note'] : ''),
            ['room_id' => $reservation->room_id, 'reservation_id' => $reservation->id],
        );

        return back()->with('toast', ['type' => 'success', 'title' => 'Laporan terkirim', 'message' => 'Kendala dicatat untuk pengelola.']);
    }
}
