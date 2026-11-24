<?php

namespace App\Services\Account;

use App\Enums\KetiApplicationStatus;
use App\Exceptions\DomainException;
use App\Models\Academic\ClassGroup;
use App\Models\User\ClassRepresentative;
use App\Models\User\KetiApplication;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Pemeriksaan pengajuan Keti oleh admin (Fase 7): setujui, minta perbaikan, tolak, nonaktifkan, aktifkan kembali.
 */
class KetiApprovalService
{
    public function __construct(
        private AuditLogger $audit,
        private NotificationService $notifications,
    ) {}

    /**
     * Batas maksimal akun Keti aktif dalam satu kelas.
     */
    public function maxPerClass(): int
    {
        return max(1, (int) config('sicma.keti.max_per_class', 2));
    }

    /**
     * Peta jumlah Keti aktif saat ini per ID kelas.
     *
     * @return array<int, int>
     */
    public function activeKetiCountsByClass(): array
    {
        return ClassRepresentative::query()
            ->activeOn(now())
            ->whereHas('user', fn (Builder $query): Builder => $query->where('is_active', true))
            ->selectRaw('class_group_id, COUNT(DISTINCT user_id) as total')
            ->groupBy('class_group_id')
            ->pluck('total', 'class_group_id')
            ->map(fn (mixed $total): int => (int) $total)
            ->all();
    }

    /**
     * Format label pilihan kelas dengan informasi kuota Keti aktif.
     */
    public function formatClassOptionLabel(ClassGroup $classGroup, int $activeCount): string
    {
        $maxPerClass = $this->maxPerClass();

        if ($activeCount >= $maxPerClass) {
            return "{$classGroup->name} (Kuota Keti penuh {$activeCount}/{$maxPerClass})";
        }

        if ($activeCount > 0) {
            return "{$classGroup->name} ({$activeCount}/{$maxPerClass} Keti aktif)";
        }

        return $classGroup->name;
    }

    /**
     * Setujui pengajuan dan hubungkan Keti ke kelas hingga batas kuota Keti aktif per kelas.
     */
    public function approve(KetiApplication $application, User $admin, ?ClassGroup $classGroup = null): KetiApplication
    {
        return DB::transaction(function () use ($application, $admin, $classGroup): KetiApplication {
            $application = KetiApplication::query()->whereKey($application->id)->lockForUpdate()->with('user')->firstOrFail();

            if ($application->status === KetiApplicationStatus::Approved) {
                throw new DomainException('INVALID_STATUS_TRANSITION', 'Pengajuan ini sudah disetujui.');
            }

            $classGroup ??= $application->classGroup;
            $classGroup = ClassGroup::query()->whereKey($classGroup->id)->lockForUpdate()->firstOrFail();
            $applicant = $application->user;

            if (! $applicant->hasVerifiedEmail()) {
                throw new DomainException('DATA_INCOMPLETE', 'Email pendaftar belum terverifikasi.');
            }

            if (! $classGroup->is_active) {
                throw new DomainException('DATA_INCOMPLETE', 'Kelas '.$classGroup->name.' sudah tidak aktif.');
            }

            $maxPerClass = $this->maxPerClass();
            $activeCount = $this->activeKetiCountForClass($classGroup, $applicant->id);

            if ($activeCount >= $maxPerClass) {
                throw new DomainException(
                    'CLASS_ALREADY_REPRESENTED',
                    "Kuota Keti untuk kelas {$classGroup->name} sudah penuh ({$activeCount}/{$maxPerClass} Keti aktif). Nonaktifkan salah satu Keti dulu sebelum menyetujui Keti baru."
                );
            }

            $before = $application->only(['status', 'class_group_id']);
            $application->forceFill([
                'class_group_id' => $classGroup->id,
                'status' => KetiApplicationStatus::Approved,
                'review_note' => null,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ])->save();

            $applicant->classRepresentatives()->activeOn(now())->update(['valid_to' => now()->subDay()->toDateString()]);
            $applicant->classRepresentatives()->create([
                'class_group_id' => $classGroup->id,
                'valid_from' => now()->toDateString(),
                'valid_to' => null,
            ]);
            $applicant->forceFill(['is_active' => true])->save();

            $this->audit->log('keti_application.approved', $application, $before, $application->only(['status', 'class_group_id']));
            $this->notifications->notify($applicant, 'keti_application.approved', 'Akun Keti sudah aktif', "Pengajuanmu disetujui. Akun terhubung ke kelas {$classGroup->name}.", ['keti_application_id' => $application->id]);

            return $application;
        });
    }

    /**
     * Minta perbaikan (needs_revision) atau tolak (rejected) dengan alasan yang dapat dibaca pendaftar.
     */
    public function decline(KetiApplication $application, User $admin, string $reason, bool $final = false): KetiApplication
    {
        if (blank($reason)) {
            throw new DomainException('DATA_INCOMPLETE', 'Alasan untuk pendaftar wajib diisi.');
        }

        return DB::transaction(function () use ($application, $admin, $reason, $final): KetiApplication {
            $application = KetiApplication::query()->whereKey($application->id)->lockForUpdate()->with('user')->firstOrFail();
            $this->assertPending($application);

            $status = $final ? KetiApplicationStatus::Rejected : KetiApplicationStatus::NeedsRevision;
            $before = $application->only(['status']);
            $application->forceFill([
                'status' => $status,
                'review_note' => $reason,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ])->save();

            $this->audit->log('keti_application.'.$status->value, $application, $before, ['status' => $status->value], $reason);
            $this->notifications->notify(
                $application->user,
                'keti_application.'.$status->value,
                $final ? 'Pengajuan ditolak' : 'Pengajuan perlu diperbaiki',
                $reason,
                ['keti_application_id' => $application->id],
            );

            return $application;
        });
    }

    /**
     * Nonaktifkan akun Keti: hak pemesanan dicabut mulai hari ini, riwayat tetap tersimpan.
     */
    public function deactivate(User $keti, User $admin, string $reason): void
    {
        if (blank($reason)) {
            throw new DomainException('DATA_INCOMPLETE', 'Alasan menonaktifkan akun wajib diisi.');
        }

        DB::transaction(function () use ($keti, $reason): void {
            $keti->classRepresentatives()->activeOn(now())->update(['valid_to' => now()->subDay()->toDateString()]);
            $keti->forceFill([
                'is_active' => false,
                'remember_token' => Str::random(60),
            ])->save();

            $this->invalidateUserSessions($keti);

            $this->audit->log('keti.deactivated', $keti, ['is_active' => true], ['is_active' => false], $reason);
        });
    }

    /**
     * Aktifkan kembali akun Keti yang pernah disetujui jika kuota kelas masih tersedia.
     */
    public function reactivate(User $keti, User $admin): KetiApplication
    {
        return DB::transaction(function () use ($keti): KetiApplication {
            $keti = User::query()->whereKey($keti->id)->lockForUpdate()->firstOrFail();

            if (! $keti->isKeti()) {
                throw new DomainException('INVALID_STATUS_TRANSITION', 'Akun ini bukan akun Keti.');
            }

            if ($keti->is_active) {
                throw new DomainException('INVALID_STATUS_TRANSITION', 'Akun Keti ini sudah aktif.');
            }

            if (! $keti->hasVerifiedEmail()) {
                throw new DomainException('DATA_INCOMPLETE', 'Email pendaftar belum terverifikasi.');
            }

            $latest = $keti->latestKetiApplication()->with('classGroup')->first();

            if ($latest === null || $latest->status !== KetiApplicationStatus::Approved) {
                throw new DomainException('INVALID_STATUS_TRANSITION', 'Hanya akun Keti yang pernah disetujui yang dapat diaktifkan kembali.');
            }

            $classGroup = ClassGroup::query()->whereKey($latest->class_group_id)->lockForUpdate()->firstOrFail();

            if (! $classGroup->is_active) {
                throw new DomainException('DATA_INCOMPLETE', 'Kelas '.$classGroup->name.' sudah tidak aktif.');
            }

            $maxPerClass = $this->maxPerClass();
            $activeCount = $this->activeKetiCountForClass($classGroup, $keti->id);

            if ($activeCount >= $maxPerClass) {
                throw new DomainException(
                    'CLASS_ALREADY_REPRESENTED',
                    "Kuota Keti untuk kelas {$classGroup->name} sudah penuh ({$activeCount}/{$maxPerClass} Keti aktif)."
                );
            }

            $keti->classRepresentatives()->activeOn(now())->update(['valid_to' => now()->subDay()->toDateString()]);
            $keti->classRepresentatives()->create([
                'class_group_id' => $classGroup->id,
                'valid_from' => now()->toDateString(),
                'valid_to' => null,
            ]);
            $keti->forceFill(['is_active' => true])->save();

            $this->audit->log('keti.reactivated', $keti, ['is_active' => false], ['is_active' => true, 'class_group_id' => $classGroup->id]);
            $this->notifications->notify(
                $keti,
                'keti.reactivated',
                'Akun Keti diaktifkan kembali',
                "Akses Keti untuk kelas {$classGroup->name} telah diaktifkan kembali.",
                ['keti_application_id' => $latest->id],
            );

            return $latest;
        });
    }

    /**
     * Hitung jumlah Keti aktif pada kelas tertentu selain akun yang sedang diperiksa.
     */
    private function activeKetiCountForClass(ClassGroup $classGroup, int $excludeUserId): int
    {
        return ClassRepresentative::query()
            ->whereBelongsTo($classGroup)
            ->activeOn(now())
            ->where('user_id', '!=', $excludeUserId)
            ->whereHas('user', fn (Builder $query): Builder => $query->where('is_active', true))
            ->count();
    }

    /**
     * Hapus seluruh sesi database milik akun Keti yang dinonaktifkan.
     */
    private function invalidateUserSessions(User $user): void
    {
        $table = config('session.table', 'sessions') ?: 'sessions';
        $connection = config('session.connection');

        if (Schema::connection($connection)->hasTable($table)) {
            DB::connection($connection)->table($table)
                ->where('user_id', $user->id)
                ->delete();
        }
    }

    private function assertPending(KetiApplication $application): void
    {
        if ($application->status !== KetiApplicationStatus::Pending) {
            throw new DomainException('INVALID_STATUS_TRANSITION', 'Pengajuan ini sudah diperiksa.');
        }
    }
}
