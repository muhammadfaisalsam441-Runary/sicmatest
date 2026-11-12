@use('App\Support\Wita')
@extends('layouts.keti')

@section('title', 'My Class')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header title="My Class" :subtitle="'Jadwal dan perkuliahan Kelas '.$classGroup->name.'.'" />

        <div class="flex items-center justify-between">
            <a href="{{ route('keti.my-class', ['tanggal' => $date->subWeek()->toDateString()]) }}" class="inline-flex items-center gap-1.5 rounded-control border border-border bg-surface px-3 py-1.5 text-xs font-semibold text-primary hover:bg-primary-soft/60">
                <i data-lucide="chevron-left" class="size-4"></i>
                <span>Minggu lalu</span>
            </a>
            <span class="text-xs font-semibold text-muted">
                {{ Wita::date($days->first()) }} – {{ Wita::date($days->last()) }}
            </span>
            <a href="{{ route('keti.my-class', ['tanggal' => $date->addWeek()->toDateString()]) }}" class="inline-flex items-center gap-1.5 rounded-control border border-border bg-surface px-3 py-1.5 text-xs font-semibold text-primary hover:bg-primary-soft/60">
                <span>Minggu depan</span>
                <i data-lucide="chevron-right" class="size-4"></i>
            </a>
        </div>

        <nav class="grid grid-cols-5 gap-3" aria-label="Pilih hari">
            @foreach ($days as $day)
                <a href="{{ route('keti.my-class', ['tanggal' => $day->toDateString()]) }}"
                   @class([
                       'flex flex-col items-center justify-center rounded-card border px-4 py-3 text-sm transition-all',
                       'border-primary bg-primary text-white shadow-sm' => $day->isSameDay($date),
                       'border-border bg-surface text-ink hover:border-primary/50 hover:bg-primary-soft/40' => ! $day->isSameDay($date),
                   ])>
                    <span class="font-semibold text-sm">{{ Wita::dayName($day) }}</span>
                    <span @class(['text-xs mt-0.5', 'text-white/80' => $day->isSameDay($date), 'text-muted' => ! $day->isSameDay($date)])>
                        {{ Wita::date($day) }}
                    </span>
                </a>
            @endforeach
        </nav>

        @if ($meetings->isEmpty() && $replacements->isEmpty())
            <x-empty-state title="Belum ada jadwal" :message="! in_array($date->dayOfWeekIso, config('sicma.scheduler.days'), true) ? 'Perkuliahan berlangsung Senin–Jumat.' : 'Tidak ada jadwal kelas pada tanggal yang dipilih.'" icon="calendar">
                <x-button variant="soft" :href="route('keti.search')">Cari ruangan kosong</x-button>
            </x-empty-state>
        @else
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold">{{ $meetings->count() + $replacements->count() }} jadwal</h2>
                <span class="text-xs text-muted">{{ Wita::longDate($date) }}</span>
            </div>
            <div class="grid grid-cols-2 gap-4">
                @foreach ($meetings as $meeting)
                    @php
                        $session = $meeting['session'];
                        [$badge, $variant] = match ($meeting['status']) {
                            'online' => ['Online', 'primary'],
                            'moved' => ['Dipindahkan', 'neutral'],
                            'finished' => ['Selesai', 'neutral'],
                            'ongoing' => ['Sedang berlangsung', 'success'],
                            default => ['Akan datang', 'primary'],
                        };
                        $replacement = $meeting['reschedule']?->reservation;
                    @endphp
                    <x-card :class="'flex flex-col gap-3'.(in_array($meeting['status'], ['moved', 'finished'], true) ? ' opacity-70' : '')">
                        <div class="flex items-start justify-between">
                            <div class="flex flex-col gap-1">
                                <h3 class="text-xl font-medium">{{ $session->room?->name ?? 'Kuliah online' }}</h3>
                                <p class="text-sm font-semibold text-primary">{{ $session->title }}</p>
                            </div>
                            <x-status-badge :variant="$variant">{{ $badge }}</x-status-badge>
                        </div>
                        <p class="text-sm text-ink">{{ Wita::minutes($session->start_minute) }}–{{ Wita::minutes($session->end_minute) }} WITA</p>
                        @if ($replacement)
                            <p class="text-sm font-medium text-ink">ke {{ Wita::dayName($replacement->start_at) }}, {{ Wita::longDate($replacement->start_at) }} • {{ Wita::clock($replacement->start_at, false) }} • {{ $replacement->room->name }}</p>
                        @endif
                        <p class="text-xs text-muted">{{ $session->room ? ($session->room->building?->name ?? 'Gedung belum didata').' • Lantai '.($session->room->floor ?? '—') : 'Tanpa ruang fisik' }}</p>
                        <p class="text-xs text-muted">Pengajar: {{ $session->lecturers->pluck('name')->implode(', ') ?: 'Perlu verifikasi' }}</p>
                        @if ($session->participants->count() > 1)
                            <p class="text-xs text-muted">Kelas gabungan: {{ $session->participants->pluck('name')->implode(' + ') }}</p>
                        @endif
                        <div class="flex justify-end gap-2">
                            @if ($replacement)
                                <x-button variant="soft" :href="route('keti.reservations.show', $replacement)">Lihat pengganti</x-button>
                            @elseif ($meeting['status'] === 'upcoming' && $session->blocksRoom())
                                <x-button variant="secondary" :href="route('keti.meetings.reschedule', ['sesi' => $session->id, 'tanggal' => $date->toDateString()])">Pindahkan</x-button>
                            @endif
                            @if ($session->room && ! $replacement)
                                <x-button variant="soft" :href="route('keti.rooms.show', $session->room)">Detail ruangan</x-button>
                            @endif
                        </div>
                    </x-card>
                @endforeach
                @foreach ($replacements as $reservation)
                    <x-card class="flex flex-col gap-3">
                        <div class="flex items-start justify-between">
                            <div class="flex flex-col gap-1">
                                <h3 class="text-xl font-medium">{{ $reservation->room->name }}</h3>
                                <p class="text-sm font-semibold text-primary">Kuliah Pengganti • {{ $reservation->sessionReschedule->session_title }}</p>
                            </div>
                            <x-status-badge :variant="$reservation->status->badgeVariant()">{{ $reservation->status->label() }}</x-status-badge>
                        </div>
                        <p class="text-sm text-ink">{{ Wita::range($reservation->start_at, $reservation->end_at) }}</p>
                        <p class="text-xs text-muted">Dipindah dari {{ Wita::dayName($reservation->sessionReschedule->original_start_at) }}, {{ Wita::longDate($reservation->sessionReschedule->original_start_at) }} • {{ Wita::clock($reservation->sessionReschedule->original_start_at, false) }}</p>
                        <x-button variant="soft" :href="route('keti.reservations.show', $reservation)" class="self-end">Lihat pengganti</x-button>
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
