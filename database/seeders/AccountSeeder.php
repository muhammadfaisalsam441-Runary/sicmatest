<?php

namespace Database\Seeders;

use App\Enums\KetiApplicationStatus;
use App\Enums\UserRole;
use App\Models\Academic\ClassGroup;
use App\Models\Academic\Period;
use App\Models\User\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * DATA DEMO — akun admin dan Keti fiktif. Semua kata sandi: "password".
 */
class AccountSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $password = Hash::make('password');

        User::create([
            'name' => 'Koordinator Jadwal JTIK',
            'email' => 'superadmin@sicma.test',
            'password' => $password,
            'role' => UserRole::SuperAdmin,
            'can_publish' => true,
        ])->markEmailAsVerified();

        $admin = User::create([
            'name' => 'Admin JTIK',
            'email' => 'admin@sicma.test',
            'password' => $password,
            'role' => UserRole::Admin,
            'can_publish' => false,
        ]);
        $admin->markEmailAsVerified();

        if (! config('sicma.public_portal.show_sim_label')) {
            return;
        }

        $periodStart = Period::query()->active()->value('start_date');

        // [nama, NIM, email, kode kelas, status pengajuan, catatan admin]
        $ketis = [
            ['Dimas Pratama', '2502040001', 'dimas.pratama@sicma.test', 'TEKOM-C-2025', KetiApplicationStatus::Approved, null],
            ['Nadia Putri', '2502050002', 'nadia.putri@sicma.test', 'PTIK-B-2025', KetiApplicationStatus::Approved, null],
            ['Rizky Ramadhan', '2502040003', 'rizky.ramadhan@sicma.test', 'TEKOM-A-2025', KetiApplicationStatus::Approved, null],
            ['Andi Saputra', '2502050004', 'andi.saputra@sicma.test', 'PTIK-A-2025', KetiApplicationStatus::Pending, null],
            ['Sarah Amalia', '2502040005', 'sarah.amalia@sicma.test', 'TEKOM-B-2025', KetiApplicationStatus::NeedsRevision, 'Kelas yang dipilih belum sesuai data NIM. Periksa kembali kelas yang diwakili.'],
        ];

        foreach ($ketis as [$name, $nim, $email, $classCode, $status, $note]) {
            $keti = User::create([
                'name' => $name,
                'nim' => $nim,
                'email' => $email,
                'password' => $password,
                'role' => UserRole::Keti,
            ]);
            $keti->markEmailAsVerified();

            $classGroup = ClassGroup::query()->where('code', $classCode)->firstOrFail();
            $isReviewed = $status !== KetiApplicationStatus::Pending;

            $keti->ketiApplications()->create([
                'class_group_id' => $classGroup->id,
                'status' => $status,
                'review_note' => $note,
                'reviewed_by' => $isReviewed ? $admin->id : null,
                'reviewed_at' => $isReviewed ? now()->subDays(3) : null,
            ]);

            if ($status === KetiApplicationStatus::Approved) {
                $keti->classRepresentatives()->create([
                    'class_group_id' => $classGroup->id,
                    'valid_from' => $periodStart,
                    'valid_to' => null,
                ]);
            }
        }
    }
}
