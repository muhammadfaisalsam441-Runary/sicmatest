@use('App\Enums\ReservationStatus')
@use('App\Support\Wita')
@extends('layouts.admin')

@section('title', 'Log Pemakaian')

@section('breadcrumb-actions')
    <x-button variant="secondary" :href="route('admin.dashboard')">Dashboard</x-button>
@endsection

@section('content')
    @php
        $activeLogs = $logs->where('status', ReservationStatus::InUse);
    @endphp

    <div class="flex flex-col gap-4 admin-page-stack">
        <x-page-header title="Log pemakaian">
            <x-slot:actions>
                <x-button size="sm" variant="secondary" icon="download" :href="route('admin.usage-logs.export', request()->query())">Unduh log</x-button>
            </x-slot:actions>
        </x-page-header>

        {{-- Live Banner: Ruang yang sedang digunakan saat ini --}}
        @if ($activeLogs->isNotEmpty())
            <div x-data="filterDrawer('usage-active-rooms', false)"
                 class="rounded-card border border-danger/40 bg-danger-soft/20 px-4 py-2.5 shadow-xs">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="flex flex-wrap items-center gap-2 text-xs">
                        <span class="relative flex size-2.5 shrink-0">
                            <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-danger opacity-75"></span>
                            <span class="relative inline-flex size-2.5 rounded-full bg-danger"></span>
                        </span>
                        <span class="font-bold uppercase tracking-wider text-danger">Ruangan Sedang Digunakan ({{ $activeLogs->count() }})</span>
                        <span class="text-muted">•</span>
                        <span class="font-medium text-ink">{{ $activeLogs->map(fn ($a) => $a->room->name.' ('.$a->classGroup->name.')')->implode(', ') }}</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="hidden sm:inline text-[11px] text-muted">Waktu WITA</span>
                        <button type="button" @click="toggle()" :aria-expanded="open"
                                class="inline-flex items-center gap-1 rounded-control border border-danger/30 bg-surface px-2.5 py-1 text-xs font-medium text-danger hover:bg-danger-soft/40 transition cursor-pointer">
                            <span>Detail ruang aktif</span>
                            <i data-lucide="chevron-down" class="size-3.5 transition-transform" :class="open ? 'rotate-180' : ''" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>
                <div x-show="open" x-cloak class="mt-2.5 grid grid-cols-1 gap-2.5 sm:grid-cols-2 lg:grid-cols-3 border-t border-danger/20 pt-2.5">
                    @foreach ($activeLogs as $active)
                        <div class="flex items-start justify-between rounded-control border border-border bg-surface p-3 shadow-xs">
                            <div class="flex flex-col">
                                <div class="flex items-center gap-2">
                                    <span class="font-bold text-ink text-sm">{{ $active->room->name }}</span>
                                    <span class="rounded bg-danger-soft px-1.5 py-0.5 text-[10px] font-bold text-danger">Aktif</span>
                                </div>
                                <span class="text-xs font-semibold text-ink mt-1">{{ $active->creator->name }}</span>
                                <span class="text-[11px] text-muted">{{ $active->classGroup->name }} • {{ $active->purpose }}</span>
                            </div>
                            <div class="text-right">
                                <span class="text-xs font-bold text-danger">Mulai {{ Wita::clock($active->actual_start_at, false) }}</span>
                                <span class="block text-[10px] text-muted">Rencana s/d {{ Wita::clock($active->end_at, false) }}</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @elseif ($lastUsed->isNotEmpty())
            <div x-data="filterDrawer('usage-last-used', false)"
                 class="rounded-card border border-border bg-surface px-4 py-2 text-xs text-muted shadow-xs">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="flex flex-wrap items-center gap-2">
                        <i data-lucide="info" class="size-4 text-primary shrink-0" aria-hidden="true"></i>
                        <span>Tidak ada ruang aktif sekarang • Terakhir dipakai: <span class="font-medium text-ink">{{ $lastUsed->take(2)->map(fn ($item) => $item['room']->name)->implode(', ') }}</span></span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="text-[11px] font-medium text-muted">{{ Wita::longDate($date) }}</span>
                        <button type="button" @click="toggle()" :aria-expanded="open"
                                class="inline-flex items-center gap-1 rounded-control border border-border bg-page px-2 py-0.5 text-[11px] font-medium text-ink hover:bg-border transition cursor-pointer">
                            <span>Ringkasan</span>
                            <i data-lucide="chevron-down" class="size-3 transition-transform" :class="open ? 'rotate-180' : ''" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>
                <div x-show="open" x-cloak class="mt-2 flex flex-wrap items-center gap-2 border-t border-border pt-2">
                    @foreach ($lastUsed->take(2) as $item)
                        <span class="rounded bg-page px-2 py-0.5 font-medium text-ink border border-border">
                            {{ $item['room']->name }} ({{ $item['last']->classGroup->name }}) • Selesai {{ $item['last']->actual_end_at ? Wita::clock($item['last']->actual_end_at, false) : '—' }}
                        </span>
                    @endforeach
                </div>
            </div>
        @endif

        <x-card padding="p-4" class="admin-table-card flex flex-col gap-3">
            <form method="GET" action="{{ route('admin.usage-logs.index') }}" class="flex flex-col gap-2.5">
                <div class="flex flex-wrap items-center gap-2">
                    <select id="usage-room-filter" name="ruang" aria-label="Filter ruang"
                            class="w-full sm:w-48 rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary shadow-2xs">
                        <option value="" @selected($roomId === null || $roomId === '')>Semua ruang</option>
                        @foreach ($rooms as $optionValue => $optionLabel)
                            <option value="{{ $optionValue }}" @selected($roomId !== null && (string) $roomId === (string) $optionValue)>{{ $optionLabel }}</option>
                        @endforeach
                    </select>

                    <select id="usage-class-filter" name="kelas" aria-label="Filter kelas"
                            class="w-full sm:w-48 rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary shadow-2xs">
                        <option value="" @selected($classGroupId === null || $classGroupId === '')>Semua kelas</option>
                        @foreach ($classGroups as $optionValue => $optionLabel)
                            <option value="{{ $optionValue }}" @selected($classGroupId !== null && (string) $classGroupId === (string) $optionValue)>{{ $optionLabel }}</option>
                        @endforeach
                    </select>

                    <input id="usage-date-filter" type="date" name="tanggal" value="{{ $date->toDateString() }}"
                           aria-label="Tanggal log" @change="$el.form.requestSubmit()"
                           class="w-full sm:w-44 rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary shadow-2xs">

                    <div class="flex items-center gap-2">
                        <x-button type="submit" size="sm">Terapkan</x-button>
                        @if ($roomId || $classGroupId || ! $date->isToday())
                            <x-button size="sm" variant="secondary" icon="rotate-ccw" :href="route('admin.usage-logs.index')" aria-label="Reset semua filter">Reset</x-button>
                        @endif
                    </div>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="flex flex-wrap items-center gap-1.5">
                        <a href="{{ route('admin.usage-logs.index', array_merge(request()->query(), ['tanggal' => $date->subDay()->toDateString()])) }}"
                           class="inline-flex items-center gap-1 rounded-full border border-border bg-surface px-3 py-1 text-xs font-medium text-muted hover:text-ink hover:bg-page transition cursor-pointer"
                           title="Lihat hari sebelumnya">
                            <i data-lucide="arrow-left" class="size-3.5" aria-hidden="true"></i>
                            <span>Kemarin</span>
                        </a>
                        <a href="{{ route('admin.usage-logs.index', array_merge(request()->query(), ['tanggal' => now()->toDateString()])) }}"
                           @class([
                               'inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold transition cursor-pointer',
                               'bg-primary text-white' => $date->isToday(),
                               'border border-border bg-surface text-muted hover:text-ink hover:bg-page' => ! $date->isToday(),
                           ])>
                            Hari Ini
                        </a>
                        <a href="{{ route('admin.usage-logs.index', array_merge(request()->query(), ['tanggal' => $date->addDay()->toDateString()])) }}"
                           class="inline-flex items-center gap-1 rounded-full border border-border bg-surface px-3 py-1 text-xs font-medium text-muted hover:text-ink hover:bg-page transition cursor-pointer"
                           title="Lihat hari berikutnya">
                            <span>Besok</span>
                            <i data-lucide="arrow-right" class="size-3.5" aria-hidden="true"></i>
                        </a>
                    </div>

                    <span class="text-xs font-semibold text-ink">
                        {{ Wita::longDate($date) }}
                    </span>
                </div>
            </form>

            @if ($logs->isEmpty())
                <x-empty-state title="Belum ada catatan pemakaian" message="Belum ada catatan penggunaan pada {{ Wita::longDate($date) }}." icon="clock">
                    <x-button variant="soft" :href="route('admin.usage-logs.index', ['tanggal' => $date->subDay()->toDateString()])">Lihat tanggal sebelumnya</x-button>
                </x-empty-state>
            @else
                <x-table-scroll>
                    <x-slot:title>{{ $logs->count() }} catatan pada {{ Wita::longDate($date) }}</x-slot:title>
                    <x-slot:headerActions>
                        <x-table-density-toggle />
                    </x-slot:headerActions>
                    <table class="w-full text-left text-sm">
                        <thead class="sticky top-0 z-10 border-b border-border bg-page text-xs font-semibold text-muted">
                            <tr>
                                <th scope="col" class="px-4 py-3 font-medium">Peminjam & Kelas</th>
                                <th scope="col" class="px-4 py-3 font-medium">Ruang</th>
                                <th scope="col" class="px-4 py-3 font-medium whitespace-nowrap">Waktu Pelaksanaan (WITA)</th>
                                <th scope="col" class="px-4 py-3 font-medium">Kegiatan</th>
                                <th scope="col" class="px-4 py-3 font-medium">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @foreach ($logs as $log)
                                <tr class="hover:bg-page/50 transition">
                                    {{-- Peminjam & Kelas --}}
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        <div class="flex flex-col">
                                            <span class="font-semibold text-ink text-sm">{{ $log->creator->name }}</span>
                                            <span class="text-xs text-muted">{{ $log->classGroup->name }}</span>
                                        </div>
                                    </td>

                                    {{-- Ruang --}}
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        <span class="inline-flex items-center rounded-md border border-border bg-page px-2 py-0.5 text-xs font-semibold text-ink">
                                            {{ $log->room->name }}
                                        </span>
                                    </td>

                                    {{-- Waktu: Rencana first, then Realisasi --}}
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        <div class="flex flex-col gap-0.5">
                                            <span class="text-xs text-muted">
                                                Rencana: {{ Wita::clock($log->start_at, false) }}–{{ Wita::clock($log->end_at, false) }}
                                            </span>
                                            <div class="flex items-center gap-1.5">
                                                <span class="text-xs font-semibold text-ink">
                                                    Realisasi: {{ Wita::clock($log->actual_start_at, false) }}–{{ $log->actual_end_at ? Wita::clock($log->actual_end_at, false) : 'Sekarang' }}
                                                </span>
                                                @if ($log->status === ReservationStatus::InUse)
                                                    <span class="inline-flex items-center rounded bg-danger-soft px-1 py-0.2 text-[9px] font-bold text-danger">AKTIF</span>
                                                @endif
                                            </div>
                                        </div>
                                    </td>

                                    {{-- Kegiatan --}}
                                    <td class="px-4 py-3">
                                        <span class="text-sm text-ink">{{ $log->purpose }}</span>
                                    </td>

                                    {{-- Status --}}
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        <x-status-badge :variant="$log->status === ReservationStatus::InUse ? 'danger' : 'success'">
                                            {{ $log->status->label() }}
                                        </x-status-badge>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-table-scroll>
            @endif
        </x-card>
    </div>
@endsection
