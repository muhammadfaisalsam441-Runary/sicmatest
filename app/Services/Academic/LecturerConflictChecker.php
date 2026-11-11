<?php

namespace App\Services\Academic;

use App\Enums\ReservationStatus;
use App\Enums\TeachingRole;
use App\Exceptions\DomainException;
use App\Models\Academic\ClassGroup;
use App\Models\Academic\SessionReschedule;
use App\Models\Lecturer\LeadershipTerm;
use App\Models\Lecturer\Lecturer;
use App\Support\Wita;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Dosen yang masuk di pertemuan pindahan harus kosong: tidak sedang bergiliran mengajar pertemuan resmi lain,
 * tidak dipilih di pemindahan aktif lain, dan bukan pimpinan bila hari Kamis (B11).
 */
class LecturerConflictChecker
{
    public function __construct(private MeetingLocator $meetings) {}

    /**
     * @param  Collection<int, Lecturer>  $lecturers
     *
     * @throws DomainException LECTURER_CONFLICT | LEADERSHIP_THURSDAY
     */
    public function assertFree(Collection $lecturers, CarbonImmutable $start, CarbonImmutable $end, ?Meeting $excludeMeeting = null, ?int $ignoreRescheduleId = null): void
    {
        $ids = $lecturers->pluck('id')->all();

        $official = $this->meetings->meetingsOn($start)
            ->filter(fn (Meeting $meeting): bool => $meeting->start->lessThan($end)
                && $meeting->end->greaterThan($start)
                && ! $meeting->isMoved()
                && ! ($excludeMeeting !== null && $meeting->is($excludeMeeting)));

        foreach ($official as $meeting) {
            $busy = $this->meetings->dutyLecturerIds($meeting->session, $start)->intersect($ids)->first();

            if ($busy !== null) {
                $lecturer = $meeting->session->lecturers->firstWhere('id', $busy);

                $this->conflict($lecturer, $lecturer->pivot->role, $meeting->session->title, $meeting->session->participants->pluck('name'), $meeting->start, $meeting->end);
            }
        }

        $moved = SessionReschedule::query()->active()
            ->when($ignoreRescheduleId !== null, fn ($query) => $query->whereKeyNot($ignoreRescheduleId))
            ->whereHas('reservation', fn ($query) => $query
                ->whereIn('status', [ReservationStatus::Booked, ReservationStatus::InUse])
                ->where('start_at', '<', $end)
                ->where('end_at', '>', $start))
            ->whereHas('lecturers', fn ($query) => $query->whereKey($ids))
            ->with(['lecturers', 'reservation'])
            ->first();

        if ($moved !== null) {
            $lecturer = $moved->lecturers->first(fn (Lecturer $lecturer): bool => in_array($lecturer->id, $ids, true));
            $classes = ClassGroup::query()->whereKey(array_filter(explode(',', $moved->participant_key)))->orderBy('name')->pluck('name');

            $this->conflict($lecturer, $lecturer->pivot->role, $moved->session_title, $classes, $moved->reservation->start_at, $moved->reservation->end_at);
        }

        if ($start->dayOfWeekIso === 4) {
            $leader = $lecturers->first(fn (Lecturer $lecturer): bool => $lecturer->leadershipTerms
                ->contains(fn (LeadershipTerm $term): bool => $term->isActiveOn($start)));

            if ($leader !== null) {
                throw new DomainException('LEADERSHIP_THURSDAY', "{$leader->name} sedang menjabat pimpinan dan tidak mengajar hari Kamis.", ['lecturer_id' => $leader->id]);
            }
        }
    }

    /**
     * @param  Collection<int, string>  $classes
     */
    private function conflict(Lecturer $lecturer, string $role, string $title, Collection $classes, \DateTimeInterface $start, \DateTimeInterface $end): never
    {
        $start = CarbonImmutable::instance($start);
        $end = CarbonImmutable::instance($end);
        $roleLabel = Str::lower(TeachingRole::from($role)->label());
        $label = $classes->isEmpty() ? $title : $title.' – '.$classes->implode(' + ');

        throw new DomainException(
            'LECTURER_CONFLICT',
            "{$lecturer->name} ({$roleLabel}) sedang mengajar {$label} pada ".Wita::dayName($start).' '.Wita::range($start, $end).'. Pilih jam lain.',
            ['lecturer_id' => $lecturer->id],
        );
    }
}
