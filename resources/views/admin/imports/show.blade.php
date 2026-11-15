@use('App\Enums\ImportRowStatus')
@use('App\Enums\ImportStatus')
@extends('layouts.admin')

@section('title', 'Impor Jadwal')
@section('breadcrumb', 'Impor Jadwal|2 Periksa hasil')

@section('breadcrumb-actions')
    <x-button variant="secondary" icon="arrow-left" :href="route('admin.imports.create')">Kembali</x-button>
@endsection

@section('content')
    @php($summary = $import->summary ?? [])

    <div class="flex flex-col gap-4 admin-page-stack">
        <x-page-header title="Periksa hasil impor" :subtitle="$import->original_name.' • '.$import->period->name">
            <x-slot:actions>
                @if ($import->status === ImportStatus::Committed && $import->scheduleVersion)
                    <x-button size="sm" :href="route('admin.drafts.show', $import->scheduleVersion)">Buka draf v{{ $import->scheduleVersion->version_no }}</x-button>
                @elseif ($import->status === ImportStatus::Checked)
                    <form method="POST" action="{{ route('admin.imports.commit', $import) }}">
                        @csrf
                        <x-button type="submit" size="sm">Simpan ke draf</x-button>
                    </form>
                @endif
            </x-slot:actions>
        </x-page-header>

        @include('admin.partials.scheduling-tabs')

        <x-card padding="px-2.5 py-1.5" class="w-fit max-w-full">
            <x-stepper :steps="['Unggah file', 'Periksa hasil', 'Masuk draf']" :current="$import->status === ImportStatus::Committed ? 3 : 2" />
        </x-card>

        <div class="grid grid-cols-2 gap-2 lg:grid-cols-4">
            <x-stat-card :compact="true" label="Baris jadwal" :value="$summary['rows'] ?? 0" :hint="($summary['skipped'] ?? 0).' baris ganda dilewati'" />
            <x-stat-card :compact="true" label="Ruang fisik" :value="$summary['offline'] ?? 0" tone="primary" />
            <x-stat-card :compact="true" label="Jadwal online" :value="$summary['online'] ?? 0" tone="success" hint="Tidak memesan ruang" />
            <x-stat-card :compact="true" label="Belum ada waktu" :value="$summary['unscheduled'] ?? 0" tone="danger" hint="Disimpan sebagai belum terjadwal" />
        </div>

        <x-alert :variant="($summary['errors'] ?? 0) > 0 ? 'danger' : 'info'"
                 :title="($summary['errors'] ?? 0) > 0 ? ($summary['errors'].' baris bermasalah tidak ikut disimpan') : 'Hasil baca belum otomatis siap ditetapkan'">
            Perbedaan ditampilkan sebagai temuan pemeriksaan; tim pengajar, acuan waktu, dan kapasitas tetap ditinjau di draf.
        </x-alert>

        <x-card padding="p-4" class="admin-table-card flex flex-col gap-3">
            <nav aria-label="Filter baris impor" class="flex flex-wrap items-center gap-1.5">
                @foreach (['semua' => 'Semua', 'perlu-verifikasi' => 'Perlu verifikasi', 'online' => 'Online', 'tanpa-waktu' => 'Tanpa waktu'] as $key => $label)
                    <a href="{{ route('admin.imports.show', [$import, 'filter' => $key]) }}"
                       @if ($filter === $key) aria-current="page" @endif
                       @class([
                           'rounded-full px-3 py-1 text-xs font-semibold transition',
                           'bg-primary text-white' => $filter === $key,
                           'bg-page text-muted hover:bg-border hover:text-ink' => $filter !== $key,
                       ])>{{ $label }}</a>
                @endforeach
            </nav>

            <x-table-scroll>
                <x-slot:title>{{ number_format($rows->total()) }} baris ditampilkan • {{ $summary['rows'] ?? 0 }} total ({{ $summary['skipped'] ?? 0 }} ganda dilewati)</x-slot:title>
                <x-slot:headerActions>
                    <x-table-density-toggle />
                </x-slot:headerActions>
                <table class="w-full text-left text-sm">
                    <thead class="sticky top-0 z-10 border-b border-border bg-page text-xs font-semibold text-muted">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-medium">Kelas / mata kuliah</th>
                            <th scope="col" class="px-4 py-3 font-medium">Sumber</th>
                            <th scope="col" class="px-4 py-3 font-medium">Temuan</th>
                            <th scope="col" class="px-4 py-3 font-medium">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($rows as $row)
                            <tr>
                                <td class="px-4 py-3">
                                    <span class="block font-medium">{{ $row->raw['class'] ?? '—' }}</span>
                                    <span class="text-xs text-muted">{{ trim(($row->raw['course_code'] ?? '').' '.($row->raw['course_name'] ?? '')) }}</span>
                                </td>
                                <td class="px-4 py-3 text-muted">Baris {{ $row->row_no }} • {{ $row->raw['day'] ?? '' }} {{ $row->raw['time'] ?? '' }} • {{ $row->raw['room'] ?? '' }}</td>
                                <td class="px-4 py-3">
                                    @forelse ($row->issues ?? [] as $issue)
                                        <span @class(['block', 'text-danger' => $issue['level'] === 'error', 'text-ink' => $issue['level'] === 'warning', 'text-muted' => $issue['level'] === 'info'])>{{ $issue['message'] }}</span>
                                    @empty
                                        <span class="text-muted">Sesuai</span>
                                    @endforelse
                                </td>
                                <td class="px-4 py-3">
                                    <x-status-badge :variant="match ($row->status) { ImportRowStatus::Ok => 'success', ImportRowStatus::Warning => 'warning', ImportRowStatus::Error => 'danger', ImportRowStatus::Skipped => 'neutral' }">{{ $row->status->label() }}</x-status-badge>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-10 text-center text-muted">Tidak ada baris pada filter ini.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </x-table-scroll>
            {{ $rows->links() }}
        </x-card>
    </div>
@endsection
