@extends('layouts.admin')

@section('title', 'Pembagian Beban Otomatis')
@section('breadcrumb', 'Pembagian Mengajar|Pembagian Beban Otomatis')

@section('content')
    <div class="flex flex-col gap-5">
        <x-page-header title="Pratinjau Pembagian Beban Dosen" :subtitle="$period->name">
            <x-slot:actions>
                <x-button variant="secondary" :href="route('admin.teaching.index')">Kembali</x-button>
                <x-button variant="secondary" icon="refresh-cw" :href="route('admin.teaching.allocation.preview')" data-native>Hitung Ulang</x-button>
            </x-slot:actions>
        </x-page-header>

        <x-card padding="px-2.5 py-2" class="w-fit max-w-full">
            <x-schedule-steps :current="2" />
        </x-card>

        <x-tabs :items="[
            ['label' => 'Penugasan Dosen & SKS', 'href' => route('admin.teaching.index'), 'active' => false],
            ['label' => 'Bagi Beban Otomatis', 'href' => route('admin.teaching.allocation.preview'), 'active' => true],
            ['label' => 'Atur Fase Pertemuan (UTS/UAS)', 'href' => route('admin.teaching.phases.preview'), 'active' => false],
        ]" />

        <x-card>
            <h2 class="font-semibold text-ink">{{ count($plan['moves']) }} penugasan kelas diusulkan pindah dosen</h2>
            <p class="mt-2 text-sm font-medium text-ink">Dosen melebihi maksimum {{ collect($plan['loads'])->filter(fn ($load) => $load['max'] !== null && $load['before'] > $load['max'])->count() }} saat ini, menjadi {{ collect($plan['loads'])->filter(fn ($load) => $load['max'] !== null && $load['after'] > $load['max'])->count() }} setelah usulan diterapkan.</p>
            @if ($plan['affected_sessions'] > 0)
                <p class="mt-2 text-sm text-muted">Penerapan akan membuat draf jadwal baru dari v{{ $plan['base_version_no'] }} untuk memperbarui tim pada {{ $plan['affected_sessions'] }} sesi terkait. Tinjau bentrok jadwal pada draf tersebut sebelum menetapkannya.</p>
            @endif
            @error('fingerprint')
                <p class="mt-3 font-medium text-danger" role="alert">{{ $message }}</p>
            @enderror
            @if ($plan['moves'] !== [])
                <form method="POST" action="{{ route('admin.teaching.allocation.apply') }}" class="mt-4">
                    @csrf
                    <input type="hidden" name="fingerprint" value="{{ $plan['fingerprint'] }}">
                    <x-button type="submit" icon="check">Terapkan Pembagian Beban</x-button>
                </form>
            @else
                <p class="mt-3 text-sm text-ink">Belum ditemukan pemindahan yang memperbaiki pembagian dengan kelayakan, batas SKS, dan kunci saat ini. Periksa daftar mata kuliah serta target dosen di Data Dosen.</p>
            @endif
        </x-card>

        @if ($plan['warnings'] !== [])
            <x-card>
                <h2 class="font-semibold text-ink">Masih perlu ditinjau</h2>
                <ul class="mt-3 list-disc space-y-2 pl-5 text-sm text-muted">
                    @foreach ($plan['warnings'] as $warning)
                        <li>{{ $warning }}</li>
                    @endforeach
                </ul>
                <x-button variant="ghost" :href="route('admin.lecturers.index')">Buka Data Dosen</x-button>
            </x-card>
        @endif

        @if ($plan['moves'] !== [])
            <x-card>
                <h2 class="mb-3 font-semibold text-ink">Usulan perpindahan penugasan</h2>
                <x-table-scroll>
                    <table class="w-full text-left text-sm">
                        <thead class="sticky top-0 z-10 border-b border-border bg-page text-xs font-semibold text-muted">
                            <tr><th scope="col" class="px-3 py-2">Mata kuliah dan kelas</th><th scope="col" class="px-3 py-2">Peran</th><th scope="col" class="px-3 py-2">SKS</th><th scope="col" class="px-3 py-2">Dosen saat ini</th><th scope="col" class="px-3 py-2">Dosen penerima</th></tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @foreach ($plan['moves'] as $move)
                                <tr>
                                    <td class="px-3 py-3">{{ $move['label'] }}</td><td class="px-3 py-3">{{ $move['role'] }}</td>
                                    <td class="px-3 py-3">{{ $move['sks'] }}</td><td class="px-3 py-3">{{ $move['from_name'] }}</td><td class="px-3 py-3 font-semibold text-primary">{{ $move['to_name'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-table-scroll>
            </x-card>
        @endif

        <x-card>
            <h2 class="mb-3 font-semibold text-ink">Perbandingan SKS Semester</h2>
            <x-table-scroll>
                <table class="w-full text-left text-sm">
                    <thead class="sticky top-0 z-10 border-b border-border bg-page text-xs font-semibold text-muted">
                        <tr><th scope="col" class="px-3 py-2">Dosen</th><th scope="col" class="px-3 py-2">Saat ini</th><th scope="col" class="px-3 py-2">Setelah diterapkan</th><th scope="col" class="px-3 py-2">Target</th><th scope="col" class="px-3 py-2">Maksimum</th><th scope="col" class="px-3 py-2">Status</th></tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @foreach (collect($plan['loads'])->sortBy('name') as $load)
                            @php
                                $overMaxAfter = $load['max'] !== null && $load['after'] > $load['max'];
                                $changed = (float) $load['before'] !== (float) $load['after'];
                            @endphp
                            <tr @class([
                                'bg-danger-soft/40' => $overMaxAfter,
                                'bg-primary-soft/25' => ! $overMaxAfter && $changed,
                            ])>
                                <td class="px-3 py-3 font-medium text-ink">{{ $load['name'] }}</td>
                                <td class="px-3 py-3">{{ $load['before'] }}</td>
                                <td @class(['px-3 py-3 font-semibold', 'text-danger' => $overMaxAfter, 'text-primary' => ! $overMaxAfter && $changed])>{{ $load['after'] }}</td>
                                <td class="px-3 py-3">{{ $load['target'] ?? 'Belum diisi' }}</td>
                                <td class="px-3 py-3">{{ $load['max'] ?? 'Belum diisi' }}</td>
                                <td class="px-3 py-3">
                                    @if ($overMaxAfter)
                                        <x-status-badge variant="danger">Melebihi maks.</x-status-badge>
                                    @elseif ($changed)
                                        <x-status-badge variant="primary">Berubah</x-status-badge>
                                    @else
                                        <x-status-badge variant="neutral">Tetap</x-status-badge>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-table-scroll>
        </x-card>
    </div>
@endsection
