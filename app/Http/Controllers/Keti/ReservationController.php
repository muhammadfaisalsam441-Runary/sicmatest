<?php

namespace App\Http\Controllers\Keti;

use App\Enums\ReservationStatus;
use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Keti\ReportIssueRequest;
use App\Http\Requests\Keti\StoreReservationRequest;
use App\Models\Reservation\Reservation;
use App\Models\Room\Room;
use App\Services\Account\AuditLogger;
use App\Services\Account\NotificationService;
use App\Services\Room\AvailabilityService;
use App\Services\Room\ReservationService;
use App\Services\Room\SearchCriteria;
use App\Support\BookingPayload;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Throwable;

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
                ->with('reservation_error', ['code' => $exception->errorCode, 'message' => $exception->getMessage()])
                ->with('reservation_request', ['room_id' => (int) $data['room_id'], 'start_at' => $start->format('Y-m-d H:i'), 'duration' => (int) $data['duration']]);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'reservation_id' => $reservation->id,
                'redirect_url' => route('keti.reservations.show', $reservation),
            ], 201);
        }

        return redirect()->route('keti.reservations.show', $reservation);
    }

    public function failed(AvailabilityService $availability): View
    {
        $request = session('reservation_request');
        $error = session('reservation_error', ['code' => 'UNKNOWN', 'message' => 'Ketersediaan ruang belum dapat dikonfirmasi. Pilih ruangan berstatus Tersedia atau coba lagi.']);
        $alternatives = [];
        $start = null;
        $duration = null;

        // Saran ruang lain yang kosong sepanjang durasi yang sama (status dihitung ulang, bukan dari cache).
        // Tidak ditawarkan bila kelasnya sendiri sudah punya pesanan di jam itu: ruang mana pun akan ditolak.
        if (is_array($request)) {
            $start = CarbonImmutable::parse($request['start_at']);
            $duration = (int) $request['duration'];

            if ($error['code'] !== 'CLASS_TIME_CONFLICT' && $start->greaterThanOrEqualTo(CarbonImmutable::now()->subMinutes(5))) {
                try {
                    $alternatives = $availability->search(new SearchCriteria(start: $start, durationMinutes: $duration, status: 'available'))
                        ->reject(fn ($result): bool => $result->room->id === (int) $request['room_id'])
                        ->take(3)
                        ->map(fn ($result): array => [
                            'name' => $result->room->name,
                            'location' => $result->locationLabel(),
                            'until' => $result->freeUntil,
                            'booking' => BookingPayload::make($result->room->id, $result->room->name, $result->locationLabel(), $result->room->capacity, $start, $duration),
                        ])
                        ->values()
                        ->all();
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
        }

        return view('keti.reservations.failed', [
            'error' => $error,
            'alternatives' => $alternatives,
            'start' => $start,
            'duration' => $duration,
            'academicAllowed' => $start === null || ReservationService::allowsAcademicPurposes($start),
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
