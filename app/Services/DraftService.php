<?php

namespace App\Services;

use App\Enums\ScheduleSource;
use App\Enums\ScheduleVersionStatus;
use App\Enums\SessionMode;
use App\Exceptions\DomainException;
use App\Models\Period;
use App\Models\Room;
use App\Models\ScheduleSession;
use App\Models\ScheduleVersion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Draf jadwal: buat revisi dari versi resmi, ubah penempatan sesi, kunci sesi, cari ruang alternatif.
 * Draf tidak pernah memengaruhi status ruang sampai ditetapkan (B09).
 */
class DraftService
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

            $draft = $period->scheduleVersions()->create([
                'version_no' => $versionNo,
                'status' => ScheduleVersionStatus::Draft,
                'source' => $source,
                'based_on_version_id' => $base?->id,
                'effective_from' => now()->max($period->start_date)->toDateString(),
                'lock_version' => 0,
            ]);

            if ($base !== null) {
                $base->loadMissing(['sessions.participants', 'sessions.lecturers']);

                foreach ($base->sessions as $session) {
                    $copy = $session->replicate(['schedule_version_id']);
                    $copy->schedule_version_id = $draft->id;
                    $copy->save();
                    $copy->participants()->sync($session->participants->pluck('id'));
                    $copy->lecturers()->sync($session->lecturers->mapWithKeys(fn ($lecturer): array => [$lecturer->id => ['role' => $lecturer->pivot->role]]));
                }
            }

            $this->audit->log('schedule.draft_created', $draft, null, ['version_no' => $versionNo, 'based_on' => $base?->version_no, 'source' => $source->value]);

            return $draft;
        });
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

        $session->loadMissing('participants');

        $occupied = ScheduleSession::query()
            ->where('schedule_version_id', $session->schedule_version_id)
            ->whereKeyNot($session->id)
            ->where('mode', SessionMode::Offline)
            ->where('day_of_week', $session->day_of_week)
            ->where('start_minute', '<', $session->end_minute)
            ->where('end_minute', '>', $session->start_minute)
            ->whereNotNull('room_id')
            ->pluck('room_id');

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

    private function assertDraft(ScheduleVersion $version): void
    {
        if (! in_array($version->status, [ScheduleVersionStatus::Draft, ScheduleVersionStatus::Ready], true)) {
            throw new DomainException('INVALID_STATUS_TRANSITION', 'Jadwal resmi tidak diubah langsung. Buat draf revisi lebih dulu.');
        }
    }
}
