<?php

namespace App\Http\Controllers\Keti;

use App\Enums\TeachingRole;
use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Keti\MoveMeetingRequest;
use App\Http\Requests\Keti\StoreOnlineMeetingRequest;
use App\Models\Academic\SessionReschedule;
use App\Models\Reservation\Reservation;
use App\Models\Room\Room;
use App\Models\Scheduling\ScheduleSession;
use App\Services\Academic\Meeting;
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
            'pengganti_online' => ['nullable', 'integer'],
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
                if (isset($filters['pengganti_online']) && $m->reschedule?->id === (int) $filters['pengganti_online']) {
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
            $replacing = $meeting->reschedule->loadMissing('lecturers');
        } elseif (isset($filters['pengganti_online'])) {
            abort_unless($meeting->reschedule?->id === (int) $filters['pengganti_online'] && $meeting->reschedule->isOnline(), 404);
            $replacing = $meeting->reschedule->loadMissing('lecturers');
        } elseif ($meeting->isMoved()) {
            if ($meeting->reschedule->isOnline()) {
                return redirect()->route('keti.my-class', ['tanggal' => $meeting->reschedule->effectiveStart()->toDateString()]);
            }

            return redirect()->route('keti.reservations.show', $meeting->reschedule->reservation_id);
        }

        $rotation = $this->meetings->rotationLecturerIds($session, $meeting->start);
        $defaultLecturers = $replacing && $replacing->lecturers->isNotEmpty()
            ? $replacing->lecturers->pluck('id')->all()
            : $this->meetings->defaultLecturerIds($session, $meeting->start)->all();
        $selected = collect($filters['dosen'] ?? $defaultLecturers)->map(fn ($id): int => (int) $id);
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
            $reschedule = match (true) {
                isset($data['replaces_reschedule_id']) => $this->reschedules->replaceWithRoom($request->user(), SessionReschedule::query()->findOrFail($data['replaces_reschedule_id']), $start, $room, $lecturerIds, $data['idempotency_key']),
                isset($data['replaces_reservation_id']) => $this->reschedules->moveAgain($request->user(), Reservation::query()->findOrFail($data['replaces_reservation_id']), $start, $room, $lecturerIds, $data['idempotency_key']),
                default => $this->reschedules->move($request->user(), ScheduleSession::query()->findOrFail($data['session_id']), CarbonImmutable::parse($data['original_date']), $start, $room, $lecturerIds, $data['idempotency_key']),
            };
        } catch (DomainException $exception) {
            if ($request->expectsJson()) {
                return response()->json($exception->toArray(), 409);
            }

            return redirect()->route('keti.meetings.reschedule', array_filter([
                'sesi' => $data['session_id'],
                'tanggal' => $data['original_date'],
                'pengganti' => $data['replaces_reservation_id'] ?? null,
                'pengganti_online' => ! isset($data['replaces_reservation_id']) ? ($data['replaces_reschedule_id'] ?? null) : null,
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

    public function storeOnline(StoreOnlineMeetingRequest $request): RedirectResponse|JsonResponse
    {
        $data = $request->validated();
        $lecturerIds = array_map('intval', $data['lecturer_ids'] ?? []);
        $note = $data['note'] ?? null;
        $startAt = isset($data['start_at']) && filled($data['start_at'])
            ? CarbonImmutable::parse($data['start_at'])
            : null;

        try {
            if (isset($data['replaces_reschedule_id'])) {
                $old = SessionReschedule::query()->findOrFail($data['replaces_reschedule_id']);
                $reschedule = $this->reschedules->replaceWithOnline(
                    $request->user(),
                    $old,
                    $startAt ?? CarbonImmutable::parse($old->original_start_at),
                    $lecturerIds,
                    $note,
                    $data['idempotency_key'],
                );
            } elseif (isset($data['replaces_reservation_id'])) {
                $old = Reservation::query()->findOrFail($data['replaces_reservation_id'])->sessionReschedule
                    ?? throw new DomainException('MEETING_NOT_FOUND', 'Reservasi ini bukan pertemuan kuliah yang dipindah.');
                $reschedule = $this->reschedules->replaceWithOnline(
                    $request->user(),
                    $old,
                    $startAt ?? CarbonImmutable::parse($old->original_start_at),
                    $lecturerIds,
                    $note,
                    $data['idempotency_key'],
                );
            } else {
                $reschedule = $this->reschedules->makeOnline(
                    $request->user(),
                    ScheduleSession::query()->findOrFail($data['session_id']),
                    CarbonImmutable::parse($data['original_date']),
                    $startAt,
                    $lecturerIds,
                    $note,
                    $data['idempotency_key'],
                );
            }
        } catch (DomainException $exception) {
            if ($request->expectsJson()) {
                return response()->json($exception->toArray(), 409);
            }

            if ($startAt !== null) {
                return redirect()->route('keti.meetings.reschedule', array_filter([
                    'sesi' => $data['session_id'],
                    'tanggal' => $data['original_date'],
                    'pengganti' => $data['replaces_reservation_id'] ?? null,
                    'pengganti_online' => ! isset($data['replaces_reservation_id']) ? ($data['replaces_reschedule_id'] ?? null) : null,
                    'ke_tanggal' => $startAt->toDateString(),
                    'jam' => $startAt->format('H:i'),
                    'dosen' => $lecturerIds,
                ]))->with('reschedule_error', ['code' => $exception->errorCode, 'message' => $exception->getMessage()]);
            }

            return redirect()->route('keti.my-class', ['tanggal' => $data['original_date']])
                ->with('online_error', $exception->getMessage());
        }

        $targetDate = $reschedule->effectiveStart()->toDateString();

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'reschedule_id' => $reschedule->id,
                'redirect_url' => route('keti.my-class', ['tanggal' => $targetDate]),
            ], 201);
        }

        return redirect()->route('keti.my-class', ['tanggal' => $targetDate])
            ->with('toast', ['type' => 'success', 'title' => 'Pertemuan dialihkan ke Online', 'message' => 'Ruang terjadwal sudah dikosongkan untuk kelas lain.']);
    }

    public function cancelOnline(Request $request, SessionReschedule $reschedule): RedirectResponse|JsonResponse
    {
        $classGroup = $request->user()->activeClassGroup();
        abort_unless($classGroup !== null && $reschedule->includesClassGroup($classGroup->id), 403);

        try {
            $this->reschedules->cancelOnline($reschedule, $request->user());
        } catch (DomainException $exception) {
            if ($request->expectsJson()) {
                return response()->json($exception->toArray(), 409);
            }

            return back()->with('online_error', $exception->getMessage());
        }

        $originalDate = CarbonImmutable::instance($reschedule->original_start_at)->toDateString();

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'reschedule_id' => $reschedule->id,
                'redirect_url' => route('keti.my-class', ['tanggal' => $originalDate]),
            ]);
        }

        return redirect()->route('keti.my-class', ['tanggal' => $originalDate])
            ->with('toast', ['type' => 'success', 'title' => 'Pertemuan dikembalikan ke ruang luring', 'message' => 'Jadwal ruang asli kembali aktif.']);
    }
}
