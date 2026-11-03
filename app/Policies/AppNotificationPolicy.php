<?php

namespace App\Policies;

use App\Models\AppNotification;
use App\Models\User;

/**
 * Notifikasi hanya bisa dibaca/ditandai oleh pemiliknya.
 */
class AppNotificationPolicy
{
    public function update(User $user, AppNotification $notification): bool
    {
        return $notification->user_id === $user->id;
    }
}
