<?php

namespace App\Support;

use App\Models\User\User;

/**
 * Tujuan setelah masuk sesuai peran dan status akun (Bagian 4.4).
 */
class HomeRoute
{
    public static function for(User $user): string
    {
        if ($user->isAdmin()) {
            return route('admin.dashboard');
        }

        if (! $user->hasVerifiedEmail()) {
            return route('verification.notice');
        }

        return $user->isApprovedKeti() ? route('keti.search') : route('keti.application');
    }
}
