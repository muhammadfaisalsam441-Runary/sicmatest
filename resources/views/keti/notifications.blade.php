@use('App\Support\Wita')
@extends('layouts.keti')

@section('title', 'Notifikasi')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header title="Notifikasi">
            @if ($unreadCount > 0)
                <x-slot:actions>
                    <form method="POST" action="{{ route('keti.notifications.read-all') }}" class="w-full sm:w-auto">
                        @csrf
                        <x-button type="submit" variant="secondary" class="min-h-11 w-full sm:w-auto">Tandai semua dibaca</x-button>
                    </form>
                </x-slot:actions>
            @endif
        </x-page-header>

        <x-tabs :items="[
            ['label' => 'Semua', 'href' => route('keti.notifications'), 'active' => ! $unreadOnly],
            ['label' => 'Belum dibaca • '.$unreadCount, 'href' => route('keti.notifications', ['filter' => 'belum']), 'active' => $unreadOnly],
        ]" />

        @forelse ($notifications as $notification)
            @php($isUnread = $notification->read_at === null)
            <x-card padding="p-4 sm:p-6" :class="'sicma-card-enter sicma-card-interactive flex flex-col justify-between gap-4 sm:flex-row sm:items-center '.($isUnread ? 'border-primary/25 bg-primary-soft/20' : '')" style="--stagger: {{ $loop->index }}">
                <div class="flex min-w-0 items-start gap-3.5">
                    <span @class([
                        'flex size-10 shrink-0 items-center justify-center rounded-control border',
                        'border-primary/25 bg-primary-soft text-primary' => $isUnread,
                        'border-border bg-page text-muted' => ! $isUnread,
                    ])>
                        <i data-lucide="bell" aria-hidden="true" class="size-4"></i>
                    </span>
                    <div class="flex min-w-0 flex-col gap-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="font-semibold text-ink">{{ $notification->title }}</h3>
                            @if ($isUnread)
                                <x-status-badge variant="primary" :pulse="true">Baru</x-status-badge>
                            @endif
                        </div>
                        <p class="text-sm leading-relaxed text-muted">{{ $notification->body }}</p>
                        <p class="inline-flex items-center gap-1.5 text-xs text-muted tabular-nums">
                            <i data-lucide="clock" aria-hidden="true" class="size-3.5 shrink-0"></i>
                            <span>{{ Wita::date($notification->created_at) }}, {{ Wita::clock($notification->created_at, false) }} WITA</span>
                        </p>
                    </div>
                </div>
                <form method="POST" action="{{ route('keti.notifications.read', $notification) }}" class="w-full shrink-0 sm:w-auto sm:self-center">
                    @csrf
                    <x-button size="sm" type="submit" :variant="$isUnread ? 'soft' : 'secondary'" class="min-h-11 w-full sm:min-h-0 sm:w-auto">{{ isset($notification->data['reservation_id']) ? 'Lihat' : 'Tandai dibaca' }}</x-button>
                </form>
            </x-card>
        @empty
            <x-empty-state title="Belum ada notifikasi" message="Informasi terbaru tentang ruangan dan pemakaianmu akan muncul di sini." icon="bell">
                <x-button variant="soft" :href="route('keti.search')" class="min-h-11 sm:min-h-0">Cari ruangan</x-button>
            </x-empty-state>
        @endforelse
    </div>
@endsection
