@use('App\Enums\ScheduleVersionStatus')
@use('App\Enums\SessionMode')
@use('App\Enums\SessionPhase')
@use('App\Support\Nav')
@use('App\Support\Wita')
@extends('layouts.admin')

@section('title', 'Jadwal Resmi')
@section('breadcrumb', 'Jadwal Resmi')

@section('breadcrumb-actions')
    <x-button variant="secondary" :href="route('admin.dashboard')">Dashboard</x-button>
@endsection

@section('content')
    @php($clock = fn (?int $minutes) => $minutes === null ? '—' : sprintf('%02d.%02d', intdiv($minutes, 60), $minutes % 60))

    <div class="flex flex-col gap-6">
        <x-page-header title="Jadwal Resmi" subtitle="Jadwal perkuliahan yang ditetapkan, rentang berlaku, dan riwayat revisi. Perubahan selalu melalui draf.">
            <x-slot:actions>
                <x-button variant="secondary" icon="download" :href="route('admin.schedules.template')">Unduh templat</x-button>
                <x-button variant="soft" icon="upload" :href="route('admin.imports.create')">Impor Excel atau CSV</x-button>
                <x-button :href="Nav::url('admin.scheduler.create')">Buka Susun Jadwal</x-button>
            </x-slot:actions>
        </x-page-header>

        <x-card><x-schedule-steps :current="5" :version="$candidate" /></x-card>
        <x-tabs :items="[
            ['label' => 'Jadwal & Riwayat', 'href' => route('admin.schedules.index'), 'active' => $tab === 'jadwal'],
            ['label' => 'Penetapan Draf', 'href' => route('admin.schedules.index', ['tab' => 'penetapan']), 'active' => $tab === 'penetapan'],
        ]" />

        @if ($tab === 'penetapan')
            @include('admin.schedules.establishment')
        @else
        @foreach ($upcoming as $next)
            <x-alert :title="'Versi v'.$next->version_no.' berlaku mulai '.Wita::longDate($next->effective_from)">
                Sudah ditetapkan; belum berlaku hari ini.
                <a href="{{ route('admin.schedules.index', ['versi' => $next->id]) }}" class="font-semibold text-primary hover:underline">Lihat versi ini</a>
            </x-alert>
        @endforeach
        <x-card class="flex flex-col gap-4">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex flex-col gap-2">
                    <div class="flex flex-wrap items-center gap-2.5">
                        @if ($official)
                            <span class="inline-flex items-center gap-1.5 rounded-full border border-success/30 bg-success-soft px-3 py-1 text-xs font-bold text-success">
                                <span class="size-2 rounded-full bg-success"></span>
                                Versi ditetapkan v{{ $official->version_no }}
                            </span>
                            <span class="text-sm font-bold text-ink">{{ $period?->name ?? 'Semester aktif' }}</span>
                        @else
                            <span class="inline-flex items-center gap-1.5 rounded-full border border-border bg-page px-3 py-1 text-xs font-bold text-muted">
                                Belum ada jadwal resmi
                            </span>
                        @endif
                    </div>

                    @if ($official)
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted">
                            <span>Berlaku mulai <strong class="text-ink">{{ Wita::longDate($official->effective_from) }}</strong>{{ $official->effective_to ? ' sampai <strong class="text-ink">'.Wita::longDate($official->effective_to).'</strong>' : '' }}</span>
                            <span>•</span>
                            <span><strong class="text-ink">{{ number_format($blockCount) }}</strong> blok ruang aktif tercatat</span>
                            <span>•</span>
                            <span>Ditetapkan {{ $official->publisher?->name ?? 'admin' }} {{ Wita::stamp($official->published_at) }}</span>
                        </div>
                    @else
                        <p class="text-xs text-muted">Status ruang tampil "Belum pasti" sampai jadwal ditetapkan.</p>
                    @endif
                </div>

                @if ($draft)
                    <div class="flex items-center gap-3 rounded-card border border-warning/30 bg-warning-soft/40 px-4 py-2.5">
                        <div class="flex flex-col">
                            <span class="flex items-center gap-1.5 text-xs font-semibold text-warning-ink">
                                <span class="size-1.5 rounded-full bg-warning"></span>
                                Draf v{{ $draft->version_no }} sedang disusun.
                            </span>
                            <span class="text-[11px] text-muted">Ada perubahan yang belum ditetapkan</span>
                        </div>
                        <x-button variant="soft" :href="route('admin.drafts.show', $draft)">Buka draf v{{ $draft->version_no }}</x-button>
                    </div>
                @elseif ($official)
                    <form method="POST" action="{{ route('admin.drafts.store') }}">
                        @csrf
                        <x-button type="submit" variant="soft" icon="pencil">Buat draf revisi</x-button>
                    </form>
                @endif
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-border pt-3 text-xs text-muted">
                <p class="flex items-center gap-1.5">
                    <i data-lucide="info" class="size-3.5 text-primary shrink-0"></i>
                    <span>{{ $active ? 'My Class dan blok jadwal ruang mengikuti rentang berlaku versi v'.$active->version_no.'.' : 'Belum ada versi yang berlaku hari ini.' }} Kalender semester, reservasi, dan penutupan ruang tetap diperhitungkan oleh layanan masing-masing.</span>
                </p>
                @if ($official)
                    <div class="flex items-center gap-2 shrink-0">
                        <x-button variant="secondary" icon="download" :href="route('admin.drafts.excel', [$official, ...request()->only(['hari', 'kelas', 'dosen', 'ruang', 'q'])])" download>Unduh Excel</x-button>
                        <x-button variant="secondary" icon="download" :href="route('admin.drafts.pdf', [$official, ...request()->only(['hari', 'kelas', 'dosen', 'ruang', 'q'])])" download>Unduh PDF</x-button>
                    </div>
                @endif
            </div>
        </x-card>

        <x-card class="flex flex-col gap-4">
            <form method="GET" action="{{ route('admin.schedules.index') }}" class="grid items-end gap-3 sm:grid-cols-2 xl:grid-cols-[2fr_1fr_1fr_1fr_auto_auto]">
                @if (request('versi'))<input type="hidden" name="versi" value="{{ $official?->id }}">@endif
                <x-field label="Cari" name="q">
                    <x-input name="q" :value="$keyword" placeholder="Cari kelas, ruang, mata kuliah, atau dosen" />
                </x-field>
                <x-field label="Kelas" name="kelas">
                    <x-select name="kelas" :options="$classGroups" :value="$classGroupId" placeholder="Semua kelas" />
                </x-field>
                <x-field label="Dosen" name="dosen">
                    <x-select name="dosen" :options="$lecturers" :value="$lecturerId" placeholder="Semua pengajar" />
                </x-field>
                <x-field label="Ruang" name="ruang"><x-select name="ruang" :options="$rooms" :value="$roomId" placeholder="Semua ruang" /></x-field>
                @if ($day)<input type="hidden" name="hari" value="{{ $day }}">@endif
                <x-button type="submit">Terapkan</x-button>
                <x-button variant="secondary" :href="route('admin.schedules.index')">Reset</x-button>
            </form>

            <x-tabs :items="collect([null => 'Semua', 1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat'])
                ->map(fn ($label, $key) => ['label' => $label, 'href' => route('admin.schedules.index', array_filter(['versi' => request('versi'), 'ruang' => $roomId, 'q' => $keyword, 'kelas' => $classGroupId, 'dosen' => $lecturerId, 'hari' => $key ?: null])), 'active' => (int) $day === (int) $key])->values()->all()" />

            @if ($sessions->isEmpty())
                <x-empty-state :title="$official ? 'Tidak ada jadwal yang cocok' : 'Belum ada jadwal'"
                               :message="$official ? 'Coba kata kunci lain atau hapus filter hari.' : 'Impor jadwal perkuliahan agar status ruang bisa dihitung.'">
                    <x-button variant="soft" :href="$official ? route('admin.schedules.index') : route('admin.imports.create')">{{ $official ? 'Hapus filter' : 'Impor Excel atau CSV' }}</x-button>
                </x-empty-state>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b border-border text-xs text-muted">
                            <tr>
                                <th class="py-3 pr-4 font-medium">Kelas</th>
                                <th class="py-3 pr-4 font-medium whitespace-nowrap">Waktu</th>
                                <th class="py-3 pr-4 font-medium">Ruang</th>
                                <th class="py-3 pr-4 font-medium">Mata kuliah</th>
                                <th class="py-3 font-medium">Tim pengajar</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @foreach ($sessions as $session)
                                <tr class="hover:bg-page/50 transition">
                                    <td class="py-3 pr-4 font-medium whitespace-nowrap">
                                        <span class="font-semibold text-ink">{{ $session->participants->pluck('name')->implode(' + ') ?: '—' }}</span>
                                    </td>
                                    <td class="py-3 pr-4 whitespace-nowrap">
                                        <div class="flex flex-col">
                                            <span class="font-medium text-ink">{{ $session->day_of_week ? Wita::dayName($session->day_of_week) : '—' }}</span>
                                            <span class="text-xs text-muted">
                                                @if ($session->mode === SessionMode::Unscheduled)
                                                    {{ $session->source_raw['waktu'] ?? 'Belum terjadwal' }}
                                                @else
                                                    {{ $clock($session->start_minute) }}–{{ $clock($session->end_minute) }} WITA
                                                @endif
                                            </span>
                                        </div>
                                    </td>
                                    <td class="py-3 pr-4 whitespace-nowrap">
                                        @if ($session->mode === SessionMode::Online)
                                            <span class="inline-flex items-center rounded-md bg-primary-soft px-2 py-0.5 text-xs font-semibold text-primary">Online</span>
                                        @elseif ($session->room)
                                            <span class="inline-flex items-center rounded-md border border-border bg-page px-2 py-0.5 text-xs font-semibold text-ink">
                                                {{ $session->room->name }}
                                            </span>
                                        @else
                                            <span class="text-xs text-muted italic">—</span>
                                        @endif
                                    </td>
                                    <td class="py-3 pr-4">
                                        <span class="font-medium text-ink">{{ $session->title }}</span>
                                    </td>
                                    <td class="py-3">
                                        @if ($session->lecturers->isNotEmpty())
                                            <div class="flex flex-col gap-1.5">
                                                @foreach ($session->lecturers->sortByDesc(fn ($l) => $l->pivot->role === 'lead') as $lecturer)
                                                    <div class="flex items-center gap-2">
                                                        @if ($lecturer->pivot->role === 'lead')
                                                            <span class="inline-flex shrink-0 items-center rounded bg-primary-soft px-1.5 py-0.5 text-[10px] font-bold text-primary tracking-wide uppercase">
                                                                Pengampu
                                                            </span>
                                                        @else
                                                            <span class="inline-flex shrink-0 items-center rounded border border-border bg-page px-1.5 py-0.5 text-[10px] font-semibold text-muted tracking-wide uppercase">
                                                                Mitra
                                                            </span>
                                                        @endif
                                                        <span class="font-medium text-ink text-xs sm:text-sm">
                                                            {{ $lecturer->name }}
                                                        </span>
                                                    </div>
                                                @endforeach
                                                @unless ($session->phase === SessionPhase::Full)
                                                    <span class="text-[11px] font-medium text-muted">
                                                        Fase: {{ $session->phase->label() }}
                                                    </span>
                                                @endunless
                                            </div>
                                        @else
                                            <span class="text-xs text-muted italic">— Belum ada pengampu —</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
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
                    <a href="{{ in_array($version->status->value, ['draft', 'ready'], true) ? route('admin.drafts.inspect', $version) : route('admin.schedules.index', ['versi' => $version->id]) }}" class="font-semibold text-primary hover:underline">Lihat</a>
                </div>
            @empty
                <p class="text-sm text-muted">Belum ada versi jadwal.</p>
            @endforelse
        </x-card>
        @endif
    </div>
@endsection
