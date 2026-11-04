@use('App\Support\Wita')
@use('App\Support\Nav')
@extends('layouts.admin')

@section('title', 'Dashboard')

@section('breadcrumb-actions')
    <x-button variant="secondary" :href="Nav::url('admin.scheduler.create')">Susun Jadwal</x-button>
@endsection

@section('content')
    @php
        $period = $progress['period'];
        $byBuilding = collect($summary['by_building']);
        $buildingHint = $byBuilding->except('Gedung belum didata')->map(fn ($count, $name) => "{$count} {$name}")->values()
            ->when($byBuilding->has('Gedung belum didata'), fn ($parts) => $parts->push($byBuilding['Gedung belum didata'].' ruang lainnya'))
            ->implode(' • ');
    @endphp

    <div class="flex flex-col gap-6">
        <x-page-header title="Ruang JTIK hari ini"
                       :subtitle="Wita::dayName($now).', '.Wita::date($now).($period ? ' • Jadwal '.$period->name : '').' • Data pukul '.Wita::clock($now)">
            <x-slot:actions>
                <x-button variant="soft" :href="Nav::url('admin.imports.create')">Impor jadwal</x-button>
                <x-button :href="route('admin.rooms.index', ['tambah' => 1])">Tambah ruang</x-button>
                <x-button :href="Nav::url('admin.scheduler.create')">Susun jadwal</x-button>
            </x-slot:actions>
        </x-page-header>

        <div class="grid grid-cols-3 gap-5">
            <x-stat-card label="Total ruang" :value="$summary['all']" :hint="$buildingHint" />
            <x-stat-card label="Tersedia sekarang" :value="$summary['available']" tone="success" hint="Tersedia pada data simulasi" />
            <x-stat-card label="Digunakan sekarang" :value="$summary['busy']" tone="danger" hint="Sedang digunakan pada data simulasi" />
        </div>

        @if ($notStarted->isNotEmpty() || $overrunning->isNotEmpty())
            <x-card class="flex flex-col gap-3 border-danger/40">
                <h2 class="text-lg font-semibold text-danger">Perlu perhatian</h2>
                @foreach ($notStarted as $reservation)
                    <p class="text-sm"><span class="font-semibold">{{ $reservation->room->name }}</span> • {{ $reservation->classGroup->name }} belum menekan Mulai gunakan ({{ Wita::range($reservation->start_at, $reservation->end_at) }}). Status tidak diubah otomatis.</p>
                @endforeach
                @foreach ($overrunning as $reservation)
                    <p class="text-sm"><span class="font-semibold">{{ $reservation->room->name }}</span> • {{ $reservation->classGroup->name }} masih menggunakan ruang melewati batas {{ Wita::clock($reservation->end_at) }}.</p>
                @endforeach
                <a href="{{ route('admin.reservations.index', ['status' => 'akan-datang']) }}" class="text-sm font-semibold text-primary hover:underline">Buka Riwayat Reservasi</a>
            </x-card>
        @endif

        <x-card class="flex flex-col gap-5">
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold">Penyusunan jadwal {{ $period?->name ?? 'semester aktif' }}</h2>
                <a href="{{ Nav::url('admin.scheduler.create') }}" class="text-sm font-semibold text-primary hover:underline">Buka Susun Jadwal</a>
            </div>
            <div class="grid grid-cols-4 gap-4">
                <div class="flex flex-col gap-1 rounded-card border border-border px-5 py-4">
                    <span class="text-xs text-muted">Jadwal resmi</span>
                    @if ($progress['official'])
                        <span class="text-lg font-semibold text-success">Versi {{ $progress['official']->version_no }}</span>
                        <span class="text-sm text-muted">Dipakai aplikasi sejak {{ Wita::date($progress['official']->published_at ?? $progress['official']->effective_from) }}</span>
                    @else
                        <span class="text-lg font-semibold text-muted">Belum ada</span>
                        <span class="text-sm text-muted">Status ruang belum pasti</span>
                    @endif
                </div>
                <div class="flex flex-col gap-1 rounded-card border border-border px-5 py-4">
                    <span class="text-xs text-muted">Draf</span>
                    @if ($progress['draft'])
                        <span class="text-lg font-semibold text-primary">Versi {{ $progress['draft']->version_no }} belum ditetapkan</span>
                        <span class="text-sm text-muted">{{ $progress['draft']->source->label() }} {{ Wita::stamp($progress['draft']->updated_at) }}</span>
                    @else
                        <span class="text-lg font-semibold text-muted">Tidak ada draf</span>
                        <span class="text-sm text-muted">Semua perubahan sudah ditetapkan</span>
                    @endif
                </div>
                <div class="flex flex-col gap-1 rounded-card border border-border px-5 py-4">
                    <span class="text-xs text-muted">Masalah wajib</span>
                    @if ($progress['blocking_count'] > 0)
                        <span class="text-lg font-semibold text-danger">{{ $progress['blocking_count'] }} perlu diselesaikan</span>
                        <span class="text-sm text-muted">{{ $progress['blocking_summary'] }}</span>
                    @else
                        <span class="text-lg font-semibold text-success">Tidak ada</span>
                        <span class="text-sm text-muted">Draf siap diperiksa akhir</span>
                    @endif
                </div>
                <div class="flex flex-col gap-1 rounded-card border border-border px-5 py-4">
                    <span class="text-xs text-muted">Data dosen</span>
                    <span class="text-lg font-semibold text-primary">{{ $progress['unverified_lecturers'] }} perlu verifikasi</span>
                    <span class="text-sm text-muted">Belum bisa dipilih sebagai pengajar</span>
                </div>
            </div>
        </x-card>

        <div class="grid grid-cols-[1fr_400px] gap-6">
            <x-card class="flex flex-col gap-4">
                <div class="flex items-center justify-between">
                    <h2 class="text-xl font-semibold">Status ruang saat ini</h2>
                    <div class="flex items-center gap-4">
                        @if (config('sicma.public_portal.show_sim_label'))
                            <x-status-badge variant="primary">Data simulasi</x-status-badge>
                        @endif
                        <a href="{{ route('admin.usage-logs.index') }}" class="text-sm font-semibold text-primary hover:underline">Lihat log lengkap</a>
                    </div>
                </div>
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-border text-xs text-muted">
                        <tr>
                            <th scope="col" class="py-3 pr-4 font-medium">Ruang</th>
                            <th scope="col" class="py-3 pr-4 font-medium">Kelas</th>
                            <th scope="col" class="py-3 pr-4 font-medium">Mata kuliah</th>
                            <th scope="col" class="py-3 pr-4 font-medium">Waktu</th>
                            <th scope="col" class="py-3 font-medium">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($roomStatuses as $row)
                            <tr>
                                <td class="py-3 pr-4 font-semibold">{{ $row['room'] }}</td>
                                <td class="py-3 pr-4">{{ $row['class'] ?? '—' }}</td>
                                <td @class(['py-3 pr-4', 'text-muted' => $row['class'] === null])>{{ $row['title'] }}</td>
                                <td class="py-3 pr-4">{{ $row['until'] }}</td>
                                <td class="py-3"><x-status-badge :variant="$row['variant']">{{ $row['badge'] }}</x-status-badge></td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="py-10 text-center text-muted">Belum ada ruang. Tambahkan ruang agar status bisa dihitung.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </x-card>

            <x-card class="flex flex-col gap-2 self-start">
                <div class="flex items-center justify-between border-b border-border pb-3">
                    <h2 class="text-xl font-semibold">Reservasi terbaru</h2>
                    <a href="{{ route('admin.reservations.index') }}" class="text-sm font-semibold text-primary hover:underline">Semua</a>
                </div>
                @forelse ($latestReservations as $reservation)
                    <div class="flex flex-col gap-1 border-b border-border py-3 last:border-0">
                        <div class="flex items-center justify-between">
                            <span class="font-semibold">{{ $reservation->room->name }}</span>
                            <span class="text-xs text-muted">{{ Wita::date($reservation->start_at) }}</span>
                        </div>
                        <span class="text-sm">{{ Wita::range($reservation->start_at, $reservation->end_at) }} • {{ $reservation->classGroup->name }}</span>
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-muted">{{ $reservation->purpose }}</span>
                            <x-status-badge :variant="match ($reservation->status->value) { 'finished' => 'success', 'cancelled' => 'neutral', 'in_use' => 'danger', default => 'primary' }">{{ $reservation->status->label() }}</x-status-badge>
                        </div>
                    </div>
                @empty
                    <p class="py-8 text-center text-sm text-muted">Belum ada reservasi.</p>
                @endforelse
            </x-card>
        </div>
    </div>
@endsection
