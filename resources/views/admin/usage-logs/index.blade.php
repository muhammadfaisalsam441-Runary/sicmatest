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

    <div class="flex flex-col gap-6">
        <x-page-header title="Siapa yang memakai ruang" subtitle="Aktivitas tercatat dari tombol Mulai gunakan dan Selesai menggunakan oleh ketua tingkat.">
            <x-slot:actions>
                <x-button variant="secondary" icon="download" :href="route('admin.usage-logs.export', request()->query())">Unduh log</x-button>
            </x-slot:actions>
        </x-page-header>

        {{-- Live Banner: Ruang yang sedang digunakan saat ini --}}
        @if ($activeLogs->isNotEmpty())
            <div class="rounded-card border border-danger/40 bg-danger-soft/20 p-4.5 shadow-xs">
                <div class="flex items-center justify-between gap-3 mb-3">
                    <div class="flex items-center gap-2">
                        <span class="relative flex size-2.5">
                            <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-danger opacity-75"></span>
                            <span class="relative inline-flex size-2.5 rounded-full bg-danger"></span>
                        </span>
                        <span class="text-xs font-bold uppercase tracking-wider text-danger">Ruangan Sedang Digunakan Saat Ini ({{ $activeLogs->count() }})</span>
                    </div>
                    <span class="text-xs text-muted">Waktu WITA • Pemantauan langsung</span>
                </div>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($activeLogs as $active)
                        <div class="flex items-start justify-between rounded-control border border-border bg-surface p-3.5 shadow-xs">
                            <div class="flex flex-col">
                                <div class="flex items-center gap-2">
                                    <span class="font-bold text-ink text-base">{{ $active->room->name }}</span>
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
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-card border border-border bg-surface px-5 py-3.5 text-xs text-muted shadow-xs">
                <div class="flex flex-wrap items-center gap-2">
                    <i data-lucide="info" class="size-4 text-primary shrink-0"></i>
                    <span>Tidak ada ruangan yang sedang aktif digunakan saat ini. Ruang terakhir dipakai:</span>
                    @foreach ($lastUsed->take(2) as $item)
                        <span class="rounded bg-page px-2 py-0.5 font-medium text-ink border border-border">
                            {{ $item['room']->name }} ({{ $item['last']->classGroup->name }}) • Selesai {{ $item['last']->actual_end_at ? Wita::clock($item['last']->actual_end_at, false) : '—' }}
                        </span>
                    @endforeach
                </div>
                <span class="text-[11px] font-medium text-muted">{{ Wita::longDate($date) }}</span>
            </div>
        @endif

        <x-card class="flex flex-col gap-4">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border pb-4">
                {{-- Quick Date Navigation --}}
                <div class="flex items-center gap-2">
                    <a href="{{ route('admin.usage-logs.index', array_merge(request()->query(), ['tanggal' => $date->subDay()->toDateString()])) }}"
                       class="inline-flex items-center gap-1 rounded-control border border-border bg-surface px-2.5 py-1.5 text-xs font-medium text-muted hover:text-ink hover:bg-page transition cursor-pointer"
                       title="Lihat hari sebelumnya">
                        <i data-lucide="chevron-left" class="size-3.5"></i>
                        <span>Kemarin</span>
                    </a>
                    <a href="{{ route('admin.usage-logs.index', array_merge(request()->query(), ['tanggal' => now()->toDateString()])) }}"
                       @class([
                           'inline-flex items-center rounded-control px-3 py-1.5 text-xs font-semibold transition cursor-pointer',
                           'bg-primary text-white' => $date->isToday(),
                           'border border-border bg-surface text-muted hover:text-ink hover:bg-page' => ! $date->isToday(),
                       ])>
                        Hari Ini
                    </a>
                    <a href="{{ route('admin.usage-logs.index', array_merge(request()->query(), ['tanggal' => $date->addDay()->toDateString()])) }}"
                       class="inline-flex items-center gap-1 rounded-control border border-border bg-surface px-2.5 py-1.5 text-xs font-medium text-muted hover:text-ink hover:bg-page transition cursor-pointer"
                       title="Lihat hari berikutnya">
                        <span>Besok</span>
                        <i data-lucide="chevron-right" class="size-3.5"></i>
                    </a>
                </div>

                <span class="text-xs font-semibold text-ink">
                    {{ Wita::longDate($date) }}
                </span>
            </div>

            <form method="GET" action="{{ route('admin.usage-logs.index') }}" class="grid grid-cols-1 items-end gap-3 sm:grid-cols-12">
                <div class="sm:col-span-4">
                    <x-field label="Ruang" name="ruang">
                        <x-select name="ruang" :options="$rooms" :value="$roomId" placeholder="Semua ruang" />
                    </x-field>
                </div>
                <div class="sm:col-span-4">
                    <x-field label="Kelas" name="kelas">
                        <x-select name="kelas" :options="$classGroups" :value="$classGroupId" placeholder="Semua kelas" />
                    </x-field>
                </div>
                <div class="sm:col-span-2">
                    <x-field label="Tanggal" name="tanggal">
                        <x-input type="date" name="tanggal" :value="$date->toDateString()" />
                    </x-field>
                </div>
                <div class="flex gap-2 sm:col-span-2">
                    <x-button type="submit" class="flex-1">Terapkan filter</x-button>
                    @if ($roomId || $classGroupId || ! $date->isToday())
                        <a href="{{ route('admin.usage-logs.index') }}" class="inline-flex h-[38px] items-center justify-center rounded-control border border-border bg-surface px-2.5 text-xs font-medium text-muted hover:text-ink hover:bg-page transition" title="Reset filter">
                            <i data-lucide="rotate-ccw" class="size-3.5"></i>
                        </a>
                    @endif
                </div>
            </form>

            @if ($logs->isEmpty())
                <x-empty-state title="Belum ada catatan pemakaian" message="Belum ada catatan penggunaan pada {{ Wita::longDate($date) }}." icon="clock">
                    <x-button variant="soft" :href="route('admin.usage-logs.index', ['tanggal' => $date->subDay()->toDateString()])">Lihat tanggal sebelumnya</x-button>
                </x-empty-state>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b border-border text-xs text-muted">
                            <tr>
                                <th scope="col" class="py-3 pr-4 font-medium">Peminjam & Kelas</th>
                                <th scope="col" class="py-3 pr-4 font-medium">Ruang</th>
                                <th scope="col" class="py-3 pr-4 font-medium whitespace-nowrap">Waktu Pelaksanaan (WITA)</th>
                                <th scope="col" class="py-3 pr-4 font-medium">Kegiatan</th>
                                <th scope="col" class="py-3 font-medium">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @foreach ($logs as $log)
                                <tr class="hover:bg-page/50 transition">
                                    {{-- Peminjam & Kelas --}}
                                    <td class="py-3 pr-4 whitespace-nowrap">
                                        <div class="flex flex-col">
                                            <span class="font-semibold text-ink text-sm">{{ $log->creator->name }}</span>
                                            <span class="text-xs text-muted">{{ $log->classGroup->name }}</span>
                                        </div>
                                    </td>

                                    {{-- Ruang --}}
                                    <td class="py-3 pr-4 whitespace-nowrap">
                                        <span class="inline-flex items-center rounded-md border border-border bg-page px-2 py-0.5 text-xs font-semibold text-ink">
                                            {{ $log->room->name }}
                                        </span>
                                    </td>

                                    {{-- Waktu: Rencana first, then Realisasi --}}
                                    <td class="py-3 pr-4 whitespace-nowrap">
                                        <div class="flex flex-col">
                                            <span class="text-xs text-muted">
                                                {{ Wita::clock($log->start_at, false) }}–{{ Wita::clock($log->end_at, false) }}
                                            </span>
                                            <div class="flex items-center gap-1.5 mt-0.5">
                                                <span class="text-xs font-semibold text-ink">
                                                    {{ Wita::clock($log->actual_start_at, false) }} – {{ $log->actual_end_at ? Wita::clock($log->actual_end_at, false) : 'Sekarang' }}
                                                </span>
                                                @if ($log->status === ReservationStatus::InUse)
                                                    <span class="inline-flex items-center rounded bg-danger-soft px-1 py-0.2 text-[9px] font-bold text-danger">AKTIF</span>
                                                @endif
                                            </div>
                                        </div>
                                    </td>

                                    {{-- Kegiatan --}}
                                    <td class="py-3 pr-4">
                                        <span class="text-sm text-ink">{{ $log->purpose }}</span>
                                    </td>

                                    {{-- Status --}}
                                    <td class="py-3 whitespace-nowrap">
                                        <x-status-badge :variant="$log->status === ReservationStatus::InUse ? 'danger' : 'success'">
                                            {{ $log->status->label() }}
                                        </x-status-badge>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="text-sm text-muted">{{ $logs->count() }} catatan pada {{ Wita::longDate($date) }}</p>
            @endif
        </x-card>
    </div>
@endsection
