@use('App\Enums\IssueSeverity')
@use('App\Support\Wita')
@extends('layouts.admin')
@section('title', 'Pemeriksaan Jadwal')
@section('breadcrumb', 'Pemeriksaan Jadwal|Versi '.$version->version_no)
@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header :title="'Pemeriksaan Draf v'.$version->version_no" :subtitle="$version->period->name.' • '.$version->source->label().($version->basedOn ? ' • Dasar v'.$version->basedOn->version_no : '')">
            <x-slot:actions>
                <x-button variant="secondary" :href="route('admin.drafts.show', $version)">Kembali ke Draf</x-button>
                @if ($isDraft)
                    <form method="POST" action="{{ route('admin.drafts.validate', $version) }}">@csrf<x-button type="submit" icon="refresh-cw">Periksa Ulang</x-button></form>
                @endif
            </x-slot:actions>
        </x-page-header>
        <x-card><x-schedule-steps :current="4" :version="$version" /></x-card>

        @include('admin.partials.scheduling-tabs', [
            'version' => $version,
            'isDraft' => $isDraft,
            'reviewStatus' => $reviewStatus,
        ])
        <x-alert :variant="$reviewStatus['ready'] ? 'success' : 'info'" :title="$reviewStatus['label']">
            {{ $reviewStatus['checked_at'] ? 'Pemeriksaan terakhir: '.Wita::stamp($reviewStatus['checked_at']) : 'Jalankan pemeriksaan sebelum melanjutkan penetapan.' }}
            @if (! $reviewStatus['current'] && $reviewStatus['checked_at']) Draf berubah sejak pemeriksaan terakhir. @endif
            Hasil pemeriksaan merupakan kondisi pada waktu tersebut; sistem memeriksa ulang saat penetapan.
        </x-alert>
        <x-card class="grid gap-4 text-sm sm:grid-cols-3 lg:grid-cols-6">
            @foreach (['Total sesi' => $counts['total'], 'Terjadwal' => $counts['timed'], 'Belum lengkap' => $counts['total'] - $counts['timed'], 'Masalah wajib' => $reviewStatus['errors'], 'Peringatan' => $reviewStatus['warnings'], 'Terkunci' => $counts['locked']] as $label => $count)
                <div><p class="text-muted">{{ $label }}</p><p class="text-2xl font-semibold">{{ $count }}</p></div>
            @endforeach
        </x-card>
        <x-card class="flex flex-col gap-4">
            <h2 class="text-lg font-semibold">Temuan pemeriksaan</h2>
            <form method="GET" action="{{ route('admin.drafts.inspect', $version) }}" class="flex flex-wrap items-end gap-3">
                <input type="hidden" name="tinjau" value="{{ $view }}">
                <x-field label="Tingkat" name="tingkat"><x-select name="tingkat" :options="['error' => 'Masalah wajib', 'warning' => 'Peringatan']" :value="request('tingkat')" placeholder="Semua tingkat" /></x-field>
                <x-field label="Jenis masalah" name="jenis"><x-select name="jenis" :options="$issues->pluck('code', 'code')->sort()" :value="request('jenis')" placeholder="Semua jenis" /></x-field>
                <x-button type="submit">Terapkan</x-button>
                <x-button variant="secondary" :href="route('admin.drafts.inspect', $version)">Reset</x-button>
            </form>
            @forelse ($filteredIssues as $issue)
                <div class="flex flex-col gap-2 rounded-control border border-border p-4">
                    <div class="flex flex-wrap items-center gap-2"><x-status-badge :variant="$issue->severity === IssueSeverity::Error ? 'danger' : 'warning'">{{ $issue->severity === IssueSeverity::Error ? 'Masalah wajib' : 'Peringatan' }}</x-status-badge><span class="text-xs text-muted">{{ $issue->code }}</span></div>
                    <p class="text-sm">{{ $issue->message }}</p>
                    @foreach ($sessions->whereIn('id', $issue->session_ids ?? []) as $session)
                        <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
                            <span>{{ $session->title }} • {{ $session->participants->pluck('name')->implode(', ') ?: 'Tanpa kelas' }} • {{ $session->lecturers->pluck('name')->implode(', ') ?: 'Tanpa dosen' }} • {{ $session->room?->name ?? 'Tanpa ruang fisik' }} • {{ $session->day_of_week ? Wita::dayName($session->day_of_week) : 'Tanpa hari' }} {{ $session->start_minute !== null ? Wita::minutes($session->start_minute) : '' }}</span>
                            <a class="font-semibold text-primary hover:underline" href="{{ route('admin.drafts.show', [$version, 'tinjau' => $view, 'sesi' => $session->id]) }}">{{ $isDraft ? 'Perbaiki sesi' : 'Lihat sesi' }}</a>
                        </div>
                    @endforeach
                </div>
            @empty
                <p class="text-sm text-muted">{{ $reviewStatus['current'] ? 'Tidak ada temuan sesuai filter.' : 'Belum ada hasil pemeriksaan untuk revisi ini.' }}</p>
            @endforelse
        </x-card>
        <x-card class="flex flex-col gap-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-lg font-semibold">Tinjauan jadwal</h2>
                <div class="flex flex-wrap gap-2"><x-button variant="secondary" :href="route('admin.drafts.excel', [$version, 'tinjau' => $view])" download>Unduh Excel</x-button><x-button variant="secondary" :href="route('admin.drafts.pdf', [$version, 'tinjau' => $view])" download>Unduh PDF</x-button></div>
            </div>
            <x-tabs :items="collect(['kelas' => 'Per kelas', 'dosen' => 'Per dosen', 'ruang' => 'Per ruang'])->map(fn ($label, $key) => ['label' => $label, 'href' => route('admin.drafts.inspect', [$version, 'tinjau' => $key]), 'active' => $view === $key])->values()->all()" />
            @foreach ($groups as $name => $rows)
                <div class="overflow-x-auto">
                    <h3 class="mb-2 font-semibold">{{ $name }} • {{ $rows->count() }} sesi</h3>
                    <table class="w-full text-left text-sm"><thead class="border-b border-border text-muted"><tr><th class="py-2">Waktu</th><th>Mata kuliah</th><th>Ruang</th><th></th></tr></thead><tbody class="divide-y divide-border">
                        @foreach ($rows as $session)
                            <tr><td class="py-3 pr-3">{{ $session->day_of_week ? Wita::dayName($session->day_of_week) : 'Belum terjadwal' }} {{ $session->start_minute !== null && $session->end_minute !== null ? Wita::minutes($session->start_minute).'–'.Wita::minutes($session->end_minute) : '' }}</td><td class="pr-3">{{ $session->title }}</td><td class="pr-3">{{ $session->mode->value === 'online' ? 'Online' : ($session->room?->name ?? '—') }}</td><td><a href="{{ route('admin.drafts.show', [$version, 'sesi' => $session->id, 'tinjau' => $view]) }}" class="font-semibold text-primary hover:underline">Lihat sesi</a></td></tr>
                        @endforeach
                    </tbody></table>
                </div>
            @endforeach
        </x-card>
        @if ($isDraft && $reviewStatus['ready'])
            <div><x-button :href="route('admin.schedules.index', ['tab' => 'penetapan', 'draf' => $version->id])">Lanjut ke Penetapan</x-button></div>
        @endif
    </div>
@endsection
