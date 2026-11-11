<?php

namespace App\Services\Account;

use App\Enums\UserRole;
use App\Models\User\AppNotification;
use App\Models\User\ClassRepresentative;
use App\Models\User\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Membuat notifikasi aplikasi. Notifikasi ditulis setelah transaksi berhasil (afterCommit)
 * agar tidak ada notifikasi untuk perubahan yang dibatalkan.
 */
class NotificationService
{
    /**
     * @param  User|iterable<User>  $recipients
     * @param  array<string, mixed>  $data
     */
    public function notify(User|iterable $recipients, string $type, string $title, string $body, array $data = []): void
    {
        $users = $recipients instanceof User ? collect([$recipients]) : collect($recipients);

        DB::afterCommit(function () use ($users, $type, $title, $body, $data): void {
            foreach ($users as $user) {
                AppNotification::create([
                    'user_id' => $user->id,
                    'type' => $type,
                    'title' => $title,
                    'body' => $body,
                    'data' => $data,
                ]);
            }
        });
    }

    /**
     * Keti aktif yang saat ini mewakili kelas-kelas tersebut.
     *
     * @param  iterable<int>  $classGroupIds
     * @return Collection<int, User>
     */
    public function ketisOf(iterable $classGroupIds): Collection
    {
        $ids = collect($classGroupIds)->all();

        if ($ids === []) {
            return new Collection;
        }

        return User::query()
            ->where('role', UserRole::Keti)
            ->where('is_active', true)
            ->whereIn('id', ClassRepresentative::query()->activeOn(now())->whereIn('class_group_id', $ids)->select('user_id'))
            ->get();
    }

    /**
     * Semua admin aktif.
     *
     * @return Collection<int, User>
     */
    public function admins(): Collection
    {
        return User::query()
            ->whereIn('role', [UserRole::Admin, UserRole::SuperAdmin])
            ->where('is_active', true)
            ->get();
    }
}
