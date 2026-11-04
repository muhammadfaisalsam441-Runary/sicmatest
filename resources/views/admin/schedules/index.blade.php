@use('App\Enums\ScheduleVersionStatus')
@use('App\Enums\SessionMode')
@use('App\Enums\SessionPhase')
@use('App\Support\Nav')
@use('App\Support\Wita')
@extends('layouts.admin')

@section('title', 'Kelola Jadwal')
@section('breadcrumb', 'Jadwal Perkuliahan')

@section('breadcrumb-actions')
    <x-button variant="secondary" :href="route('admin.dashboard')">Dashboard</x-button>
@endsection

@section('content')
    @php($clock = fn (?int $minutes) => $minutes === null ? '—' : sprintf('%02d.%02d', intdiv($minutes, 60), $minutes % 60))

    <div class="flex flex-col gap-6">
        <x-page-header title="Jadwal perkuliahan" subtitle="Perubahan jadwal selalu melalui draf sebelum ditetapkan. Portal Keti dan publik hanya membaca jadwal resmi.">
            <x-slot:actions>
                <x-button variant="secondary" icon="download" :href="route('admin.schedules.template')">Unduh templat</x-button>
                <x-button variant="soft" icon="upload" :href="route('admin.imports.create')">Impor Excel atau CSV</x-button>
                <x-button :href="Nav::url('admin.scheduler.create')">Buka Susun Jadwal</x-button>
            </x-slot:actions>
        </x-page-header>

        <x-card class="flex items-center justify-between gap-6">
            <div class="flex flex-col gap-1">
                @if ($official)
                    <span class="text-lg font-semibold text-success">Resmi v{{ $official->version_no }}</span>
                    <span class="text-sm text-muted">{{ $period->name }} • berlaku sejak {{ Wita::longDate($official->effective_from) }} • ditetapkan {{ Wita::date($official->published_at) }}</span>
                @else
                    <span class="text-lg font-semibold text-muted">Belum ada jadwal resmi</span>
                    <span class="text-sm text-muted">Status ruang tampil "Belum pasti" sampai jadwal ditetapkan.</span>
                @endif
            </div>
            @if ($draft)
                <div class="flex items-center gap-3">
                    <span class="text-sm text-muted">Draf v{{ $draft->version_no }} sedang disusun.</span>
                    <x-button variant="soft" :href="route('admin.drafts.show', $draft)">Buka draf v{{ $draft->version_no }}</x-button>
                </div>
            @elseif ($official)
                <form method="POST" action="{{ route('admin.drafts.store') }}">
                    @csrf
                    <x-button type="submit" variant="soft" icon="pencil">Buat draf revisi</x-button>
                </form>
            @endif
        </x-card>

        <x-card class="flex flex-col gap-4">
            <form method="GET" action="{{ route('admin.schedules.index') }}" class="grid grid-cols-[2fr_1fr_1fr_auto_auto] items-end gap-3">
                <x-field label="Cari" name="q">
                    <x-input name="q" :value="$keyword" placeholder="Cari kelas, ruang, mata kuliah, atau dosen" />
                </x-field>
                <x-field label="Kelas" name="kelas">
                    <x-select name="kelas" :options="$classGroups" :value="$classGroupId" placeholder="Semua kelas" />
                </x-field>
                <x-field label="Dosen" name="dosen">
                    <x-select name="dosen" :options="$lecturers" :value="$lecturerId" placeholder="Semua pengajar" />
                </x-field>
                @if ($day)<input type="hidden" name="hari" value="{{ $day }}">@endif
                <x-button type="submit">Terapkan</x-button>
                <x-button variant="secondary" :href="route('admin.schedules.index')">Reset</x-button>
            </form>

            <x-tabs :items="collect([null => 'Semua', 1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat'])
                ->map(fn ($label, $key) => ['label' => $label, 'href' => route('admin.schedules.index', array_filter(['q' => $keyword, 'kelas' => $classGroupId, 'dosen' => $lecturerId, 'hari' => $key ?: null])), 'active' => (int) $day === (int) $key])->values()->all()" />

            @if ($sessions->isEmpty())
                <x-empty-state :title="$official ? 'Tidak ada jadwal yang cocok' : 'Belum ada jadwal'"
                               :message="$official ? 'Coba kata kunci lain atau hapus filter hari.' : 'Impor jadwal perkuliahan agar status ruang bisa dihitung.'">
                    <x-button variant="soft" :href="$official ? route('admin.schedules.index') : route('admin.imports.create')">{{ $official ? 'Hapus filter' : 'Impor Excel atau CSV' }}</x-button>
                </x-empty-state>
            @else
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-border text-xs text-muted">
                        <tr>
                            <th class="py-3 pr-4 font-medium">Kelas</th>
                            <th class="py-3 pr-4 font-medium">Hari</th>
                            <th class="py-3 pr-4 font-medium">Jam (WITA)</th>
                            <th class="py-3 pr-4 font-medium">Ruang</th>
                            <th class="py-3 pr-4 font-medium">Mata kuliah</th>
                            <th class="py-3 font-medium">Tim pengajar</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @foreach ($sessions as $session)
                            <tr>
                                <td class="py-3 pr-4 font-medium">{{ $session->participants->pluck('name')->implode(' + ') }}</td>
                                <td class="py-3 pr-4">{{ $session->day_of_week ? Wita::dayName($session->day_of_week) : '—' }}</td>
                                <td class="py-3 pr-4">{{ $session->mode === SessionMode::Unscheduled ? ($session->source_raw['waktu'] ?? 'Belum terjadwal') : $clock($session->start_minute).'–'.$clock($session->end_minute) }}</td>
                                <td class="py-3 pr-4">{{ match ($session->mode) { SessionMode::Online => 'Online', SessionMode::Unscheduled => '—', default => $session->room?->name ?? '—' } }}</td>
                                <td class="py-3 pr-4">{{ $session->title }}</td>
                                <td class="py-3 text-muted">
                                    {{ $session->lecturers->map(fn ($lecturer) => ($lecturer->pivot->role === 'lead' ? 'Pengampu: ' : 'Mitra: ').$lecturer->name)->implode(' • ') ?: '—' }}
                                    @unless ($session->phase === SessionPhase::Full)<span class="block text-xs">{{ strtolower($session->phase->label()) }}</span>@endunless
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <p class="text-sm text-muted">{{ $sessions->count() }} sesi pada jadwal resmi v{{ $official->version_no }}</p>
            @endif
        </x-card>

        <x-card class="flex flex-col gap-3">
            <h2 class="text-lg font-semibold">Riwayat revisi</h2>
            @forelse ($versions as $version)
                <div class="flex items-center justify-between rounded-control border border-border px-4 py-3 text-sm">
                    <div class="flex items-center gap-3">
                        <span class="font-semibold">v{{ $version->version_no }}</span>
                        <x-status-badge :variant="match ($version->status) { ScheduleVersionStatus::Official => 'success', ScheduleVersionStatus::Draft, ScheduleVersionStatus::Ready => 'primary', default => 'neutral' }">{{ $version->status->label() }}</x-status-badge>
                        <span class="text-muted">{{ $version->source->label() }} • berlaku {{ Wita::date($version->effective_from) }}{{ $version->effective_to ? ' – '.Wita::date($version->effective_to) : '' }}</span>
                        @if ($version->publisher)<span class="text-muted">• ditetapkan {{ $version->publisher->name }}</span>@endif
                    </div>
                    <a href="{{ route('admin.drafts.show', $version) }}" class="font-semibold text-primary hover:underline">Lihat</a>
                </div>
            @empty
                <p class="text-sm text-muted">Belum ada versi jadwal.</p>
            @endforelse
        </x-card>
    </div>
@endsection
