@use('App\Support\Wita')
@extends('layouts.keti')

@section('title', 'Notifikasi')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header title="Notifikasi" subtitle="Informasi terbaru tentang ruanganmu.">
            @if ($unreadCount > 0)
                <x-slot:actions>
                    <form method="POST" action="{{ route('keti.notifications.read-all') }}">
                        @csrf
                        <x-button type="submit" variant="secondary">Tandai semua dibaca</x-button>
                    </form>
                </x-slot:actions>
            @endif
        </x-page-header>

        <x-tabs :items="[
            ['label' => 'Semua', 'href' => route('keti.notifications'), 'active' => ! $unreadOnly],
            ['label' => 'Belum dibaca • '.$unreadCount, 'href' => route('keti.notifications', ['filter' => 'belum']), 'active' => $unreadOnly],
        ]" />

        @forelse ($notifications as $notification)
            <x-card class="flex items-start justify-between gap-6">
                <div class="flex gap-4">
                    <span class="w-14 text-sm text-muted">{{ Wita::clock($notification->created_at, false) }}</span>
                    <div class="flex flex-col gap-1">
                        <div class="flex items-center gap-2">
                            <h3 class="font-semibold">{{ $notification->title }}</h3>
                            @if ($notification->read_at === null)<x-status-badge variant="primary">Baru</x-status-badge>@endif
                        </div>
                        <p class="text-sm text-muted">{{ $notification->body }}</p>
                        <p class="text-xs text-muted">{{ Wita::date($notification->created_at) }}</p>
                    </div>
                </div>
                <form method="POST" action="{{ route('keti.notifications.read', $notification) }}">
                    @csrf
                    <x-button type="submit" variant="soft">{{ isset($notification->data['reservation_id']) ? 'Lihat' : 'Tandai dibaca' }}</x-button>
                </form>
            </x-card>
        @empty
            <x-empty-state title="Belum ada notifikasi" message="Informasi terbaru tentang ruangan dan pemakaianmu akan muncul di sini." icon="bell">
                <x-button variant="soft" :href="route('keti.search')">Cari ruangan</x-button>
            </x-empty-state>
        @endforelse
    </div>
@endsection
