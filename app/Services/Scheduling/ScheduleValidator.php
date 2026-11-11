<?php

namespace App\Services\Scheduling;

use App\Enums\IssueSeverity;
use App\Enums\LecturerProfileStatus;
use App\Enums\RoomBlockSource;
use App\Enums\SessionMode;
use App\Enums\SessionPhase;
use App\Models\Academic\Period;
use App\Models\Lecturer\Lecturer;
use App\Models\Lecturer\LecturerAvailability;
use App\Models\Room\RoomBlock;
use App\Models\Scheduling\ScheduleIssue;
use App\Models\Scheduling\ScheduleSession;
use App\Models\Scheduling\ScheduleVersion;
use App\Models\User\AuditLog;
use App\Services\Academic\TeachingLoadService;
use App\Services\Account\AuditLogger;
use App\Support\Wita;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Memeriksa draf jadwal dan menyimpan "masalah wajib" (error) serta peringatan ke schedule_issues.
 *
 * Masalah wajib: bentrok ruang/dosen/kelas, pimpinan hari Kamis (B11), sesi tanpa waktu atau ruang,
 * bentrok dengan reservasi aktif (B10), dan beban melebihi batas maksimum dosen.
 */
class ScheduleValidator
{
    public function __construct(
        private ScheduleBlockBuilder $blocks,
        private TeachingLoadService $teachingLoads,
        private PhpScheduleSolver $solver,
    ) {}

    /**
     * Periksa ulang seluruh draf; masalah lama yang belum diselesaikan diganti hasil terbaru.
     *
     * @return Collection<int, ScheduleIssue>
     */
    public function validate(ScheduleVersion $version): Collection
    {
        $version->loadMissing([
            'period.calendarExceptions',
            'sessions.room',
            'sessions.participants',
            'sessions.lecturers.leadershipTerms',
            'sessions.lecturers.loadTargets',
            'sessions.courseOffering.course',
        ]);

        $findings = collect()
            ->merge($this->sourceConsistency($version))
            ->merge($this->incomplete($version->sessions))
            ->merge($this->conflicts($version->sessions))
            ->merge($this->leadershipThursday($version))
            ->merge($this->availability($version))
            ->merge($this->capacity($version->sessions))
            ->merge($this->unverifiedLecturers($version->sessions))
            ->merge($this->lecturerLoad($version))
            ->merge($this->reservationConflicts($version));

        return DB::transaction(function () use ($version, $findings): Collection {
            $version->issues()->whereNull('resolved_at')->delete();

            $issues = $findings->map(fn (array $finding): ScheduleIssue => $version->issues()->create($finding));
            app(AuditLogger::class)->log('schedule.checked', $version, null, [
                'lock_version' => (int) $version->lock_version,
                'errors' => $issues->where('severity', IssueSeverity::Error)->count(),
                'warnings' => $issues->where('severity', IssueSeverity::Warning)->count(),
            ]);

            return $issues;
        });
    }

    /** @return array{checked_at: mixed, current: bool, ready: bool, errors: int, warnings: int, label: string} */
    public function reviewStatus(ScheduleVersion $version): array
    {
        $check = AuditLog::query()->where('auditable_type', $version->getMorphClass())
            ->where('auditable_id', $version->id)->where('action', 'schedule.checked')->latest('id')->first();
        $current = $check !== null && (int) ($check->after['lock_version'] ?? -1) === (int) $version->lock_version;
        $errors = $version->issues()->whereNull('resolved_at')->where('severity', IssueSeverity::Error)->count();
        $warnings = $version->issues()->whereNull('resolved_at')->where('severity', IssueSeverity::Warning)->count();

        return [
            'checked_at' => $check?->created_at,
            'current' => $current,
            'ready' => $current && $errors === 0,
            'errors' => $errors,
            'warnings' => $warnings,
            'label' => ! $current ? ($check ? 'Perlu diperiksa ulang' : 'Belum diperiksa')
                : ($errors > 0 ? 'Perlu diperbaiki' : ($warnings > 0 ? 'Siap dengan peringatan' : 'Siap ditetapkan')),
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function sourceConsistency(ScheduleVersion $version): Collection
    {
        $findings = collect();

        if ($version->period->start_date === null || $version->period->end_date === null) {
            $findings->push($this->finding('PERIOD_CALENDAR_MISSING', IssueSeverity::Error, [], 'Tanggal mulai dan akhir semester belum lengkap. Lengkapi kalender sebelum menetapkan jadwal.'));
        }
        if ($version->sessions->contains(fn (ScheduleSession $session): bool => $session->phase !== SessionPhase::Full)
            && ($version->period->uts_start === null || $version->period->uts_end === null || $version->period->uts_end->lessThan($version->period->uts_start))) {
            $findings->push($this->finding('UTS_CALENDAR_MISSING', IssueSeverity::Error, [], 'Lengkapi rentang UTS yang valid sebelum memakai pembagian sesi sebelum/setelah UTS.'));
        }

        foreach ($version->sessions as $session) {
            $sourceSks = $session->source_raw['sks'] ?? null;
            $course = $session->courseOffering?->course;
            if (is_numeric($sourceSks) && $course !== null && (int) $sourceSks !== $course->sks) {
                $findings->push($this->finding('SOURCE_SKS_CONFLICT', IssueSeverity::Error, [$session->id], "{$course->code}: sumber memuat {$sourceSks} SKS, sedangkan master memuat {$course->sks} SKS. Periksa data sumber sebelum menetapkan jadwal."));
            }
        }

        return $findings;
    }

    /**
     * @param  Collection<int, ScheduleSession>  $sessions
     * @return Collection<int, array<string, mixed>>
     */
    private function incomplete(Collection $sessions): Collection
    {
        return $sessions->flatMap(function (ScheduleSession $session): array {
            $label = $this->label($session);

            if ($session->mode === SessionMode::Unscheduled || $session->day_of_week === null || $session->start_minute === null || $session->end_minute === null) {
                $raw = $session->source_raw['waktu'] ?? null;

                return [$this->finding('SESSION_WITHOUT_TIME', IssueSeverity::Error, [$session->id], "{$label} belum punya hari dan jam".($raw ? " (\"{$raw}\")" : '').'.')];
            }

            if ($session->end_minute <= $session->start_minute) {
                return [$this->finding('SESSION_WITHOUT_TIME', IssueSeverity::Error, [$session->id], "{$label}: jam selesai harus setelah jam mulai.")];
            }

            if (! in_array($session->day_of_week, config('sicma.scheduler.days'), true)) {
                return [$this->finding('TEACHING_DAY_NOT_ALLOWED', IssueSeverity::Error, [$session->id], "{$label}: perkuliahan hanya boleh dijadwalkan Senin–Jumat.")];
            }

            $fridayBreak = config('sicma.scheduler.friday_break', ['start' => 720, 'end' => 790]);
            if ($session->day_of_week === 5 && $session->start_minute < $fridayBreak['end'] && $session->end_minute > $fridayBreak['start']) {
                return [$this->finding('FRIDAY_BREAK_CONFLICT', IssueSeverity::Error, [$session->id], "{$label}: pada hari Jumat pukul 12.00–13.10 tidak ada perkuliahan.")];
            }

            if ($session->mode === SessionMode::Offline && $session->room_id === null) {
                return [$this->finding('SESSION_WITHOUT_ROOM', IssueSeverity::Error, [$session->id], "{$label} luring belum punya ruang.")];
            }

            return [];
        });
    }

    /**
     * Bentrok ruang (hanya luring), dosen, dan kelas untuk sesi pada hari yang sama dengan jam beririsan (B03).
     *
     * @param  Collection<int, ScheduleSession>  $sessions
     * @return Collection<int, array<string, mixed>>
     */
    private function conflicts(Collection $sessions): Collection
    {
        $timed = $sessions->filter(fn (ScheduleSession $session): bool => $session->day_of_week !== null
            && $session->start_minute !== null
            && $session->end_minute !== null
            && $session->mode !== SessionMode::Unscheduled)->values();

        $findings = collect();

        foreach ($timed->groupBy('day_of_week') as $day => $daySessions) {
            $daySessions = $daySessions->sortBy('start_minute')->values();

            foreach ($daySessions as $i => $a) {
                foreach ($daySessions->slice($i + 1) as $b) {
                    if ($b->start_minute >= $a->end_minute) {
                        break;
                    }

                    if (! $this->phasesOverlap($a->phase, $b->phase)) {
                        continue;
                    }

                    $when = Wita::dayName((int) $day).' '.$this->clock(max($a->start_minute, $b->start_minute)).'–'.$this->clock(min($a->end_minute, $b->end_minute)).' WITA';

                    if ($a->blocksRoom() && $b->blocksRoom() && $a->room_id === $b->room_id) {
                        $findings->push($this->finding('ROOM_CONFLICT', IssueSeverity::Error, [$a->id, $b->id], "{$a->room->name} dipakai dua sesi pada {$when}: {$this->label($a)} dan {$this->label($b)}."));
                    }

                    $sharedLecturers = $a->lecturers->pluck('id')->intersect($b->lecturers->pluck('id'));
                    foreach ($sharedLecturers as $lecturerId) {
                        $name = $a->lecturers->firstWhere('id', $lecturerId)->name;
                        $findings->push($this->finding('LECTURER_CONFLICT', IssueSeverity::Error, [$a->id, $b->id], "{$name} mengajar dua sesi pada {$when}."));
                    }

                    $sharedClasses = $a->participants->pluck('id')->intersect($b->participants->pluck('id'));
                    foreach ($sharedClasses as $classGroupId) {
                        $name = $a->participants->firstWhere('id', $classGroupId)->name;
                        $findings->push($this->finding('CLASS_CONFLICT', IssueSeverity::Error, [$a->id, $b->id], "{$name} punya dua sesi pada {$when}."));
                    }
                }
            }
        }

        return $findings;
    }

    /**
     * Dosen pimpinan tidak boleh dijadwalkan hari Kamis selama masa jabatannya (B11).
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function leadershipThursday(ScheduleVersion $version): Collection
    {
        if ($version->period->start_date === null || $version->period->end_date === null) {
            return collect();
        }

        $from = CarbonImmutable::parse($version->effective_from->toDateString());
        $to = CarbonImmutable::parse(($version->effective_to ?? $version->period->end_date)->toDateString());

        return $version->sessions
            ->filter(fn (ScheduleSession $session): bool => $session->mode !== SessionMode::Unscheduled && $session->day_of_week === 4)
            ->flatMap(function (ScheduleSession $session) use ($version, $from, $to): Collection {
                [$phaseFrom, $phaseTo] = $this->phaseWindow($version->period, $session->phase, $from, $to);

                if ($phaseFrom->greaterThan($phaseTo)) {
                    return collect();
                }

                return $session->lecturers
                    ->filter(fn (Lecturer $lecturer): bool => $this->isLeaderDuring($lecturer, $phaseFrom, $phaseTo))
                    ->map(fn (Lecturer $lecturer): array => $this->finding(
                        'LEADERSHIP_THURSDAY',
                        IssueSeverity::Error,
                        [$session->id],
                        "{$lecturer->name} (pimpinan) dijadwalkan pada hari Kamis: {$this->label($session)}.",
                    ));
            });
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function phaseWindow(Period $period, SessionPhase $phase, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return match ($phase) {
            SessionPhase::BeforeUts => [
                $from,
                $period->uts_start !== null ? $to->min(CarbonImmutable::parse($period->uts_start->toDateString())->subDay()) : $to,
            ],
            SessionPhase::AfterUts => [
                $period->uts_end !== null ? $from->max(CarbonImmutable::parse($period->uts_end->toDateString())->addDay()) : $from,
                $to,
            ],
            SessionPhase::Full => [$from, $to],
        };
    }

    /**
     * Kapasitas ruang lebih kecil dari jumlah peserta. Hanya peringatan selama kebijakan belum ditetapkan.
     *
     * @param  Collection<int, ScheduleSession>  $sessions
     * @return Collection<int, array<string, mixed>>
     */
    private function capacity(Collection $sessions): Collection
    {
        $severity = config('sicma.scheduler.capacity_blocks_publish') ? IssueSeverity::Error : IssueSeverity::Warning;

        return $sessions
            ->filter(fn (ScheduleSession $session): bool => $session->blocksRoom())
            ->flatMap(function (ScheduleSession $session) use ($severity): array {
                $counts = $session->participants->pluck('student_count');

                if ($session->room->capacity === null || $counts->contains(null)) {
                    return [];
                }

                $students = $counts->sum();

                return $students > $session->room->capacity
                    ? [$this->finding('CAPACITY_EXCEEDED', $severity, [$session->id], "{$session->room->name} ({$session->room->capacity} kursi) kurang untuk {$students} mahasiswa: {$this->label($session)}.")]
                    : [];
            });
    }

    /**
     * @param  Collection<int, ScheduleSession>  $sessions
     * @return Collection<int, array<string, mixed>>
     */
    private function unverifiedLecturers(Collection $sessions): Collection
    {
        return $sessions
            ->flatMap(fn (ScheduleSession $session): Collection => $session->lecturers
                ->where('profile_status', LecturerProfileStatus::Unverified)
                ->map(fn (Lecturer $lecturer): array => ['lecturer' => $lecturer, 'session' => $session]))
            ->groupBy(fn (array $row): int => $row['lecturer']->id)
            ->map(fn (Collection $rows): array => $this->finding(
                'UNVERIFIED_LECTURER',
                IssueSeverity::Warning,
                $rows->pluck('session.id')->unique()->values()->all(),
                $rows->first()['lecturer']->name.' masih "Perlu verifikasi" ('.$rows->count().' sesi).',
            ))
            ->values();
    }

    /**
     * Beban SKS dosen melebihi batas maksimum yang sudah ditetapkan (NULL = belum ditetapkan, tidak diperiksa).
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function lecturerLoad(ScheduleVersion $version): Collection
    {
        $lecturers = $version->sessions->flatMap->lecturers->unique('id');

        return $lecturers->flatMap(function (Lecturer $lecturer) use ($version): array {
            $max = $lecturer->loadTargets->firstWhere('period_id', $version->period_id)?->max_sks;

            if ($max === null) {
                return [];
            }

            $sessions = $version->sessions->filter(fn (ScheduleSession $session): bool => $session->lecturers->contains('id', $lecturer->id));
            $load = 0.0;
            $pending = 0;
            foreach ($sessions->unique('course_offering_id') as $session) {
                $credit = $this->teachingLoads->semesterSks($session->courseOffering);
                if ($credit === null) {
                    $pending++;
                } else {
                    $load += $credit;
                }
            }
            $issues = [];
            if ($load > (float) $max) {
                $issues[] = $this->finding('LOAD_EXCEEDED', IssueSeverity::Error, $sessions->pluck('id')->values()->all(), "{$lecturer->name} mendapat {$load} SKS, melebihi batas maksimum {$max} SKS.");
            }
            if ($pending > 0) {
                $issues[] = $this->finding('LOAD_UNCONFIRMED', IssueSeverity::Error, $sessions->pluck('id')->values()->all(), "Beban {$lecturer->name} belum lengkap. Hubungkan {$pending} penawaran ke mata kuliah sebelum memeriksa maksimum {$max} SKS.");
            }

            return $issues;
        });
    }

    /**
     * Kemunculan sesi draf yang beririsan dengan reservasi aktif sejak tanggal berlaku (B10).
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function reservationConflicts(ScheduleVersion $version): Collection
    {
        $reservationBlocks = RoomBlock::query()
            ->active()
            ->whereIn('source_type', [RoomBlockSource::Reservation, RoomBlockSource::Closure])
            ->where('end_at', '>', $version->effective_from)
            ->get()
            ->groupBy('room_id');

        if ($reservationBlocks->isEmpty()) {
            return collect();
        }

        $sessions = $version->sessions->keyBy('id');

        return collect($this->blocks->occurrences($version))
            // Pertemuan yang sudah dipindah Keti tidak menempati ruang aslinya.
            ->filter(fn (array $occurrence): bool => $occurrence['is_active'])
            ->flatMap(function (array $occurrence) use ($reservationBlocks, $sessions): array {
                $start = CarbonImmutable::parse($occurrence['start_at']);
                $end = CarbonImmutable::parse($occurrence['end_at']);

                return $reservationBlocks->get($occurrence['room_id'], collect())
                    ->filter(fn (RoomBlock $block): bool => $block->start_at->lessThan($end) && $block->end_at->greaterThan($start))
                    ->map(fn (RoomBlock $block): array => $this->finding(
                        $block->source_type === RoomBlockSource::Closure ? 'ROOM_CLOSED' : 'RESERVATION_CONFLICT',
                        IssueSeverity::Error,
                        [$occurrence['source_id']],
                        $this->label($sessions[$occurrence['source_id']]).' bentrok dengan '.($block->source_type === RoomBlockSource::Closure ? 'penutupan ruang' : 'reservasi').' di '.$sessions[$occurrence['source_id']]->room->name.' pada '.Wita::date($block->start_at).' '.Wita::range($block->start_at, $block->end_at).'.',
                    ))
                    ->all();
            })
            ->values();
    }

    private function isLeaderDuring(Lecturer $lecturer, CarbonImmutable $from, CarbonImmutable $to): bool
    {
        return $lecturer->leadershipTerms->contains(fn ($term): bool => $term->valid_from->toDateString() <= $to->toDateString()
            && ($term->valid_to === null || $term->valid_to->toDateString() >= $from->toDateString()));
    }

    /** @return Collection<int, array<string, mixed>> */
    private function availability(ScheduleVersion $version): Collection
    {
        $availability = LecturerAvailability::query()->where('period_id', $version->period_id)->get()
            ->map(fn (LecturerAvailability $entry): array => ['lecturer' => $entry->lecturer_id, 'day' => $entry->day_of_week,
                'start' => $entry->start_minute, 'end' => $entry->end_minute, 'type' => $entry->type->value])->all();

        return $version->sessions->filter(fn (ScheduleSession $session): bool => $session->mode !== SessionMode::Unscheduled
            && $session->day_of_week !== null && $session->start_minute !== null && $session->end_minute !== null)
            ->filter(fn (ScheduleSession $session): bool => ! $this->solver->available(['lecturers' => $session->lecturers->pluck('id')->all()],
                $session->day_of_week, $session->start_minute, $session->end_minute, ['availability' => $availability]))
            ->map(fn (ScheduleSession $session): array => $this->finding('LECTURER_UNAVAILABLE', IssueSeverity::Error, [$session->id],
                $this->label($session).' berada di luar ketersediaan pengajar.'))->values();
    }

    private function phasesOverlap(SessionPhase $a, SessionPhase $b): bool
    {
        return $a === SessionPhase::Full || $b === SessionPhase::Full || $a === $b;
    }

    private function label(ScheduleSession $session): string
    {
        $classes = $session->participants->pluck('name')->implode(' + ');

        return $session->title.($classes !== '' ? ' '.$classes : '');
    }

    private function clock(int $minutes): string
    {
        return sprintf('%02d.%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /**
     * @param  list<int>  $sessionIds
     * @return array{code: string, severity: IssueSeverity, session_ids: list<int>, message: string}
     */
    private function finding(string $code, IssueSeverity $severity, array $sessionIds, string $message): array
    {
        return ['code' => $code, 'severity' => $severity, 'session_ids' => $sessionIds, 'message' => $message];
    }
}
