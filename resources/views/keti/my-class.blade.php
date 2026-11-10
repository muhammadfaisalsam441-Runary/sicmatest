@use('App\Support\Wita')
@extends('layouts.keti')

@section('title', 'My Class')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header title="My Class" subtitle="Jadwal dan ruangan kelasmu." />
        @include('keti.partials.search-tabs', ['active' => 'my-class'])
        <p class="text-sm text-muted">Perkuliahan berlangsung Senin–Jumat.</p>

        <x-card class="flex items-center justify-between">
            <div class="flex flex-col gap-1">
                <h2 class="text-lg font-semibold">Kelas {{ $classGroup->name }}</h2>
                <p class="text-sm text-muted">Ketua tingkat: {{ auth()->user()->name }}</p>
            </div>
            <div class="flex flex-col items-end gap-1">
                <span class="text-xs text-muted">Tanggal jadwal</span>
                <span class="font-semibold">{{ Wita::longDate($date) }}</span>
            </div>
        </x-card>

        <nav class="flex gap-2" aria-label="Pilih hari">
            @foreach ($days as $day)
                <a href="{{ route('keti.my-class', ['tanggal' => $day->toDateString()]) }}"
                   @class(['flex flex-col items-center rounded-control px-5 py-2 text-sm', 'bg-primary text-white' => $day->isSameDay($date), 'bg-primary-soft text-primary hover:bg-primary-soft/70' => ! $day->isSameDay($date)])>
                    <span class="font-semibold">{{ Wita::dayName($day) }}</span>
                    <span class="text-xs">{{ Wita::date($day) }}</span>
                </a>
            @endforeach
        </nav>

        @if ($sessions->isEmpty())
            <x-empty-state title="Belum ada jadwal" message="Tidak ada jadwal kelas pada tanggal yang dipilih." icon="calendar">
                <x-button variant="soft" :href="route('keti.search')">Cari ruangan kosong</x-button>
            </x-empty-state>
        @else
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold">{{ $sessions->count() }} jadwal</h2>
                <span class="text-xs text-muted">Jadwal kelas • Hari yang dipilih</span>
            </div>
            <div class="grid grid-cols-2 gap-4">
                @foreach ($sessions as $session)
                    <x-card class="flex flex-col gap-3">
                        <div class="flex items-start justify-between">
                            <div class="flex flex-col gap-1">
                                <h3 class="text-xl font-medium">{{ $session->room?->name ?? 'Kuliah online' }}</h3>
                                <p class="text-sm font-semibold text-primary">{{ $session->title }}</p>
                            </div>
                            <x-status-badge variant="primary">{{ $session->mode->value === 'online' ? 'Online' : 'Terjadwal' }}</x-status-badge>
                        </div>
                        <p class="text-sm text-ink">{{ Wita::minutes($session->start_minute) }}–{{ Wita::minutes($session->end_minute) }} WITA</p>
                        <p class="text-xs text-muted">{{ $session->room ? ($session->room->building?->name ?? 'Gedung belum didata').' • Lantai '.($session->room->floor ?? '—') : 'Tanpa ruang fisik' }}</p>
                        <p class="text-xs text-muted">Pengajar: {{ $session->lecturers->pluck('name')->implode(', ') ?: 'Perlu verifikasi' }}</p>
                        @if ($session->participants->count() > 1)
                            <p class="text-xs text-muted">Kelas gabungan: {{ $session->participants->pluck('name')->implode(' + ') }}</p>
                        @endif
                        @if ($session->room)
                            <x-button variant="soft" :href="route('keti.rooms.show', $session->room)" class="self-end">Detail ruangan</x-button>
                        @endif
                    </x-card>
                @endforeach
            </div>
        @endif

        <x-card class="flex flex-col gap-4">
            <div class="flex items-center justify-between">
                <h2 class="text-lg font-semibold">Pesanan kelas</h2>
                <x-button variant="secondary" :href="route('keti.search')">Cari ruangan kosong</x-button>
            </div>
            @forelse ($reservations as $reservation)
                <a href="{{ route('keti.reservations.show', $reservation) }}" class="flex items-center justify-between rounded-control border border-border px-4 py-3 hover:bg-primary-soft/40">
                    <span class="font-medium">{{ $reservation->room->name }} • {{ $reservation->purpose }}</span>
                    <span class="flex items-center gap-3 text-sm text-muted">
                        {{ Wita::range($reservation->start_at, $reservation->end_at) }}
                        <x-status-badge :variant="$reservation->status->badgeVariant()">{{ $reservation->status->label() }}</x-status-badge>
                    </span>
                </a>
            @empty
                <p class="text-sm text-muted">Belum ada pesanan kelas pada tanggal ini.</p>
            @endforelse
        </x-card>
    </div>
@endsection
