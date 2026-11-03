<?php

namespace App\Services;

use App\Enums\KetiApplicationStatus;
use App\Enums\UserRole;
use App\Exceptions\DomainException;
use App\Models\ClassGroup;
use App\Models\KetiApplication;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Pendaftaran Keti dan pengajuan ulang. Persetujuan admin ditangani di Fase 7.
 */
class KetiApplicationService
{
    public function __construct(
        private EmailVerificationService $verification,
        private NotificationService $notifications,
        private AuditLogger $audit,
    ) {}

    /**
     * Buat akun Keti (belum terverifikasi) + pengajuan pending, lalu kirim kode verifikasi.
     *
     * @param  array{name: string, nim: string, email: string, password: string, class_group_id: int|string}  $data
     */
    public function register(array $data): User
    {
        $user = DB::transaction(function () use ($data): User {
            $user = User::create([
                'name' => $data['name'],
                'nim' => $data['nim'],
                'email' => $data['email'],
                'password' => $data['password'],
                'role' => UserRole::Keti,
            ]);

            $user->ketiApplications()->create([
                'class_group_id' => $data['class_group_id'],
                'status' => KetiApplicationStatus::Pending,
            ]);

            return $user;
        });

        $this->verification->send($user, ignoreCooldown: true);

        return $user;
    }

    /**
     * Beri tahu admin bahwa ada pengajuan yang siap diperiksa (dipanggil setelah email terverifikasi).
     */
    public function announceToAdmins(User $user): void
    {
        $application = $user->latestKetiApplication()->with('classGroup')->first();

        if ($application === null || $application->status !== KetiApplicationStatus::Pending) {
            return;
        }

        $this->notifications->notify(
            $this->notifications->admins(),
            'keti_application.submitted',
            'Pengajuan Keti baru',
            "{$user->name} mengajukan akun Keti untuk {$application->classGroup->name}.",
            ['keti_application_id' => $application->id],
        );
    }

    /**
     * Ajukan ulang setelah diminta perbaikan/ditolak. Riwayat pengajuan lama tetap tersimpan.
     */
    public function resubmit(User $user, ClassGroup $classGroup): KetiApplication
    {
        $latest = $user->latestKetiApplication;

        if (! in_array($latest?->status, [KetiApplicationStatus::NeedsRevision, KetiApplicationStatus::Rejected], true)) {
            throw new DomainException('INVALID_STATUS_TRANSITION', 'Pengajuan ini tidak dapat diajukan ulang.');
        }

        $application = DB::transaction(function () use ($user, $classGroup, $latest): KetiApplication {
            $application = $user->ketiApplications()->create([
                'class_group_id' => $classGroup->id,
                'status' => KetiApplicationStatus::Pending,
            ]);

            $this->audit->log('keti_application.resubmitted', $application, ['previous_application_id' => $latest->id], [
                'class_group_id' => $classGroup->id,
            ]);

            return $application;
        });

        $this->announceToAdmins($user);

        return $application;
    }
}
