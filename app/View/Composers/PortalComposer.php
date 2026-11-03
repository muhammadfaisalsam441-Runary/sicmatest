<?php

namespace App\View\Composers;

use App\Models\User;
use Illuminate\View\View;

/**
 * Data topbar Portal Keti dan Panel Admin: pengguna, kelas, dan jumlah notifikasi belum dibaca.
 */
class PortalComposer
{
    /**
     * Bind data to the view.
     */
    public function compose(View $view): void
    {
        /** @var User|null $user */
        $user = auth()->user();

        $view->with([
            'portalUser' => $user,
            'portalClassGroup' => $user?->isKeti() ? $user->activeClassGroup() : null,
            'unreadNotificationCount' => $user?->appNotifications()->unread()->count() ?? 0,
        ]);
    }
}
