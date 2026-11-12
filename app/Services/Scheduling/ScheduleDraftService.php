<?php

namespace App\Services\Scheduling;

use App\Enums\RoomBlockSource;
use App\Enums\ScheduleSource;
use App\Enums\ScheduleVersionStatus;
use App\Enums\SessionMode;
use App\Enums\SessionPhase;
use App\Exceptions\DomainException;
use App\Models\Academic\CourseOffering;
use App\Models\Academic\Period;
use App\Models\Lecturer\TeachingAssignment;
use App\Models\Room\Room;
use App\Models\Room\RoomBlock;
use App\Models\Scheduling\ScheduleSession;
use App\Models\Scheduling\ScheduleVersion;
use App\Services\Account\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Draf jadwal: buat revisi dari versi resmi, ubah penempatan sesi, kunci sesi, cari ruang alternatif.
 * Draf tidak pernah memengaruhi status ruang sampai ditetapkan (B09).
 */
class ScheduleDraftService
{
    public function __construct(
        private ScheduleValidator $validator,
        private AuditLogger $audit,
    ) {}

    /**
     * Versi baru berstatus draf, menyalin seluruh sesi (beserta peserta & pengajar) dari $base bila ada.
     */
    public function create(Period $period, ScheduleSource $source, ?ScheduleVersion $base = null): ScheduleVersion
    {
        return DB::transaction(function () use ($period, $source, $base): ScheduleVersion {
            $versionNo = (int) $period->scheduleVersions()->lockForUpdate()->max('version_no') + 1;

            $effectiveFrom = now()->startOfDay()->max($period->start_date);

            if ($period->end_date !== null && $effectiveFrom->greaterThan($period->end_date)) {
                $effectiveFrom = $period->start_date ?? $period->end_date;
            }

            $draft = $period->scheduleVersions()->create([
                'version_no' => $versionNo,
                'status' => ScheduleVersionStatus::Draft,
                'source' => $source,
                'based_on_version_id' => $base?->id,
                'effective_from' => $effectiveFrom->toDateString(),
                'lock_version' => 0,
            ]);

            if ($base !== null) {
                $this->copySessions($base, $draft);
            }

            $this->audit->log('schedule.draft_created', $draft, null, ['version_no' => $versionNo, 'based_on' => $base?->version_no, 'source' => $source->value]);

            return $draft;
        });
    }

    /**
     * Salin seluruh sesi (beserta peserta & pengajar) dari satu versi ke versi lain.
     *
     * @return array<int, int> Peta id sesi asal → id sesi salinan.
     */
    public function copySessions(ScheduleVersion $from, ScheduleVersion $to): array
    {
        $map = [];

        DB::transaction(function () use ($from, $to, &$map): void {
            foreach ($from->sessions()->with(['participants', 'lecturers'])->get() as $session) {
                $copy = $session->replicate(['schedule_version_id']);
                $copy->schedule_version_id = $to->id;
                $copy->save();
                $copy->participants()->sync($session->participants->pluck('id'));
                $copy->lecturers()->sync($session->lecturers->mapWithKeys(fn ($lecturer): array => [$lecturer->id => ['role' => $lecturer->pivot->role]]));
                $map[$session->id] = $copy->id;
            }
        });

        return $map;
    }

    /**
     * Ubah hari/jam/ruang/mode sesi draf. Sesi terkunci tidak bisa diubah; draf diperiksa ulang setelahnya.
     *
     * @param  array{mode: string, day_of_week?: int|null, start_minute?: int|null, end_minute?: int|null, room_id?: int|null}  $data
     */
    public function updateSession(ScheduleSession $session, array $data, ?string $note = null): ScheduleSession
    {
        $session->loadMissing('version');
        $this->assertDraft($session->version);

        if ($session->is_locked) {
            throw new DomainException('SESSION_LOCKED', 'Sesi ini dikunci. Buka kunci dulu sebelum mengubah penempatan.');
        }

        $mode = SessionMode::from($data['mode']);
        $isUnscheduled = $mode === SessionMode::Unscheduled;

        if (! $isUnscheduled && isset($data['day_of_week']) && ! in_array((int) $data['day_of_week'], config('sicma.scheduler.days'), true)) {
            throw new DomainException('INVALID_TEACHING_DAY', 'Perkuliahan hanya boleh dijadwalkan Senin–Jumat.');
        }

        $fridayBreak = config('sicma.scheduler.friday_break', ['start' => 720, 'end' => 790]);
        if (! $isUnscheduled && (int) ($data['day_of_week'] ?? 0) === 5
            && isset($data['start_minute'], $data['end_minute'])
            && (int) $data['start_minute'] < $fridayBreak['end'] && (int) $data['end_minute'] > $fridayBreak['start']) {
            throw new DomainException('FRIDAY_BREAK_CONFLICT', 'Pada hari Jumat pukul 12.00–13.10 tidak ada perkuliahan.');
        }

        DB::transaction(function () use ($session, $data, $mode, $isUnscheduled, $note): void {
            $before = $session->only(['mode', 'day_of_week', 'start_minute', 'end_minute', 'room_id']);

            $session->forceFill([
                'mode' => $mode,
                'day_of_week' => $isUnscheduled ? null : ($data['day_of_week'] ?? null),
                'start_minute' => $isUnscheduled ? null : ($data['start_minute'] ?? null),
                'end_minute' => $isUnscheduled ? null : ($data['end_minute'] ?? null),
                'room_id' => $mode === SessionMode::Offline ? ($data['room_id'] ?? null) : null,
            ])->save();

            $session->version->increment('lock_version');

            $this->audit->log('schedule.session_updated', $session, $before, $session->only(['mode', 'day_of_week', 'start_minute', 'end_minute', 'room_id']), $note);
        });

        $this->validator->validate($session->version->fresh());

        return $session;
    }

    public function toggleLock(ScheduleSession $session): ScheduleSession
    {
        $session->loadMissing('version');
        $this->assertDraft($session->version);

        $session->forceFill(['is_locked' => ! $session->is_locked])->save();
        $session->version->increment('lock_version');

        return $session;
    }

    /**
     * Ruang luring yang kosong pada hari & jam sesi di draf yang sama dan cukup untuk pesertanya.
     *
     * @return Collection<int, Room>
     */
    public function alternativeRooms(ScheduleSession $session): Collection
    {
        if ($session->day_of_week === null || $session->start_minute === null || $session->end_minute === null) {
            return collect();
        }

        $session->loadMissing(['participants', 'version.period.calendarExceptions']);

        $occupied = ScheduleSession::query()
            ->where('schedule_version_id', $session->schedule_version_id)
            ->whereKeyNot($session->id)
            ->where('mode', SessionMode::Offline)
            ->where('day_of_week', $session->day_of_week)
            ->when($session->phase !== SessionPhase::Full, fn ($query) => $query->whereIn('phase', [SessionPhase::Full, $session->phase]))
            ->where('start_minute', '<', $session->end_minute)
            ->where('end_minute', '>', $session->start_minute)
            ->whereNotNull('room_id')
            ->pluck('room_id')
            ->merge($this->externallyOccupiedRoomIds($session))
            ->unique();

        $students = $session->participants->pluck('student_count');
        $needed = $students->contains(null) ? null : $students->sum();

        return Room::query()
            ->with('building')
            ->where('is_active', true)
            ->whereNotIn('id', $occupied)
            ->when($needed !== null, fn ($query) => $query->where(fn ($query) => $query->whereNull('capacity')->orWhere('capacity', '>=', $needed)))
            ->get()
            ->sortBy('name', SORT_NATURAL)
            ->values();
    }

    /**
     * @return Collection<int, int>
     */
    private function externallyOccupiedRoomIds(ScheduleSession $session): Collection
    {
        $version = $session->version;
        $period = $version?->period;

        if ($version === null || $period === null || $period->start_date === null || $period->end_date === null) {
            return collect();
        }

        $from = CarbonImmutable::parse($version->effective_from->toDateString())->max(CarbonImmutable::parse($period->start_date->toDateString()));
        $to = CarbonImmutable::parse(($version->effective_to ?? $period->end_date)->toDateString())->min(CarbonImmutable::parse($period->end_date->toDateString()));

        if ($from->greaterThan($to)) {
            return collect();
        }

        $holidays = $period->calendarExceptions->map(fn ($exception): string => $exception->date->toDateString())->all();
        $blocks = RoomBlock::query()
            ->active()
            ->whereIn('source_type', [RoomBlockSource::Reservation, RoomBlockSource::Closure])
            ->overlapping($from->startOfDay(), $to->endOfDay())
            ->get();

        $occupied = collect();

        foreach ($blocks as $block) {
            for ($date = CarbonImmutable::instance($block->start_at)->startOfDay()->max($from); $date->lessThanOrEqualTo($to) && $date->lessThan($block->end_at); $date = $date->addDay()) {
                if ($date->dayOfWeekIso !== $session->day_of_week || in_array($date->toDateString(), $holidays, true)) {
                    continue;
                }

                if ($session->phase === SessionPhase::BeforeUts && $period->uts_start !== null && $date->greaterThanOrEqualTo($period->uts_start)) {
                    continue;
                }

                if ($session->phase === SessionPhase::AfterUts && $period->uts_end !== null && $date->lessThanOrEqualTo($period->uts_end)) {
                    continue;
                }

                $blockStart = $block->start_at->lessThan($date) ? 0 : $block->start_at->hour * 60 + $block->start_at->minute;
                $blockEnd = $block->end_at->greaterThanOrEqualTo($date->addDay()) ? 1440 : $block->end_at->hour * 60 + $block->end_at->minute;

                if ($blockStart < $session->end_minute && $blockEnd > $session->start_minute) {
                    $occupied->push($block->room_id);
                    break;
                }
            }
        }

        return $occupied;
    }

    /**
     * Sinkronkan dosen dan fase pada sesi draf yang terhubung ke penawaran mata kuliah tertentu.
     *
     * @param  iterable<int|string>  $offeringIds
     */
    public function syncOfferings(Period $period, iterable $offeringIds): void
    {
        $ids = collect($offeringIds)->filter()->unique()->values()->all();
        if (empty($ids)) {
            return;
        }

        $drafts = $period->scheduleVersions()
            ->whereIn('status', [ScheduleVersionStatus::Draft, ScheduleVersionStatus::Ready])
            ->get();

        if ($drafts->isEmpty()) {
            return;
        }

        $offerings = CourseOffering::query()
            ->whereIn('id', $ids)
            ->with('teachingAssignments')
            ->get()
            ->keyBy('id');

        if ($offerings->isEmpty()) {
            return;
        }

        foreach ($drafts as $draft) {
            $sessions = $draft->sessions()
                ->whereIn('course_offering_id', $offerings->modelKeys())
                ->get();

            if ($sessions->isEmpty()) {
                continue;
            }

            $sessionsByOffering = $sessions->groupBy('course_offering_id');

            foreach ($sessionsByOffering as $offeringId => $offeringSessions) {
                $offering = $offerings->get($offeringId);
                if (! $offering) {
                    continue;
                }

                $assignments = $offering->teachingAssignments;

                foreach ($offeringSessions as $session) {
                    if ($assignments->isNotEmpty()) {
                        $uniquePhases = $assignments->pluck('phase')->unique();
                        if ($uniquePhases->count() === 1) {
                            $session->phase = $uniquePhases->first();
                        } elseif ($offeringSessions->count() === 1) {
                            $session->phase = SessionPhase::Full;
                        }
                        $session->save();
                    }

                    $team = $assignments->filter(fn (TeachingAssignment $assignment): bool => $session->phase === SessionPhase::Full
                        || $assignment->phase === SessionPhase::Full
                        || $session->phase === $assignment->phase
                    );

                    $session->lecturers()->sync($team->mapWithKeys(fn (TeachingAssignment $assignment): array => [
                        $assignment->lecturer_id => ['role' => $assignment->role->value],
                    ])->all());
                }
            }

            $this->validator->validate($draft);
        }
    }

    private function assertDraft(ScheduleVersion $version): void
    {
        if (! in_array($version->status, [ScheduleVersionStatus::Draft, ScheduleVersionStatus::Ready], true)) {
            throw new DomainException('INVALID_STATUS_TRANSITION', 'Jadwal resmi tidak diubah langsung. Buat draf revisi lebih dulu.');
        }
    }
}
