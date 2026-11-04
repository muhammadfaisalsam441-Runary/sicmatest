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

    <div class="flex flex-col gap-6">
        <x-page-header title="Periksa hasil impor" :subtitle="$import->original_name.' • '.$import->period->name">
            <x-slot:actions>
                @if ($import->status === ImportStatus::Committed && $import->scheduleVersion)
                    <x-button :href="route('admin.drafts.show', $import->scheduleVersion)">Buka draf v{{ $import->scheduleVersion->version_no }}</x-button>
                @elseif ($import->status === ImportStatus::Checked)
                    <form method="POST" action="{{ route('admin.imports.commit', $import) }}">
                        @csrf
                        <x-button type="submit">Simpan ke draf</x-button>
                    </form>
                @endif
            </x-slot:actions>
        </x-page-header>

        <x-card>
            <x-stepper :steps="['Unggah file', 'Periksa hasil', 'Masuk draf']" :current="$import->status === ImportStatus::Committed ? 4 : 2" />
        </x-card>

        <div class="grid grid-cols-4 gap-5">
            <x-stat-card label="Baris jadwal" :value="$summary['rows'] ?? 0" :hint="($summary['skipped'] ?? 0).' baris ganda dilewati'" />
            <x-stat-card label="Ruang fisik" :value="$summary['offline'] ?? 0" tone="primary" />
            <x-stat-card label="Jadwal online" :value="$summary['online'] ?? 0" tone="success" hint="Tidak memesan ruang" />
            <x-stat-card label="Belum ada waktu" :value="$summary['unscheduled'] ?? 0" tone="danger" hint="Disimpan sebagai belum terjadwal" />
        </div>

        <x-info-box :title="($summary['errors'] ?? 0) > 0 ? ($summary['errors'].' baris bermasalah tidak ikut disimpan') : 'Hasil baca belum otomatis siap ditetapkan'" :variant="($summary['errors'] ?? 0) > 0 ? 'danger' : 'primary'">
            <p>Perbedaan ditampilkan sebagai temuan pemeriksaan. Sistem mempertahankan nilai sumber dan tidak memilih versi secara diam-diam. Tim pengajar, acuan waktu, dan kapasitas tetap ditinjau di draf.</p>
        </x-info-box>

        <x-card class="flex flex-col gap-4">
            <x-tabs :items="collect(['semua' => 'Semua', 'perlu-verifikasi' => 'Perlu verifikasi', 'online' => 'Online', 'tanpa-waktu' => 'Tanpa waktu'])
                ->map(fn ($label, $key) => ['label' => $label, 'href' => route('admin.imports.show', [$import, 'filter' => $key]), 'active' => $filter === $key])->values()->all()" />

            <table class="w-full text-left text-sm">
                <thead class="border-b border-border text-xs text-muted">
                    <tr>
                        <th class="py-3 pr-4 font-medium">Kelas / mata kuliah</th>
                        <th class="py-3 pr-4 font-medium">Sumber</th>
                        <th class="py-3 pr-4 font-medium">Temuan</th>
                        <th class="py-3 font-medium">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($rows as $row)
                        <tr>
                            <td class="py-3 pr-4">
                                <span class="block font-medium">{{ $row->raw['class'] ?? '—' }}</span>
                                <span class="text-xs text-muted">{{ trim(($row->raw['course_code'] ?? '').' '.($row->raw['course_name'] ?? '')) }}</span>
                            </td>
                            <td class="py-3 pr-4 text-muted">Baris {{ $row->row_no }} • {{ $row->raw['day'] ?? '' }} {{ $row->raw['time'] ?? '' }} • {{ $row->raw['room'] ?? '' }}</td>
                            <td class="py-3 pr-4">
                                @forelse ($row->issues ?? [] as $issue)
                                    <span @class(['block', 'text-danger' => $issue['level'] === 'error', 'text-ink' => $issue['level'] === 'warning', 'text-muted' => $issue['level'] === 'info'])>{{ $issue['message'] }}</span>
                                @empty
                                    <span class="text-muted">Sesuai</span>
                                @endforelse
                            </td>
                            <td class="py-3">
                                <x-status-badge :variant="match ($row->status) { ImportRowStatus::Ok => 'success', ImportRowStatus::Warning => 'warning', ImportRowStatus::Error => 'danger', ImportRowStatus::Skipped => 'neutral' }">{{ $row->status->label() }}</x-status-badge>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-10 text-center text-muted">Tidak ada baris pada filter ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
            {{ $rows->links() }}
        </x-card>
    </div>
@endsection
