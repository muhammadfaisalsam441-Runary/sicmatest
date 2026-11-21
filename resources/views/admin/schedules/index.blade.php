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
    @php
        $clock = fn (?int $minutes) => $minutes === null ? '—' : sprintf('%02d.%02d', intdiv($minutes, 60), $minutes % 60);
    @endphp

    <div class="flex flex-col gap-4 admin-page-stack">
        <x-page-header title="Jadwal Resmi">
            <x-slot:actions>
                <x-button variant="secondary" size="sm" icon="download" :href="route('admin.schedules.template')">Unduh templat</x-button>
                <x-button variant="soft" size="sm" icon="upload" :href="route('admin.imports.create')">Impor Excel atau CSV</x-button>
                <x-button size="sm" :href="Nav::url('admin.scheduler.create')">Buka Susun Jadwal</x-button>
            </x-slot:actions>
        </x-page-header>

        <x-tabs size="sm" :items="[
            ['label' => 'Jadwal & Riwayat', 'href' => route('admin.schedules.index'), 'active' => $tab === 'jadwal'],
            ['label' => 'Penetapan Draf', 'href' => route('admin.schedules.index', ['tab' => 'penetapan']), 'active' => $tab === 'penetapan'],
        ]" />

        @if ($tab === 'penetapan')
            @include('admin.schedules.establishment')
        @else
        @php
            $isActiveVersion = $active && $official && $official->id === $active->id;
            $isUpcomingPreview = $official && ! $isActiveVersion && $official->effective_from?->isFuture();
            $isHistoricalPreview = $official && ! $isActiveVersion && ! $isUpcomingPreview;
        @endphp
        @if ($isUpcomingPreview)
            <x-alert variant="info" :title="'Pratinjau versi mendatang v'.$official->version_no">
                Versi ini berlaku mulai {{ Wita::longDate($official->effective_from) }} dan belum menggantikan jadwal aktif hari ini.
                <a href="{{ route('admin.schedules.index') }}" class="font-semibold text-primary hover:underline">Kembali ke jadwal aktif</a>
            </x-alert>
        @elseif ($isHistoricalPreview)
            <x-alert variant="info" :title="'Pratinjau arsip versi v'.$official->version_no">
                Kamu sedang melihat versi riwayat yang sudah digantikan.
                <a href="{{ route('admin.schedules.index') }}" class="font-semibold text-primary hover:underline">Kembali ke jadwal aktif</a>
            </x-alert>
        @endif
        @foreach ($upcoming as $next)
            @continue($official && $official->id === $next->id)
            <x-alert :title="'Versi v'.$next->version_no.' berlaku mulai '.Wita::longDate($next->effective_from)">
                Sudah ditetapkan; belum berlaku hari ini.
                <a href="{{ route('admin.schedules.index', ['versi' => $next->id]) }}" class="font-semibold text-primary hover:underline">Lihat versi ini</a>
            </x-alert>
        @endforeach
        <x-card padding="px-4 py-2.5" class="flex flex-wrap items-center justify-between gap-2.5">
            <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1 text-xs text-muted">
                @if ($official)
                    @if ($isUpcomingPreview)
                        <x-status-badge variant="primary">Versi mendatang v{{ $official->version_no }}</x-status-badge>
                    @elseif ($isHistoricalPreview || $official->status === ScheduleVersionStatus::Superseded && ! $isActiveVersion)
                        <x-status-badge variant="neutral">Arsip v{{ $official->version_no }}</x-status-badge>
                    @else
                        <span class="inline-flex items-center gap-1.5 rounded-full border border-success/30 bg-success-soft px-2.5 py-0.5 text-xs font-bold text-success">
                            <span class="size-1.5 rounded-full bg-success" aria-hidden="true"></span>
                            Versi ditetapkan v{{ $official->version_no }}
                        </span>
                    @endif
                    <span class="text-sm font-bold text-ink">{{ $period?->name ?? 'Semester aktif' }}</span>
                    <span>•</span>
                    <span>Berlaku: <strong class="text-ink">{{ Wita::longDate($official->effective_from) }}</strong>@if ($official->effective_to) s.d. <strong class="text-ink">{{ Wita::longDate($official->effective_to) }}</strong>@endif</span>
                    <span>•</span>
                    <span class="rounded bg-page px-1.5 py-0.5 font-medium text-ink border border-border">{{ number_format($blockCount) }} blok ruang aktif</span>
                    @if ($official->publisher)
                        <span>• oleh {{ $official->publisher->name }}</span>
                    @endif
                @else
                    <span class="inline-flex items-center rounded-full border border-border bg-page px-2.5 py-0.5 text-xs font-bold text-muted">
                        Belum ada jadwal resmi
                    </span>
                    <span>Status ruang tampil "Belum pasti" sampai jadwal ditetapkan.</span>
                @endif
                <span class="inline-flex items-center text-primary cursor-help"
                      title="{{ $active ? 'Jadwal aktif terhubung otomatis ke My Class dan pengecekan bentrok reservasi mahasiswa. Kalender semester, reservasi, dan penutupan ruang tetap diperhitungkan oleh layanan masing-masing.' : 'Belum ada jadwal yang berlaku hari ini.' }}"
                      aria-label="Informasi keterhubungan jadwal">
                    <i data-lucide="info" class="size-3.5" aria-hidden="true"></i>
                </span>
            </div>

            <div class="flex flex-wrap items-center gap-2 shrink-0">
                @if ($draft)
                    <div class="flex items-center gap-2 rounded-control border border-warning/30 bg-warning-soft/40 px-2.5 py-1">
                        <span class="flex items-center gap-1.5 text-xs font-medium text-warning-ink">
                            <span class="size-1.5 rounded-full bg-warning" aria-hidden="true"></span>
                            Draf v{{ $draft->version_no }} sedang disusun
                        </span>
                        <a href="{{ route('admin.drafts.show', $draft) }}" class="inline-flex items-center gap-1 text-xs font-semibold text-primary hover:underline">
                            <span>Buka</span>
                            <i data-lucide="arrow-right" class="size-3" aria-hidden="true"></i>
                        </a>
                    </div>
                @elseif ($official)
                    <form method="POST" action="{{ route('admin.drafts.store') }}">
                        @csrf
                        <x-button type="submit" variant="secondary" size="sm" icon="pencil">Buat draf revisi</x-button>
                    </form>
                @endif
                @if ($official)
                    <x-button variant="secondary" size="sm" icon="download" :href="route('admin.drafts.excel', [$official, ...request()->only(['hari', 'prodi', 'angkatan', 'kelas', 'dosen', 'ruang', 'q'])])" download>Excel</x-button>
                    <x-button variant="secondary" size="sm" icon="download" :href="route('admin.drafts.pdf', [$official, ...request()->only(['hari', 'prodi', 'angkatan', 'kelas', 'dosen', 'ruang', 'q'])])" download>PDF</x-button>
                @endif
            </div>
        </x-card>

        <x-card padding="p-4" class="admin-table-card flex flex-col gap-3">
            @php
                $activeDropdownCount = collect([
                    $prodi !== '',
                    $cohortYear !== null,
                    $classGroupId !== null,
                    $lecturerId !== null,
                    $roomId !== null,
                ])->filter()->count();
                $hasDropdownFilters = $activeDropdownCount > 0;
                $hasActiveFilters = $keyword !== '' || $hasDropdownFilters || $day !== null;
                $dropdownFilters = [
                    ['name' => 'prodi', 'label' => 'Prodi', 'placeholder' => 'Semua prodi', 'options' => $studyPrograms, 'value' => $prodi],
                    ['name' => 'angkatan', 'label' => 'Angkatan', 'placeholder' => 'Semua angkatan', 'options' => $cohortYears, 'value' => $cohortYear !== null ? (string) $cohortYear : ''],
                    ['name' => 'kelas', 'label' => 'Kelas', 'placeholder' => 'Semua kelas', 'options' => $classGroups, 'value' => $classGroupId !== null ? (string) $classGroupId : ''],
                    ['name' => 'dosen', 'label' => 'Dosen', 'placeholder' => 'Semua pengajar', 'options' => $lecturers, 'value' => $lecturerId !== null ? (string) $lecturerId : ''],
                    ['name' => 'ruang', 'label' => 'Ruang', 'placeholder' => 'Semua ruang', 'options' => $rooms, 'value' => $roomId !== null ? (string) $roomId : ''],
                ];
                $baseFilterQuery = array_filter([
                    'versi' => request('versi'),
                    'hari' => $day,
                    'q' => $keyword !== '' ? $keyword : null,
                    'prodi' => $prodi !== '' ? $prodi : null,
                    'angkatan' => $cohortYear,
                    'kelas' => $classGroupId,
                    'dosen' => $lecturerId,
                    'ruang' => $roomId,
                    'per_page' => $perPage !== 25 ? $perPage : null,
                ]);
                $activeChips = collect([
                    $prodi !== '' ? ['key' => 'prodi', 'label' => 'Prodi: '.($studyPrograms[$prodi] ?? $prodi)] : null,
                    $cohortYear !== null ? ['key' => 'angkatan', 'label' => $cohortYears[$cohortYear] ?? 'Angkatan '.$cohortYear] : null,
                    $classGroupId !== null ? ['key' => 'kelas', 'label' => 'Kelas: '.($classGroups[$classGroupId] ?? $classGroupId)] : null,
                    $lecturerId !== null ? ['key' => 'dosen', 'label' => 'Dosen: '.($lecturers[$lecturerId] ?? $lecturerId)] : null,
                    $roomId !== null ? ['key' => 'ruang', 'label' => 'Ruang: '.($rooms[$roomId] ?? $roomId)] : null,
                ])->filter()->values();
            @endphp

            <form method="GET"
                  action="{{ route('admin.schedules.index') }}"
                  x-data="filterDrawer('schedules-filters', @json($hasDropdownFilters))"
                  class="flex flex-col gap-2.5">
                @if (request('versi'))<input type="hidden" name="versi" value="{{ $official?->id }}">@endif
                @if ($day)<input type="hidden" name="hari" value="{{ $day }}">@endif

                <div class="flex flex-wrap items-center gap-2">
                    <div class="relative min-w-[200px] flex-1">
                        <i data-lucide="search" class="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted" aria-hidden="true"></i>
                        <input id="q"
                               type="search"
                               name="q"
                               value="{{ $keyword }}"
                               data-table-search
                               aria-label="Cari jadwal"
                               @input.debounce.400ms="$el.form.requestSubmit()"
                               placeholder="Cari kelas, ruang, mata kuliah, atau dosen... (/)"
                               class="w-full rounded-control border border-border bg-surface py-1.5 pr-3 pl-8 text-xs sm:text-sm text-ink placeholder:text-muted focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                    </div>

                    <nav aria-label="Filter hari kuliah" class="inline-flex flex-wrap items-center gap-0.5 rounded-control border border-border bg-page p-0.5">
                        @foreach ([null => 'Semua', 1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat'] as $key => $label)
                            @php($dayActive = (int) $day === (int) $key)
                            <a href="{{ route('admin.schedules.index', array_filter(['versi' => request('versi'), 'ruang' => $roomId, 'q' => $keyword, 'prodi' => $prodi, 'angkatan' => $cohortYear, 'kelas' => $classGroupId, 'dosen' => $lecturerId, 'per_page' => $perPage !== 25 ? $perPage : null, 'hari' => $key ?: null])) }}"
                               @if ($dayActive) aria-current="page" @endif
                               @class([
                                   'rounded-[6px] px-2.5 py-1 text-xs font-semibold transition',
                                   'bg-primary text-white shadow-2xs' => $dayActive,
                                   'text-muted hover:bg-surface hover:text-ink' => ! $dayActive,
                               ])>{{ $label }}</a>
                        @endforeach
                    </nav>

                    <select name="per_page"
                            aria-label="Baris per halaman"
                            @change="$el.form.requestSubmit()"
                            class="rounded-control border border-border bg-surface px-2.5 py-1.5 text-xs text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                        @foreach ([25, 50, 100] as $n)
                            <option value="{{ $n }}" @selected($perPage === $n)>{{ $n }} baris</option>
                        @endforeach
                    </select>

                    <button type="button"
                            @click="toggle()"
                            :aria-expanded="open.toString()"
                            @class([
                                'inline-flex items-center gap-1.5 rounded-control border px-2.5 py-1.5 text-xs font-semibold transition cursor-pointer shrink-0',
                                'border-primary/40 bg-primary-soft text-primary' => $hasDropdownFilters,
                                'border-border bg-surface text-ink hover:bg-page' => ! $hasDropdownFilters,
                            ])>
                        <i data-lucide="sliders-horizontal" class="size-3.5" aria-hidden="true"></i>
                        <span>Filter{{ $activeDropdownCount > 0 ? " ({$activeDropdownCount})" : '' }}</span>
                        <i data-lucide="chevron-down" class="size-3.5 transition-transform" :class="open ? 'rotate-180' : ''" aria-hidden="true"></i>
                    </button>

                    @if ($hasActiveFilters)
                        <x-button variant="secondary" size="sm" icon="rotate-ccw" :href="route('admin.schedules.index', array_filter(['versi' => request('versi')]))" class="shrink-0">Reset</x-button>
                    @endif
                </div>

                @if ($activeChips->isNotEmpty())
                    <div x-show="!open" x-cloak class="flex flex-wrap items-center gap-1.5 text-xs">
                        <span class="text-muted">Aktif:</span>
                        @foreach ($activeChips as $chip)
                            <a href="{{ route('admin.schedules.index', collect($baseFilterQuery)->except($chip['key'])->all()) }}"
                               class="inline-flex items-center gap-1 rounded-full border border-primary/25 bg-primary-soft px-2.5 py-0.5 font-medium text-primary hover:bg-primary/15 transition"
                               title="Hapus filter {{ $chip['label'] }}">
                                <span>{{ $chip['label'] }}</span>
                                <i data-lucide="x" class="size-3" aria-hidden="true"></i>
                            </a>
                        @endforeach
                    </div>
                @endif

                <div x-show="open" x-cloak class="grid grid-cols-1 gap-2 border-t border-border pt-2.5 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5">
                    @foreach ($dropdownFilters as $filterItem)
                        <select id="{{ $filterItem['name'] }}"
                                name="{{ $filterItem['name'] }}"
                                aria-label="{{ $filterItem['label'] }}"
                                @change="$el.form.requestSubmit()"
                                class="w-full rounded-control border border-border bg-surface px-2.5 py-1.5 text-xs text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                            <option value="">{{ $filterItem['placeholder'] }}</option>
                            @foreach ($filterItem['options'] as $optionValue => $optionLabel)
                                <option value="{{ $optionValue }}" @selected($filterItem['value'] !== '' && $filterItem['value'] === (string) $optionValue)>{{ $optionLabel }}</option>
                            @endforeach
                        </select>
                    @endforeach
                </div>
            </form>

            @if ($sessions->isEmpty())
                <x-empty-state :title="$official ? 'Tidak ada jadwal yang cocok' : 'Belum ada jadwal'"
                               :message="$official ? 'Tidak ada jadwal perkuliahan yang cocok dengan filter.' : 'Jadwal perkuliahan belum tersedia. Impor Excel atau CSV agar status ruang bisa dihitung.'">
                    <x-button variant="soft" :href="$official ? route('admin.schedules.index', array_filter(['versi' => request('versi')])) : route('admin.imports.create')">{{ $official ? 'Hapus filter' : 'Impor Excel atau CSV' }}</x-button>
                </x-empty-state>
            @else
                <x-table-scroll>
                    <x-slot:title>{{ $sessions->total() }} sesi • Jadwal perkuliahan resmi v{{ $official->version_no }}</x-slot:title>
                    <x-slot:headerActions>
                        <x-table-density-toggle />
                    </x-slot:headerActions>
                    <table class="w-full text-left text-sm">
                        <thead class="sticky top-0 z-10 border-b border-border bg-page text-xs font-semibold text-muted">
                            <tr>
                                <th scope="col" class="px-4 py-3 font-medium">Kelas</th>
                                <th scope="col" class="px-4 py-3 font-medium whitespace-nowrap">Waktu</th>
                                <th scope="col" class="px-4 py-3 font-medium">Ruang</th>
                                <th scope="col" class="px-4 py-3 font-medium">Mata kuliah</th>
                                <th scope="col" class="px-4 py-3 font-medium">Tim pengajar</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @foreach ($sessions as $session)
                                @php($isAlternateBlock = $cohortBlockAlternate[$session->id] ?? false)
                                <tr @class([
                                    'transition',
                                    'bg-primary-soft/45 hover:bg-primary-soft/70' => $isAlternateBlock,
                                    'bg-surface hover:bg-page/70' => ! $isAlternateBlock,
                                ])>
                                    <td class="px-4 py-3 font-medium whitespace-nowrap">
                                        <span class="font-semibold text-ink">{{ $session->participants->pluck('name')->implode(' + ') ?: '—' }}</span>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap">
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
                                    <td class="px-4 py-3 whitespace-nowrap">
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
                                    <td class="px-4 py-3">
                                        <span class="font-medium text-ink">{{ $session->title }}</span>
                                    </td>
                                    <td class="px-4 py-3">
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
                </x-table-scroll>
                @if ($sessions->hasPages())
                    <div class="border-t border-border pt-3">
                        {{ $sessions->links() }}
                    </div>
                @endif
            @endif
        </x-card>

        <x-card padding="p-4" class="flex flex-col gap-2.5">
            <h2 class="text-base font-semibold">Riwayat revisi</h2>
            @forelse ($versions as $version)
                <div class="flex flex-wrap items-center justify-between gap-2 rounded-control border border-border px-3 py-2 text-xs sm:text-sm">
                    <div class="flex flex-wrap items-center gap-2.5">
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
