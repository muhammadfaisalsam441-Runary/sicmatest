<?php

namespace App\Services\Scheduling;

use App\Enums\IssueSeverity;
use App\Enums\RoomBlockSource;
use App\Enums\ScheduleVersionStatus;
use App\Enums\UserRole;
use App\Exceptions\DomainException;
use App\Models\Room\RoomBlock;
use App\Models\Scheduling\ScheduleVersion;
use App\Models\User\ClassRepresentative;
use App\Models\User\User;
use App\Services\Account\AuditLogger;
use App\Services\Account\NotificationService;
use App\Support\AvailabilityCache;
use App\Support\Wita;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Menetapkan draf menjadi Jadwal resmi (B09, B10, B21).
 *
 * Penetapan memeriksa ulang draf (termasuk bentrok dengan reservasi yang ada), menolak bila masih ada
 * masalah wajib atau draf berubah sejak ditinjau (lock_version), lalu mengganti blok ruang mulai tanggal berlaku.
 */
class SchedulePublisher
{
    public function __construct(
        private ScheduleValidator $validator,
        private ScheduleBlockBuilder $blocks,
        private AuditLogger $audit,
        private NotificationService $notifications,
    ) {}

    /**
     * @throws DomainException NOT_AUTHORIZED | INVALID_STATUS_TRANSITION | VERSION_CONFLICT | INVALID_DATE | DRAFT_HAS_ISSUES
     */
    public function publish(ScheduleVersion $draft, User $actor, CarbonInterface $effectiveFrom, int $expectedLockVersion, ?string $note = null): ScheduleVersion
    {
        if (Gate::forUser($actor)->denies('publish-schedule')) {
            throw new DomainException('NOT_AUTHORIZED', 'Hanya admin dengan izin menetapkan jadwal yang boleh menetapkan draf.');
        }

        $effectiveFrom = CarbonImmutable::instance($effectiveFrom)->setTimezone(config('app.timezone'))->startOfDay();

        $this->assertPublishable($draft, $effectiveFrom, $expectedLockVersion);

        // Pemeriksaan pertama di luar transaksi agar daftar masalah tersimpan dan bisa ditinjau admin.
        $draft->forceFill(['effective_from' => $effectiveFrom->max($draft->period->start_date)->toDateString()])->save();
        $this->assertNoBlockingIssues($draft);

        $published = DB::transaction(function () use ($draft, $actor, $effectiveFrom, $expectedLockVersion, $note): ScheduleVersion {
            $draft = ScheduleVersion::query()->whereKey($draft->id)->lockForUpdate()->with('period')->firstOrFail();

            // Periksa ulang dengan data terbaru: reservasi baru atau perubahan draf serentak menghentikan penetapan.
            $this->assertPublishable($draft, $effectiveFrom, $expectedLockVersion);
            $this->assertNoBlockingIssues($draft);

            $this->supersedePrevious($draft, $effectiveFrom);

            $draft->forceFill([
                'status' => ScheduleVersionStatus::Official,
                'effective_to' => null,
                'published_by' => $actor->id,
                'published_at' => now(),
                'lock_version' => $draft->lock_version + 1,
            ])->save();

            $count = $this->blocks->rebuild($draft);

            AvailabilityCache::bust();

            $this->audit->log('schedule.published', $draft, ['status' => 'draft'], [
                'status' => 'official',
                'version_no' => $draft->version_no,
                'effective_from' => $draft->effective_from->toDateString(),
                'room_blocks' => $count,
            ], $note);

            return $draft;
        });

        $this->announce($published);

        return $published;
    }

    private function assertPublishable(ScheduleVersion $draft, CarbonImmutable $effectiveFrom, int $expectedLockVersion): void
    {
        $draft->loadMissing('period');

        if ($draft->period->start_date === null || $draft->period->end_date === null) {
            throw new DomainException('INVALID_DATE', 'Lengkapi kalender semester sebelum menetapkan jadwal.');
        }

        if (! in_array($draft->status, [ScheduleVersionStatus::Draft, ScheduleVersionStatus::Ready], true)) {
            throw new DomainException('INVALID_STATUS_TRANSITION', "Versi {$draft->version_no} bukan draf sehingga tidak bisa ditetapkan.");
        }

        if ($draft->lock_version !== $expectedLockVersion) {
            throw new DomainException('VERSION_CONFLICT', 'Draf berubah sejak terakhir diperiksa. Tinjau ulang perubahan sebelum menetapkan.');
        }

        if ($effectiveFrom->lessThan(CarbonImmutable::today()) || $effectiveFrom->greaterThan($draft->period->end_date)) {
            throw new DomainException('INVALID_DATE', 'Tanggal berlaku harus mulai hari ini dan berada dalam semester '.$draft->period->name.'.');
        }
    }

    private function assertNoBlockingIssues(ScheduleVersion $draft): void
    {
        $blocking = $this->validator->validate($draft->fresh())
            ->filter(fn ($issue): bool => $issue->severity === IssueSeverity::Error);

        if ($blocking->isNotEmpty()) {
            throw new DomainException('DRAFT_HAS_ISSUES', "Selesaikan {$blocking->count()} masalah wajib dulu.", ['issues' => $blocking->pluck('message')->all()]);
        }
    }

    /**
     * Versi resmi sebelumnya berakhir sehari sebelum versi baru berlaku; blok ruangnya sejak tanggal itu dinonaktifkan.
     */
    private function supersedePrevious(ScheduleVersion $draft, CarbonImmutable $effectiveFrom): void
    {
        $previous = ScheduleVersion::query()
            ->whereBelongsTo($draft->period)
            ->official()
            ->whereKeyNot($draft->id)
            ->lockForUpdate()
            ->get();

        foreach ($previous as $version) {
            $version->forceFill([
                'status' => ScheduleVersionStatus::Superseded,
                'effective_to' => $effectiveFrom->subDay()->max($version->effective_from)->toDateString(),
            ])->save();

            RoomBlock::query()
                ->whereBelongsTo($version, 'scheduleVersion')
                ->where('source_type', RoomBlockSource::Schedule)
                ->where('start_at', '>=', $effectiveFrom)
                ->update(['is_active' => false]);

            $this->audit->log('schedule.superseded', $version, ['status' => 'official'], ['status' => 'superseded', 'effective_to' => $version->effective_to->toDateString()]);
        }
    }

    /**
     * Beri tahu Keti aktif yang kelasnya ada di versi ini dan admin lain.
     */
    private function announce(ScheduleVersion $version): void
    {
        $classGroupIds = DB::table('session_participants')
            ->join('schedule_sessions', 'schedule_sessions.id', '=', 'session_participants.schedule_session_id')
            ->where('schedule_sessions.schedule_version_id', $version->id)
            ->distinct()
            ->pluck('session_participants.class_group_id');

        $ketis = User::query()
            ->where('role', UserRole::Keti)
            ->where('is_active', true)
            ->whereIn('id', ClassRepresentative::query()->activeOn(now())->whereIn('class_group_id', $classGroupIds)->select('user_id'))
            ->get();

        $this->notifications->notify(
            $ketis,
            'schedule.published',
            'Jadwal kelas diperbarui',
            "Jadwal resmi v{$version->version_no} berlaku mulai ".Wita::longDate($version->effective_from).'. Cek My Class untuk jadwal terbaru.',
            ['schedule_version_id' => $version->id],
        );

        $this->notifications->notify(
            $this->notifications->admins(),
            'schedule.published',
            "Jadwal resmi v{$version->version_no} ditetapkan",
            'Berlaku mulai '.Wita::longDate($version->effective_from).'.',
            ['schedule_version_id' => $version->id],
        );
    }
}
