<?php

namespace App\Http\Controllers\Keti;

use App\Http\Controllers\Controller;
use App\Models\User\AppNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        $unreadOnly = $request->query('filter') === 'belum';
        $notifications = $request->user()->appNotifications();

        return view('keti.notifications', [
            'notifications' => (clone $notifications)->when($unreadOnly, fn ($query) => $query->whereNull('read_at'))->latest()->limit(50)->get(),
            'unreadCount' => (clone $notifications)->unread()->count(),
            'unreadOnly' => $unreadOnly,
        ]);
    }

    public function read(AppNotification $notification): RedirectResponse
    {
        Gate::authorize('update', $notification);
        $notification->update(['read_at' => $notification->read_at ?? now()]);

        $reservationId = $notification->data['reservation_id'] ?? null;

        return $reservationId
            ? redirect()->route('keti.reservations.show', $reservationId)
            : back();
    }

    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->appNotifications()->unread()->update(['read_at' => now()]);

        return back()->with('toast', ['type' => 'success', 'title' => 'Semua notifikasi ditandai dibaca']);
    }
}
