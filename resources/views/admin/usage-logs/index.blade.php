@use('App\Enums\ReservationStatus')
@use('App\Support\Wita')
@extends('layouts.admin')

@section('title', 'Log Pemakaian')

@section('breadcrumb-actions')
    <x-button variant="secondary" :href="route('admin.dashboard')">Dashboard</x-button>
@endsection

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header title="Siapa yang memakai ruang" subtitle="Aktivitas tercatat dari tombol Mulai gunakan dan Selesai menggunakan oleh ketua tingkat.">
            <x-slot:actions>
                <x-button variant="secondary" icon="download" :href="route('admin.usage-logs.export', request()->query())">Unduh log</x-button>
            </x-slot:actions>
        </x-page-header>

        @if ($lastUsed->isNotEmpty())
            <div class="grid grid-cols-3 gap-5">
                @foreach ($lastUsed as $item)
                    <x-card class="flex flex-col gap-1">
                        <span class="text-lg font-semibold">{{ $item['room']->name }}</span>
                        <span class="text-xs text-muted">Terakhir dipakai</span>
                        <span class="text-sm">{{ $item['last']->creator->name }} • {{ $item['last']->classGroup->name }}</span>
                        <span class="text-sm text-muted">Mulai {{ Wita::clock($item['last']->actual_start_at, false) }} • {{ $item['last']->actual_end_at ? 'Selesai '.Wita::clock($item['last']->actual_end_at) : 'Masih digunakan' }}</span>
                    </x-card>
                @endforeach
            </div>
        @endif

        <x-card class="flex flex-col gap-4">
            <form method="GET" action="{{ route('admin.usage-logs.index') }}" class="grid grid-cols-[1fr_1fr_1fr_auto] items-end gap-3">
                <x-field label="Ruang" name="ruang">
                    <x-select name="ruang" :options="$rooms" :value="$roomId" placeholder="Semua ruang" />
                </x-field>
                <x-field label="Kelas" name="kelas">
                    <x-select name="kelas" :options="$classGroups" :value="$classGroupId" placeholder="Semua kelas" />
                </x-field>
                <x-field label="Tanggal" name="tanggal">
                    <x-input type="date" name="tanggal" :value="$date->toDateString()" />
                </x-field>
                <x-button type="submit">Terapkan filter</x-button>
            </form>

            @if ($logs->isEmpty())
                <x-empty-state title="Belum ada catatan pemakaian" message="Belum ada catatan penggunaan pada {{ Wita::longDate($date) }}." icon="clock">
                    <x-button variant="soft" :href="route('admin.usage-logs.index', ['tanggal' => $date->subDay()->toDateString()])">Lihat tanggal sebelumnya</x-button>
                </x-empty-state>
            @else
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-border text-xs text-muted">
                        <tr>
                            <th scope="col" class="py-3 pr-4 font-medium">Pengguna</th>
                            <th scope="col" class="py-3 pr-4 font-medium">Kelas</th>
                            <th scope="col" class="py-3 pr-4 font-medium">Ruang</th>
                            <th scope="col" class="py-3 pr-4 font-medium">Rencana (WITA)</th>
                            <th scope="col" class="py-3 pr-4 font-medium">Mulai (WITA)</th>
                            <th scope="col" class="py-3 pr-4 font-medium">Selesai (WITA)</th>
                            <th scope="col" class="py-3 pr-4 font-medium">Kegiatan</th>
                            <th scope="col" class="py-3 font-medium">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @foreach ($logs as $log)
                            <tr>
                                <td class="py-3 pr-4 font-semibold">{{ $log->creator->name }}</td>
                                <td class="py-3 pr-4">{{ $log->classGroup->name }}</td>
                                <td class="py-3 pr-4">{{ $log->room->name }}</td>
                                <td class="py-3 pr-4 text-muted">{{ Wita::clock($log->start_at, false) }}–{{ Wita::clock($log->end_at, false) }}</td>
                                <td class="py-3 pr-4">{{ Wita::clock($log->actual_start_at, false) }}</td>
                                <td class="py-3 pr-4">{{ $log->actual_end_at ? Wita::clock($log->actual_end_at, false) : '—' }}</td>
                                <td class="py-3 pr-4 text-muted">{{ $log->purpose }}</td>
                                <td class="py-3">
                                    <x-status-badge :variant="$log->status === ReservationStatus::InUse ? 'danger' : 'success'">{{ $log->status->label() }}</x-status-badge>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <p class="text-sm text-muted">{{ $logs->count() }} catatan pada {{ Wita::longDate($date) }}</p>
            @endif
        </x-card>
    </div>
@endsection
