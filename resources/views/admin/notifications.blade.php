@use('App\Support\Wita')
@extends('layouts.admin')

@section('title', 'Notifikasi')

@section('breadcrumb-actions')
    <x-button variant="secondary" icon="arrow-left" :href="url()->previous() !== url()->current() ? url()->previous() : route('admin.dashboard')">Kembali</x-button>
@endsection

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header title="Pemberitahuan" :subtitle="$unreadCount.' belum dibaca dari '.$totalCount.' notifikasi terbaru.'">
            @if ($unreadCount > 0)
                <x-slot:actions>
                    <form method="POST" action="{{ route('admin.notifications.read-all') }}">
                        @csrf
                        <x-button type="submit" variant="secondary">Tandai semua dibaca</x-button>
                    </form>
                </x-slot:actions>
            @endif
        </x-page-header>

        <x-tabs :items="collect(['semua' => 'Semua', 'belum' => 'Belum dibaca', 'reservasi' => 'Reservasi', 'jadwal' => 'Jadwal', 'sistem' => 'Sistem'])
            ->map(fn ($label, $key) => [
                'label' => $label,
                'href' => route('admin.notifications', $key === 'semua' ? [] : ['filter' => $key]),
                'active' => $filter === $key,
                ...($key === 'belum' && $unreadCount > 0 ? ['count' => $unreadCount] : []),
            ])
            ->values()->all()" />

        @forelse ($notifications as $notification)
            @php
                $hasTargetLink = isset($notification->data['reservation_id'])
                    || isset($notification->data['keti_application_id'])
                    || isset($notification->data['optimization_job_id'])
                    || isset($notification->data['schedule_version_id']);
                $actionLabel = match (true) {
                    isset($notification->data['reservation_id']) => 'Lihat reservasi',
                    isset($notification->data['keti_application_id']) => 'Periksa pengajuan',
                    isset($notification->data['optimization_job_id']) => 'Lihat proses jadwal',
                    isset($notification->data['schedule_version_id']) => 'Lihat draf jadwal',
                    default => 'Tandai dibaca',
                };
            @endphp
            <x-card class="flex flex-col justify-between gap-4 sm:flex-row sm:items-start sm:gap-6">
                <div class="flex flex-col gap-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <h3 class="font-semibold">{{ $notification->title }}</h3>
                        @if ($notification->read_at === null)<x-status-badge variant="primary">Baru</x-status-badge>@endif
                    </div>
                    <p class="text-sm text-muted">{{ $notification->body }}</p>
                    <p class="text-xs text-muted">{{ $notification->created_at->locale('id')->diffForHumans() }} • {{ Wita::stamp($notification->created_at) }}</p>
                </div>
                @if ($notification->read_at === null || $hasTargetLink)
                    <form method="POST" action="{{ route('admin.notifications.read', $notification) }}" class="shrink-0">
                        @csrf
                        <x-button type="submit" variant="soft">{{ $actionLabel }}</x-button>
                    </form>
                @endif
            </x-card>
        @empty
            <x-empty-state title="Belum ada notifikasi" message="Reservasi baru, pengajuan Keti, dan hasil impor jadwal akan muncul di sini." icon="bell" />
        @endforelse
    </div>
@endsection
