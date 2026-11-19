@use('App\Enums\IssueSeverity')
@use('App\Enums\SessionMode')
@use('App\Support\Wita')
@extends('layouts.admin')
@section('title', 'Pemeriksaan Jadwal')
@section('breadcrumb', 'Pemeriksaan Jadwal|Versi '.$version->version_no)
@section('content')
    @php
        $clock = fn (?int $minutes) => $minutes === null ? '—' : sprintf('%02d.%02d', intdiv($minutes, 60), $minutes % 60);
        $issueLabels = [
            'PERIOD_CALENDAR_MISSING' => 'Kalender semester belum lengkap',
            'UTS_CALENDAR_MISSING' => 'Rentang UTS belum lengkap',
            'SOURCE_SKS_CONFLICT' => 'Perbedaan SKS sumber & master',
            'SESSION_WITHOUT_TIME' => 'Sesi tanpa hari & jam',
            'TEACHING_DAY_NOT_ALLOWED' => 'Hari kuliah tidak diizinkan',
            'FRIDAY_BREAK_CONFLICT' => 'Bentrok jeda Jumat',
            'SESSION_WITHOUT_ROOM' => 'Sesi luring tanpa ruang',
            'ROOM_CONFLICT' => 'Bentrok ruang',
            'LECTURER_CONFLICT' => 'Bentrok dosen',
            'CLASS_CONFLICT' => 'Bentrok kelas',
            'LEADERSHIP_THURSDAY' => 'Pimpinan terjadwal Kamis',
            'CAPACITY_EXCEEDED' => 'Kapasitas ruang kurang',
            'UNVERIFIED_LECTURER' => 'Dosen belum terverifikasi',
            'LOAD_EXCEEDED' => 'Beban SKS melebihi maksimum',
            'LOAD_UNCONFIRMED' => 'Beban SKS belum lengkap',
            'ROOM_CLOSED' => 'Bentrok penutupan ruang',
            'RESERVATION_CONFLICT' => 'Bentrok reservasi ruang',
            'LECTURER_UNAVAILABLE' => 'Di luar ketersediaan dosen',
        ];
        $issueOptions = $issues->pluck('code')->unique()->sort()->mapWithKeys(fn (string $code) => [$code => $issueLabels[$code] ?? $code]);
        $defaultInspectionSection = (request()->filled('tingkat') || request()->filled('jenis') || request()->filled('keparahan') || request()->filled('kategori'))
            ? 'temuan'
            : (request()->filled('tinjau') ? 'tinjauan' : ($filteredIssues->isNotEmpty() ? 'temuan' : 'tinjauan'));
    @endphp
    <div class="flex flex-col gap-4 admin-page-stack">
        <x-page-header :title="'Pemeriksaan Draf v'.$version->version_no">
            <x-slot:actions>
                @unless ($isDraft)
                    <x-button variant="secondary" size="sm" :href="route('admin.drafts.show', $version)">Kembali ke Draf</x-button>
                @endunless
                @if ($isDraft)
                    <form method="POST" action="{{ route('admin.drafts.validate', $version) }}">@csrf<x-button type="submit" size="sm" icon="refresh-cw">Periksa Ulang</x-button></form>
                @endif
            </x-slot:actions>
        </x-page-header>

        @include('admin.partials.scheduling-tabs', [
            'version' => $version,
            'isDraft' => $isDraft,
            'reviewStatus' => $reviewStatus,
        ])

        <div @class([
            'flex flex-wrap items-center justify-between gap-2 rounded-card border px-4 py-2.5 text-xs sm:text-sm',
            'border-success/40 bg-success-soft text-success' => $reviewStatus['ready'],
            'border-primary/30 bg-primary-soft text-primary' => ! $reviewStatus['ready'],
        ]) role="status">
            <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1">
                <i data-lucide="{{ $reviewStatus['ready'] ? 'circle-check' : 'info' }}" class="size-4 shrink-0" aria-hidden="true"></i>
                <strong class="font-semibold">{{ $reviewStatus['label'] }}</strong>
                <span class="text-xs opacity-90">
                    {{ $reviewStatus['checked_at'] ? 'Diperiksa '.Wita::stamp($reviewStatus['checked_at']) : 'Jalankan pemeriksaan sebelum melanjutkan penetapan.' }}
                    @if (! $reviewStatus['current'] && $reviewStatus['checked_at']) • Draf berubah sejak pemeriksaan terakhir. @endif
                </span>
                <span class="text-xs opacity-75">• {{ $version->period->name }} ({{ $version->source->label() }}{{ $version->basedOn ? ', dasar v'.$version->basedOn->version_no : '' }})</span>
                <span class="inline-flex items-center cursor-help opacity-80"
                      title="Hasil pemeriksaan merupakan kondisi pada waktu tersebut; sistem memeriksa ulang saat penetapan."
                      aria-label="Catatan pemeriksaan">
                    <i data-lucide="info" class="size-3.5" aria-hidden="true"></i>
                </span>
                @if ($version->allow_conflicts)
                    <span class="rounded-full border border-current/25 bg-white/60 px-2.5 py-0.5 text-[11px] font-semibold">
                        Toleransi draf aktif
                    </span>
                @endif
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @if ($isDraft && auth()->user()->can('publish-schedule') && ($version->allow_conflicts || $reviewStatus['errors'] > 0))
                    <form method="POST" action="{{ route('admin.drafts.tolerance', $version) }}">
                        @csrf
                        <x-button type="submit" variant="secondary" size="sm">
                            {{ $version->allow_conflicts ? 'Ketatkan Kembali' : 'Izinkan Toleransi Draf Ini' }}
                        </x-button>
                    </form>
                @endif
                @if ($isDraft && $reviewStatus['ready'])
                    <x-button size="sm" :href="route('admin.schedules.index', ['tab' => 'penetapan', 'draf' => $version->id])">Lanjut ke Penetapan</x-button>
                @endif
            </div>
        </div>

        <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">
            <x-stat-card :compact="true" label="Total sesi" :value="$counts['total']" />
            <x-stat-card :compact="true" label="Terjadwal" :value="$counts['timed']" tone="success" />
            <x-stat-card :compact="true" label="Belum lengkap" :value="$counts['total'] - $counts['timed']" :tone="($counts['total'] - $counts['timed']) > 0 ? 'danger' : 'ink'" />
            <x-stat-card :compact="true" label="Masalah wajib" :value="$reviewStatus['errors']" :tone="$reviewStatus['errors'] > 0 ? 'danger' : 'success'" />
            <x-stat-card :compact="true" label="Peringatan" :value="$reviewStatus['warnings']" :tone="$reviewStatus['warnings'] > 0 ? 'primary' : 'ink'" />
            <x-stat-card :compact="true" label="Terkunci" :value="$counts['locked']" />
        </div>

        <div x-data="{ section: @js($defaultInspectionSection) }" class="flex flex-col gap-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex flex-wrap items-center gap-1.5" role="tablist" aria-label="Bagian pemeriksaan draf">
                    <button type="button"
                            role="tab"
                            :aria-selected="(section === 'temuan').toString()"
                            @click="section = 'temuan'"
                            :class="section === 'temuan' ? 'bg-primary text-white shadow-2xs' : 'border border-border bg-surface text-ink hover:bg-primary-soft/60'"
                            class="inline-flex items-center gap-1.5 rounded-full px-3.5 py-1.5 text-xs font-semibold transition cursor-pointer">
                        <span>Temuan Pemeriksaan</span>
                        <span class="rounded-full px-1.5 py-0.5 text-[11px]"
                              :class="section === 'temuan' ? 'bg-white/20 text-white' : 'bg-page text-muted'">{{ $filteredIssues->count() }}</span>
                    </button>
                    <button type="button"
                            role="tab"
                            :aria-selected="(section === 'tinjauan').toString()"
                            @click="section = 'tinjauan'"
                            :class="section === 'tinjauan' ? 'bg-primary text-white shadow-2xs' : 'border border-border bg-surface text-ink hover:bg-primary-soft/60'"
                            class="inline-flex items-center gap-1.5 rounded-full px-3.5 py-1.5 text-xs font-semibold transition cursor-pointer">
                        <span>Tinjauan Jadwal</span>
                        <span class="rounded-full px-1.5 py-0.5 text-[11px]"
                              :class="section === 'tinjauan' ? 'bg-white/20 text-white' : 'bg-page text-muted'">{{ $groups->count() }} kelompok</span>
                    </button>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <x-button variant="secondary" size="sm" icon="download" :href="route('admin.drafts.excel', [$version, 'tinjau' => $view])" download>Unduh Excel</x-button>
                    <x-button variant="secondary" size="sm" icon="download" :href="route('admin.drafts.pdf', [$version, 'tinjau' => $view])" download>Unduh PDF</x-button>
                </div>
            </div>

            <div x-show="section === 'temuan'">
                <x-card padding="p-4" class="admin-table-card flex flex-col gap-3">
                    <form method="GET" action="{{ route('admin.drafts.inspect', $version) }}" class="flex flex-wrap items-center gap-2">
                        <input type="hidden" name="tinjau" value="{{ $view }}">
                        <select name="tingkat"
                                aria-label="Tingkat temuan"
                                @change="$el.form.requestSubmit()"
                                class="rounded-control border border-border bg-surface px-3 py-1.5 text-xs sm:text-sm text-ink focus:border-primary focus:outline-none">
                            <option value="">Semua tingkat</option>
                            <option value="error" @selected(request('tingkat') === 'error')>Masalah wajib</option>
                            <option value="warning" @selected(request('tingkat') === 'warning')>Peringatan</option>
                        </select>
                        <select name="jenis"
                                aria-label="Jenis masalah"
                                @change="$el.form.requestSubmit()"
                                class="rounded-control border border-border bg-surface px-3 py-1.5 text-xs sm:text-sm text-ink focus:border-primary focus:outline-none">
                            <option value="">Semua jenis</option>
                            @foreach ($issueOptions as $code => $label)
                                <option value="{{ $code }}" @selected(request('jenis') === $code)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <x-button type="submit" size="sm">Terapkan</x-button>
                        @if (request()->filled('tingkat') || request()->filled('jenis'))
                            <x-button variant="secondary" size="sm" :href="route('admin.drafts.inspect', [$version, 'tinjau' => $view])">Reset</x-button>
                        @endif
                    </form>

                    @forelse ($filteredIssues as $issue)
                        <div class="flex flex-col gap-1.5 rounded-control border border-border p-3">
                            <div class="flex flex-wrap items-center gap-2">
                                <x-status-badge :variant="$issue->severity === IssueSeverity::Error ? 'danger' : 'warning'">{{ $issue->severity === IssueSeverity::Error ? 'Masalah wajib' : 'Peringatan' }}</x-status-badge>
                                <span class="text-xs font-medium text-muted">{{ $issueLabels[$issue->code] ?? $issue->code }}</span>
                            </div>
                            <p class="text-sm">{{ $issue->message }}</p>
                            @foreach ($sessions->whereIn('id', $issue->session_ids ?? []) as $session)
                                <div class="flex flex-wrap items-center justify-between gap-2 text-xs sm:text-sm">
                                    <span>{{ $session->title }} • {{ $session->participants->pluck('name')->implode(', ') ?: 'Tanpa kelas' }} • {{ $session->lecturers->pluck('name')->implode(', ') ?: 'Tanpa dosen' }} • {{ $session->room?->name ?? 'Tanpa ruang fisik' }} • {{ $session->day_of_week ? Wita::dayName($session->day_of_week) : 'Tanpa hari' }} {{ $session->start_minute !== null ? $clock($session->start_minute) : '' }}</span>
                                    <a class="font-semibold text-primary hover:underline" href="{{ route('admin.drafts.show', [$version, 'tinjau' => $view, 'sesi' => $session->id]) }}">{{ $isDraft ? 'Perbaiki sesi' : 'Lihat sesi' }}</a>
                                </div>
                            @endforeach
                        </div>
                    @empty
                        <p class="text-sm text-muted">{{ $reviewStatus['current'] ? 'Tidak ada temuan sesuai filter.' : 'Belum ada hasil pemeriksaan untuk revisi ini.' }}</p>
                    @endforelse
                </x-card>
            </div>

            <div x-show="section === 'tinjauan'">
                <x-card padding="p-4" class="admin-table-card flex flex-col gap-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <nav aria-label="Pengelompokan jadwal" class="inline-flex items-center rounded-control border border-border bg-page p-0.5 text-xs font-semibold">
                            @foreach (['kelas' => 'Per kelas', 'dosen' => 'Per dosen', 'ruang' => 'Per ruang'] as $key => $label)
                                <a href="{{ route('admin.drafts.inspect', [$version, 'tinjau' => $key]) }}"
                                   @if ($view === $key) aria-current="page" @endif
                                   @class([
                                       'rounded-[6px] px-2.5 py-1 transition',
                                       'bg-primary text-white shadow-2xs' => $view === $key,
                                       'text-muted hover:text-ink' => $view !== $key,
                                   ])>{{ $label }}</a>
                            @endforeach
                        </nav>
                        <x-table-density-toggle />
                    </div>

                    @forelse ($groups as $name => $rows)
                        <x-table-scroll>
                            <x-slot:title>{{ $name }} • {{ $rows->count() }} sesi</x-slot:title>
                            <table class="w-full text-left text-sm">
                                <thead class="sticky top-0 z-10 border-b border-border bg-page text-xs font-semibold text-muted">
                                    <tr>
                                        <th scope="col" class="px-4 py-2 font-medium">Waktu (WITA)</th>
                                        <th scope="col" class="px-4 py-2 font-medium">Mata kuliah</th>
                                        <th scope="col" class="px-4 py-2 font-medium">Kelas</th>
                                        <th scope="col" class="px-4 py-2 font-medium">Ruang</th>
                                        <th scope="col" class="px-4 py-2 font-medium">Tim pengajar</th>
                                        <th scope="col" class="px-4 py-2 font-medium"><span class="sr-only">Aksi</span></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border">
                                    @foreach ($rows as $session)
                                        <tr @class(['bg-danger-soft/60' => $problemSessionIds->contains($session->id)])>
                                            <td class="px-4 py-3 whitespace-nowrap">
                                                @if ($session->mode === SessionMode::Unscheduled || $session->day_of_week === null)
                                                    <x-status-badge variant="warning">Belum terjadwal</x-status-badge>
                                                @else
                                                    {{ Wita::dayName($session->day_of_week) }} {{ $clock($session->start_minute) }}–{{ $clock($session->end_minute) }}
                                                @endif
                                            </td>
                                            <td class="px-4 py-3 font-medium">
                                                <span class="inline-flex items-center gap-1.5">
                                                    <span>{{ $session->title }}</span>
                                                    @if ($session->is_locked)
                                                        <i data-lucide="lock" class="size-3.5 text-primary shrink-0" title="Sesi dikunci" aria-hidden="true"></i>
                                                    @endif
                                                </span>
                                            </td>
                                            <td class="px-4 py-3">{{ $session->participants->pluck('name')->implode(' + ') ?: '—' }}</td>
                                            <td class="px-4 py-3 whitespace-nowrap">{{ match ($session->mode) { SessionMode::Online => 'Online', SessionMode::Unscheduled => '—', default => $session->room?->name ?? 'Tanpa ruang' } }}</td>
                                            <td class="px-4 py-3 text-muted">{{ $session->lecturers->map(fn ($lecturer) => ($lecturer->pivot->role === 'lead' ? 'Pengampu: ' : 'Mitra: ').$lecturer->name)->implode(' • ') ?: '—' }}</td>
                                            <td class="px-4 py-3 text-right whitespace-nowrap"><a href="{{ route('admin.drafts.show', [$version, 'sesi' => $session->id, 'tinjau' => $view]) }}" class="font-semibold text-primary hover:underline">{{ $isDraft ? 'Perbaiki / lihat' : 'Lihat sesi' }}</a></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </x-table-scroll>
                    @empty
                        <p class="text-sm text-muted">Belum ada sesi pada draf ini.</p>
                    @endforelse
                </x-card>
            </div>
        </div>
    </div>
@endsection
