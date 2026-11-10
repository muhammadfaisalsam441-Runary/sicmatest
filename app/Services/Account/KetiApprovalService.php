<?php

namespace App\Services\Account;

use App\Enums\KetiApplicationStatus;
use App\Exceptions\DomainException;
use App\Models\Academic\ClassGroup;
use App\Models\User\ClassRepresentative;
use App\Models\User\KetiApplication;
use App\Models\User\User;
use Illuminate\Support\Facades\DB;

/**
 * Pemeriksaan pengajuan Keti oleh admin (Fase 7): setujui, minta perbaikan, tolak, nonaktifkan.
 */
class KetiApprovalService
{
    public function __construct(
        private AuditLogger $audit,
        private NotificationService $notifications,
    ) {}

    /**
     * Setujui pengajuan dan hubungkan Keti ke kelas. Satu kelas hanya punya satu Keti aktif per tanggal.
     */
    public function approve(KetiApplication $application, User $admin, ?ClassGroup $classGroup = null): KetiApplication
    {
        return DB::transaction(function () use ($application, $admin, $classGroup): KetiApplication {
            $application = KetiApplication::query()->whereKey($application->id)->lockForUpdate()->with('user')->firstOrFail();
            $this->assertPending($application);

            $classGroup ??= $application->classGroup;
            $classGroup = ClassGroup::query()->whereKey($classGroup->id)->lockForUpdate()->firstOrFail();
            $applicant = $application->user;

            if (! $applicant->hasVerifiedEmail()) {
                throw new DomainException('DATA_INCOMPLETE', 'Email pendaftar belum terverifikasi.');
            }

            if (! $classGroup->is_active) {
                throw new DomainException('DATA_INCOMPLETE', 'Kelas '.$classGroup->name.' sudah tidak aktif.');
            }

            $existing = ClassRepresentative::query()
                ->whereBelongsTo($classGroup)
                ->activeOn(now())
                ->where('user_id', '!=', $applicant->id)
                ->with('user')
                ->first();

            if ($existing !== null) {
                throw new DomainException('CLASS_ALREADY_REPRESENTED', "Kelas {$classGroup->name} sudah memiliki Keti aktif ({$existing->user->name}). Nonaktifkan dulu sebelum menyetujui Keti baru.");
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
            $keti->forceFill(['is_active' => false])->save();

            $this->audit->log('keti.deactivated', $keti, ['is_active' => true], ['is_active' => false], $reason);
        });
    }

    private function assertPending(KetiApplication $application): void
    {
        if ($application->status !== KetiApplicationStatus::Pending) {
            throw new DomainException('INVALID_STATUS_TRANSITION', 'Pengajuan ini sudah diperiksa.');
        }
    }
}
