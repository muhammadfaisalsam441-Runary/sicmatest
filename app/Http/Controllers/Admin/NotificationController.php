<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User\AppNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Pemberitahuan admin: reservasi baru/dibatalkan, pengajuan Keti, hasil impor & susun jadwal.
 */
class NotificationController extends Controller
{
    /**
     * Tab → awalan tipe notifikasi.
     *
     * @var array<string, list<string>>
     */
    public const CATEGORIES = [
        'reservasi' => ['reservation.', 'room.'],
        'jadwal' => ['schedule.', 'import.', 'optimization.'],
        'sistem' => ['keti_application.', 'system.'],
    ];

    public function index(Request $request): View
    {
        $filter = (string) $request->query('filter', 'semua');
        $notifications = $request->user()->appNotifications();

        $list = (clone $notifications)
            ->when($filter === 'belum', fn ($query) => $query->whereNull('read_at'))
            ->when(isset(self::CATEGORIES[$filter]), fn ($query) => $query->where(function ($query) use ($filter): void {
                foreach (self::CATEGORIES[$filter] as $prefix) {
                    $query->orWhere('type', 'like', $prefix.'%');
                }
            }))
            ->latest()
            ->limit(50)
            ->get();

        return view('admin.notifications', [
            'notifications' => $list,
            'unreadCount' => (clone $notifications)->unread()->count(),
            'totalCount' => (clone $notifications)->count(),
            'filter' => $filter,
        ]);
    }

    public function read(AppNotification $notification): RedirectResponse
    {
        Gate::authorize('update', $notification);
        $notification->update(['read_at' => $notification->read_at ?? now()]);

        return match (true) {
            isset($notification->data['optimization_job_id']) => to_route('admin.scheduler.show', $notification->data['optimization_job_id']),
            isset($notification->data['schedule_version_id']) => to_route('admin.drafts.show', $notification->data['schedule_version_id']),
            isset($notification->data['reservation_id']) => to_route('admin.reservations.index'),
            isset($notification->data['keti_application_id']) => to_route('admin.keti-accounts.index'),
            default => back(),
        };
    }

    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->appNotifications()->unread()->update(['read_at' => now()]);

        return back()->with('toast', ['type' => 'success', 'title' => 'Semua notifikasi ditandai dibaca']);
    }
}
