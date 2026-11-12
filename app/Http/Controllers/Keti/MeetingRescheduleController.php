<?php

namespace App\Http\Controllers\Keti;

use App\Enums\TeachingRole;
use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Keti\MoveMeetingRequest;
use App\Models\Reservation\Reservation;
use App\Models\Room\Room;
use App\Models\Scheduling\ScheduleSession;
use App\Services\Academic\MeetingLocator;
use App\Services\Academic\MeetingRescheduleService;
use App\Services\Room\AvailabilityService;
use App\Services\Room\SearchCriteria;
use App\Support\RoomCardPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Halaman "Pindahkan pertemuan": Keti memilih dosen yang masuk, tanggal & jam baru, lalu ruang kosong.
 */
class MeetingRescheduleController extends Controller
{
    public function __construct(private MeetingRescheduleService $reschedules, private MeetingLocator $meetings) {}

    public function create(Request $request, AvailabilityService $availability): View|RedirectResponse
    {
        $filters = $request->validate([
            'sesi' => ['required', 'integer'],
            'tanggal' => ['required', 'date_format:Y-m-d'],
            'pengganti' => ['nullable', 'integer'],
            'ke_tanggal' => ['nullable', 'date_format:Y-m-d'],
            'jam' => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'dosen' => ['nullable', 'array'],
            'dosen.*' => ['integer'],
        ]);

        $user = $request->user();
        $date = CarbonImmutable::parse($filters['tanggal']);
        $session = ScheduleSession::query()->with(['participants', 'lecturers', 'room.building', 'version.period'])->findOrFail($filters['sesi']);
        $meeting = $this->meetings->find($session, $date);

        if ($meeting === null) {
            // Jika jadwal resmi telah diperbarui (schedule_version baru), cari sesi padanannya di versi resmi saat ini
            $meeting = $this->meetings->meetingsOn($date)->first(function (Meeting $m) use ($session, $filters): bool {
                if (isset($filters['pengganti']) && $m->reschedule?->reservation_id === (int) $filters['pengganti']) {
                    return true;
                }
                if ($session->course_offering_id !== null && $m->session->course_offering_id === $session->course_offering_id) {
                    return true;
                }

                return $m->session->title === $session->title
                    && $m->session->day_of_week === $session->day_of_week
                    && $m->session->start_minute === $session->start_minute
                    && $m->session->participants->pluck('id')->sort()->values()->all() === $session->participants->pluck('id')->sort()->values()->all();
            });

            if ($meeting !== null) {
                $session = $meeting->session;
            }
        }

        if ($meeting === null) {
            abort(404);
        }

        abort_unless($session->participants->contains('id', $user->activeClassGroup()?->id), 403);

        $replacing = null;
        if (isset($filters['pengganti'])) {
            abort_unless($meeting->reschedule?->reservation_id === (int) $filters['pengganti'], 404);
            $replacing = $meeting->reschedule;
        } elseif ($meeting->isMoved()) {
            return redirect()->route('keti.reservations.show', $meeting->reschedule->reservation_id);
        }

        $rotation = $this->meetings->rotationLecturerIds($session, $meeting->start);
        $selected = collect($filters['dosen'] ?? $this->meetings->defaultLecturerIds($session, $meeting->start)->all())->map(fn ($id): int => (int) $id);
        $duration = (int) $meeting->start->diffInMinutes($meeting->end);
        $start = isset($filters['ke_tanggal'], $filters['jam']) && filled($filters['jam'])
            ? CarbonImmutable::parse($filters['ke_tanggal'].' '.$filters['jam'])
            : null;

        $conflict = session('reschedule_error');
        $rooms = [];
        $recommendations = collect();

        if ($start !== null && $conflict === null) {
            try {
                $this->reschedules->check($user, $session, $meeting->start, $start, $selected->all(), $replacing);
                $rooms = $availability->search(new SearchCriteria(start: $start, durationMinutes: $duration, status: 'available', sort: SearchCriteria::SORT_NAME))
                    ->map(RoomCardPresenter::card(...))
                    ->sortBy(fn (array $room): int => $room['id'] === $session->room_id ? 0 : 1)
                    ->values()
                    ->all();
            } catch (DomainException $exception) {
                $conflict = ['code' => $exception->errorCode, 'message' => $exception->getMessage()];
            }
        }

        $targetDate = isset($filters['ke_tanggal']) && filled($filters['ke_tanggal'])
            ? CarbonImmutable::parse($filters['ke_tanggal'])
            : null;

        if ($targetDate !== null && ! ($start !== null && $conflict === null)) {
            $manualJam = isset($filters['jam']) && filled($filters['jam'])
                ? (int) substr($filters['jam'], 0, 2) * 60 + (int) substr($filters['jam'], 3, 2)
                : null;
            $recommendations = $this->reschedules->recommendations($user, $session, $meeting->start, $targetDate, $selected->all(), $replacing, $manualJam);
        }

        return view('keti.meetings.reschedule', [
            'meeting' => $meeting,
            'session' => $session,
            'replacing' => $replacing,
            'team' => $session->lecturers->map(fn ($lecturer): array => [
                'id' => $lecturer->id,
                'name' => $lecturer->name,
                'role' => TeachingRole::from($lecturer->pivot->role)->label(),
                'offTurn' => $rotation !== null && ! $rotation->contains($lecturer->id),
            ])->all(),
            'selectedLecturerIds' => $selected->all(),
            'duration' => $duration,
            'start' => $start,
            'targetDate' => $targetDate,
            'filters' => $filters,
            'conflict' => $conflict,
            'rooms' => $rooms,
            'recommendations' => $recommendations,
            'idempotencyKey' => (string) Str::uuid(),
        ]);
    }

    public function store(MoveMeetingRequest $request): RedirectResponse|JsonResponse
    {
        $data = $request->validated();
        $start = CarbonImmutable::parse($data['start_at']);
        $room = Room::query()->findOrFail($data['room_id']);
        $lecturerIds = array_map('intval', $data['lecturer_ids'] ?? []);

        try {
            $reschedule = isset($data['replaces_reservation_id'])
                ? $this->reschedules->moveAgain($request->user(), Reservation::query()->findOrFail($data['replaces_reservation_id']), $start, $room, $lecturerIds, $data['idempotency_key'])
                : $this->reschedules->move($request->user(), ScheduleSession::query()->findOrFail($data['session_id']), CarbonImmutable::parse($data['original_date']), $start, $room, $lecturerIds, $data['idempotency_key']);
        } catch (DomainException $exception) {
            if ($request->expectsJson()) {
                return response()->json($exception->toArray(), 409);
            }

            return redirect()->route('keti.meetings.reschedule', array_filter([
                'sesi' => $data['session_id'],
                'tanggal' => $data['original_date'],
                'pengganti' => $data['replaces_reservation_id'] ?? null,
                'ke_tanggal' => $start->toDateString(),
                'jam' => $start->format('H:i'),
                'dosen' => $lecturerIds,
            ]))->with('reschedule_error', ['code' => $exception->errorCode, 'message' => $exception->getMessage()]);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'reservation_id' => $reschedule->reservation_id,
                'redirect_url' => route('keti.reservations.show', $reschedule->reservation_id),
            ], 201);
        }

        return redirect()->route('keti.reservations.show', $reschedule->reservation_id)
            ->with('toast', ['type' => 'success', 'title' => 'Pertemuan berhasil dipindahkan', 'message' => 'Ruang asli sudah dilepas untuk kelas lain.']);
    }
}
