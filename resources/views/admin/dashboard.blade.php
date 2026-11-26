@use('App\Support\Wita')
@use('App\Support\Nav')
@extends('layouts.admin')

@section('title', 'Dashboard')

@section('content')
    @php
        $period = $progress['period'];
        $byBuilding = collect($summary['by_building']);
        $buildingHint = $byBuilding->except('Gedung belum didata')->map(fn ($count, $name) => "{$count} {$name}")->values()
            ->when($byBuilding->has('Gedung belum didata'), fn ($parts) => $parts->push($byBuilding['Gedung belum didata'].' ruang lainnya'))
            ->implode(', ');
    @endphp

    <div class="flex flex-col gap-4 admin-page-stack">
        <x-page-header title="Ruang JTIK hari ini"
                       :subtitle="Wita::dayName($now).', '.Wita::date($now).($period ? ' — '.$period->name : '').' ('.Wita::clock($now).')'">
            <x-slot:actions>
                <x-button size="sm" variant="soft" :href="Nav::url('admin.imports.create')">Impor jadwal</x-button>
                <x-button size="sm" variant="secondary" :href="route('admin.rooms.index', ['tambah' => 1])">Tambah ruang</x-button>
                <x-button size="sm" :href="Nav::url('admin.scheduler.create')">Susun jadwal</x-button>
            </x-slot:actions>
        </x-page-header>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <x-stat-card label="Total ruang" :value="$summary['all']" :hint="$buildingHint" />
            <x-stat-card label="Tersedia sekarang" :value="$summary['available']" tone="success" />
            <x-stat-card label="Digunakan sekarang" :value="$summary['busy']" tone="danger" />
        </div>

        @if ($notStarted->isNotEmpty() || $overrunning->isNotEmpty())
            <x-card padding="p-4" class="flex flex-col gap-2 border-danger/40">
                <h2 class="flex items-center gap-2 text-base font-semibold text-danger">
                    <i data-lucide="triangle-alert" class="size-4 shrink-0" aria-hidden="true"></i>
                    <span>Perlu perhatian</span>
                </h2>
                @foreach ($notStarted as $reservation)
                    <p class="text-sm"><span class="font-semibold">{{ $reservation->room->name }}</span> • {{ $reservation->classGroup->name }} belum menekan Mulai gunakan ({{ Wita::range($reservation->start_at, $reservation->end_at) }}). Status tidak diubah otomatis.</p>
                @endforeach
                @foreach ($overrunning as $reservation)
                    <p class="text-sm"><span class="font-semibold">{{ $reservation->room->name }}</span> • {{ $reservation->classGroup->name }} masih menggunakan ruang melewati batas {{ Wita::clock($reservation->end_at) }}.</p>
                @endforeach
                <a href="{{ route('admin.reservations.index', ['status' => 'akan-datang']) }}" class="self-start text-xs font-semibold text-primary hover:underline">Kelola Reservasi Aktif &amp; Mendatang</a>
            </x-card>
        @endif

        <x-card padding="p-4" x-data="filterDrawer('dashboard-schedule-progress', false)" class="flex flex-col gap-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex min-w-0 flex-wrap items-center gap-2">
                    <h2 class="text-base font-semibold">Penyusunan jadwal {{ $period?->name ?? 'semester aktif' }}</h2>
                    <span x-show="!open" class="inline-flex max-w-full flex-wrap items-center gap-x-2 gap-y-0.5 rounded-2xl border border-border bg-page px-2.5 py-0.5 text-xs font-medium text-muted sm:rounded-full">
                        @if ($progress['official'])
                            <span class="font-semibold text-success">Resmi v{{ $progress['official']->version_no }}</span>
                        @else
                            <span>Belum ada jadwal resmi</span>
                        @endif
                        <span class="text-border" aria-hidden="true">|</span>
                        @if ($progress['draft'])
                            <span class="font-semibold text-primary">Draf v{{ $progress['draft']->version_no }}</span>
                        @else
                            <span>Tanpa draf</span>
                        @endif
                        @if ($progress['blocking_count'] > 0)
                            <span class="text-border" aria-hidden="true">|</span>
                            <span class="font-semibold text-danger">{{ $progress['blocking_count'] }} masalah wajib</span>
                        @endif
                    </span>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <a href="{{ Nav::url('admin.scheduler.create') }}" class="text-xs font-semibold text-primary hover:underline">Buka Susun Jadwal</a>
                    <button type="button"
                            @click="toggle()"
                            :aria-expanded="open.toString()"
                            aria-controls="dashboard-schedule-progress-panel"
                            class="inline-flex cursor-pointer items-center gap-1.5 rounded-control border border-border bg-page px-2.5 py-1 text-xs font-semibold text-ink transition hover:bg-border focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                        <span x-text="open ? 'Tutup informasi' : 'Tampilkan informasi'">Tampilkan informasi</span>
                        <i data-lucide="chevron-down" class="size-3.5 text-muted transition-transform duration-200" :class="open ? 'rotate-180' : ''" aria-hidden="true"></i>
                    </button>
                </div>
            </div>
            <div id="dashboard-schedule-progress-panel" x-show="open" x-cloak class="grid grid-cols-1 gap-3 border-t border-border pt-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="flex flex-col gap-0.5 rounded-card border border-border px-4 py-3">
                    <span class="text-xs text-muted">Jadwal resmi</span>
                    @if ($progress['official'])
                        <span class="text-base font-semibold text-success">Versi {{ $progress['official']->version_no }}</span>
                        <span class="text-xs text-muted">Dipakai aplikasi sejak {{ Wita::date($progress['official']->published_at ?? $progress['official']->effective_from) }}</span>
                    @else
                        <span class="text-base font-semibold text-muted">Belum ada</span>
                        <span class="text-xs text-muted">Status ruang belum pasti</span>
                    @endif
                </div>
                <div class="flex flex-col gap-0.5 rounded-card border border-border px-4 py-3">
                    <span class="text-xs text-muted">Draf</span>
                    @if ($progress['draft'])
                        <span class="text-base font-semibold text-primary">Versi {{ $progress['draft']->version_no }} belum ditetapkan</span>
                        <span class="text-xs text-muted">{{ $progress['draft']->source->label() }} {{ Wita::stamp($progress['draft']->updated_at) }}</span>
                    @else
                        <span class="text-base font-semibold text-muted">Tidak ada draf</span>
                        <span class="text-xs text-muted">Semua perubahan sudah ditetapkan</span>
                    @endif
                </div>
                <div class="flex flex-col gap-0.5 rounded-card border border-border px-4 py-3">
                    <span class="text-xs text-muted">Masalah wajib</span>
                    @if ($progress['blocking_count'] > 0)
                        <span class="text-base font-semibold text-danger">{{ $progress['blocking_count'] }} perlu diselesaikan</span>
                        <span class="text-xs text-muted">{{ $progress['blocking_summary'] }}</span>
                    @else
                        <span class="text-base font-semibold text-success">Tidak ada</span>
                        <span class="text-xs text-muted">Draf siap diperiksa akhir</span>
                    @endif
                </div>
                <div class="flex flex-col gap-0.5 rounded-card border border-border px-4 py-3">
                    <span class="text-xs text-muted">Data dosen</span>
                    <span class="text-base font-semibold text-primary">{{ $progress['unverified_lecturers'] }} perlu verifikasi</span>
                    <span class="text-xs text-muted">Belum bisa dipilih sebagai pengajar</span>
                </div>
            </div>
        </x-card>

        <x-card padding="p-4" class="admin-table-card flex flex-col gap-3">
            <x-table-scroll max-height="max-h-[420px]">
                <x-slot:title>Status ruang saat ini ({{ $summary['available'] }} tersedia, {{ $summary['busy'] }} digunakan)</x-slot:title>
                <x-slot:headerActions>
                    <x-table-density-toggle />
                    <a href="{{ route('admin.usage-logs.index') }}" class="text-xs font-semibold text-primary hover:underline">Lihat log lengkap</a>
                </x-slot:headerActions>
                <table class="w-full text-left text-sm">
                    <thead class="sticky top-0 z-10 border-b border-border bg-page text-xs font-semibold text-muted">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-medium">Ruang</th>
                            <th scope="col" class="px-4 py-3 font-medium">Kelas</th>
                            <th scope="col" class="px-4 py-3 font-medium">Mata kuliah</th>
                            <th scope="col" class="px-4 py-3 font-medium">Waktu</th>
                            <th scope="col" class="px-4 py-3 font-medium">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($roomStatuses as $row)
                            <tr>
                                <td class="px-4 py-3 font-semibold">{{ $row['room'] }}</td>
                                <td class="px-4 py-3">{{ $row['class'] ?? '—' }}</td>
                                <td @class(['px-4 py-3', 'text-muted' => $row['class'] === null])>{{ $row['title'] }}</td>
                                <td class="px-4 py-3">{{ $row['until'] }}</td>
                                <td class="px-4 py-3"><x-status-badge :variant="$row['variant']">{{ $row['badge'] }}</x-status-badge></td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="py-10 text-center text-muted">Belum ada ruang. Tambahkan ruang agar status bisa dihitung.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </x-table-scroll>
        </x-card>
    </div>
@endsection
