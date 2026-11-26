@use('App\Enums\IssueSeverity')
@use('App\Enums\SessionMode')
@use('App\Enums\SessionPhase')
@use('App\Support\Wita')
@extends('layouts.admin')

@section('title', 'Susun Jadwal')
@section('breadcrumb', 'Susun Jadwal|'.($isDraft ? 'Draf v'.$version->version_no : 'Resmi v'.$version->version_no))

@section('breadcrumb-actions')
    <x-button variant="secondary" :href="route('admin.schedules.index')">Jadwal Resmi</x-button>
@endsection

@section('content')
    @php
        $blocking = $issues->where('severity', IssueSeverity::Error);
        $warnings = $issues->where('severity', IssueSeverity::Warning);
        $clock = fn (?int $minutes) => $minutes === null ? '—' : sprintf('%02d.%02d', intdiv($minutes, 60), $minutes % 60);
        $canPublish = auth()->user()->can('publish-schedule');
    @endphp

    <div class="flex flex-col gap-4 admin-page-stack">
        <x-page-header :title="($isDraft ? 'Draf jadwal ' : 'Jadwal ').$version->period->name">
            <x-slot:actions>
                @unless ($isDraft)
                    <x-status-badge variant="primary">{{ $version->status->label() }} v{{ $version->version_no }}</x-status-badge>
                @endunless
                <x-button variant="secondary" size="sm" icon="download" :href="route('admin.drafts.excel', [$version, 'tinjau' => $view])" download>Unduh Excel</x-button>
                <x-button variant="secondary" size="sm" icon="download" :href="route('admin.drafts.pdf', [$version, 'tinjau' => $view])" download>Unduh PDF</x-button>
                @if ($isDraft)
                    <x-button size="sm" :href="route('admin.drafts.inspect', $version)">Buka Pemeriksaan</x-button>
                @endif
            </x-slot:actions>
        </x-page-header>

        @include('admin.partials.scheduling-tabs', [
            'version' => $version,
            'isDraft' => $isDraft,
            'reviewStatus' => $reviewStatus,
        ])

        <x-card class="flex flex-wrap items-center gap-x-5 gap-y-1 text-xs text-muted" padding="px-4 py-2.5">
            <span><strong class="text-ink">{{ $version->source->label() }}</strong> {{ Wita::stamp($version->created_at) }}</span>
            <span><strong class="text-ink">{{ $isDraft ? 'Pemeriksaan:' : 'Status versi:' }}</strong> {{ $isDraft ? $reviewStatus['label'] : $version->status->label() }}</span>
            <span><strong class="text-ink">Sesi:</strong> {{ $counts['total'] }} total, {{ $counts['timed'] }} terjadwal, {{ $counts['unscheduled'] }} tanpa waktu, {{ $counts['online'] }} online</span>
            <span><strong class="text-ink">Dikunci:</strong> {{ $counts['locked'] }} sesi</span>
            <span><strong class="text-ink">Berlaku mulai:</strong> {{ Wita::date($version->effective_from) }}</span>
        </x-card>

        <div x-data="{
            searchQuery: '',
            onlyIssues: false,
            collapsedGroups: {},
            allCollapsed: false,
            groupKeys: @js(collect($groups)->keys()->map(fn ($name) => 'g-'.md5((string) $name))->values()->all()),
            toggleGroup(key) {
                this.collapsedGroups[key] = !this.collapsedGroups[key];
                this.allCollapsed = this.groupKeys.length > 0 && this.groupKeys.every((k) => Boolean(this.collapsedGroups[k]));
            },
            toggleAll() {
                const next = !this.allCollapsed;
                this.allCollapsed = next;
                const map = {};
                this.groupKeys.forEach((k) => { map[k] = next; });
                this.collapsedGroups = map;
            },
            rowMatches(search, hasIssue) {
                if (this.onlyIssues && !hasIssue) {
                    return false;
                }
                const q = this.searchQuery.trim().toLowerCase();
                if (!q) {
                    return true;
                }
                return search.includes(q);
            },
            groupVisible(el) {
                if (!this.onlyIssues && !this.searchQuery.trim()) {
                    return true;
                }
                return Array.from(el.querySelectorAll('tbody tr[data-draft-row]')).some((tr) =>
                    this.rowMatches(tr.dataset.search || '', tr.dataset.issue === '1')
                );
            }
        }" class="flex flex-col gap-3">
            <div class="flex flex-wrap items-center justify-between gap-2 rounded-card border border-border bg-surface p-2.5 shadow-xs">
                <div class="flex flex-wrap items-center gap-2">
                    <nav aria-label="Pengelompokan tinjauan" class="inline-flex items-center rounded-control border border-border bg-page p-0.5 text-xs font-semibold">
                        @foreach (['kelas' => 'Per kelas', 'dosen' => 'Per dosen', 'ruang' => 'Per ruang'] as $key => $label)
                            <a href="{{ route('admin.drafts.show', [$version, 'tinjau' => $key]) }}"
                               @if ($view === $key) aria-current="page" @endif
                               @class([
                                   'rounded-[6px] px-2.5 py-1 transition',
                                   'bg-primary text-white shadow-2xs' => $view === $key,
                                   'text-muted hover:text-ink' => $view !== $key,
                               ])>{{ $label }}</a>
                        @endforeach
                    </nav>

                    @if ($groups->isNotEmpty())
                        <div class="relative w-full sm:w-64">
                            <i data-lucide="search" class="pointer-events-none absolute left-2.5 top-1/2 size-3.5 -translate-y-1/2 text-muted" aria-hidden="true"></i>
                            <input type="search"
                                   x-model.debounce.200ms="searchQuery"
                                   data-table-search
                                   aria-label="Cari sesi draf"
                                   placeholder="Cari matkul, kelas, dosen, ruang... (/)"
                                   class="h-8 w-full rounded-control border border-border bg-page pl-8 pr-7 text-xs sm:text-sm text-ink outline-none focus:border-primary focus:ring-2 focus:ring-primary/20" />
                            <button type="button" x-show="searchQuery" x-cloak @click="searchQuery = ''"
                                    class="absolute right-2 top-1/2 -translate-y-1/2 text-muted hover:text-ink cursor-pointer"
                                    title="Hapus pencarian" aria-label="Hapus pencarian">
                                <i data-lucide="x" class="size-3.5" aria-hidden="true"></i>
                            </button>
                        </div>

                        <div class="inline-flex items-center rounded-control border border-border bg-page p-0.5 text-xs font-semibold">
                            <button type="button" @click="onlyIssues = false"
                                    :class="!onlyIssues ? 'bg-surface text-ink shadow-xs' : 'text-muted hover:text-ink'"
                                    class="rounded-[6px] px-2.5 py-1 transition cursor-pointer">
                                Semua Sesi
                            </button>
                            <button type="button" @click="onlyIssues = true"
                                    :class="onlyIssues ? 'bg-danger text-white shadow-xs' : 'text-muted hover:text-ink'"
                                    title="Tampilkan sesi bermasalah atau belum terjadwal"
                                    class="rounded-[6px] px-2.5 py-1 transition cursor-pointer">
                                Bermasalah
                            </button>
                        </div>
                    @endif
                </div>

                @if ($groups->isNotEmpty())
                    <div class="flex flex-wrap items-center gap-1.5">
                        <button type="button" @click="toggleAll()"
                                class="inline-flex items-center gap-1.5 rounded-control border border-border bg-page px-2.5 py-1.5 text-xs font-semibold text-ink hover:border-primary hover:text-primary transition cursor-pointer">
                            <i x-show="!allCollapsed" data-lucide="chevrons-down-up" class="size-3.5" aria-hidden="true"></i>
                            <i x-show="allCollapsed" x-cloak data-lucide="chevrons-up-down" class="size-3.5" aria-hidden="true"></i>
                            <span x-text="allCollapsed ? 'Buka Semua' : 'Ringkas Semua'"></span>
                        </button>
                        <x-table-density-toggle />
                    </div>
                @endif
            </div>

        @forelse ($groups as $groupName => $groupSessions)
            @php
                $groupKey = 'g-'.md5((string) $groupName);
                $issueCount = $groupSessions->filter(fn ($s) => $problemSessionIds->contains($s->id) || $s->mode === SessionMode::Unscheduled || $s->day_of_week === null)->count();
            @endphp
            <div x-show="groupVisible($el)">
            <x-card padding="p-4" class="admin-table-card flex flex-col gap-2.5">
                <button type="button" @click="toggleGroup('{{ $groupKey }}')"
                        class="flex w-full items-center justify-between gap-3 text-left cursor-pointer group">
                    <h3 class="text-base font-semibold group-hover:text-primary transition-colors">
                        {{ $groupName }}
                        <span class="text-xs sm:text-sm font-normal text-muted">• {{ $groupSessions->count() }} sesi</span>
                        @if ($issueCount > 0)
                            <span class="ml-1.5 inline-flex items-center rounded-full bg-danger-soft px-2 py-0.5 text-xs font-semibold text-danger">{{ $issueCount }} perhatian</span>
                        @endif
                    </h3>
                    <span class="inline-flex shrink-0 items-center gap-1 text-xs font-medium text-muted">
                        <span x-text="collapsedGroups['{{ $groupKey }}'] ? 'Buka' : 'Ringkas'"></span>
                        <i data-lucide="chevron-down" class="size-4 transition-transform" :class="collapsedGroups['{{ $groupKey }}'] ? '-rotate-90' : ''" aria-hidden="true"></i>
                    </span>
                </button>
                <x-table-scroll x-show="!collapsedGroups['{{ $groupKey }}']">
                    <x-slot:title>{{ $groupName }} • {{ $groupSessions->count() }} sesi</x-slot:title>
                    <table class="w-full text-left text-sm">
                        <thead class="sticky top-0 z-10 border-b border-border bg-page text-xs font-semibold text-muted">
                            <tr>
                                <th scope="col" class="px-4 py-2 font-medium">Hari & jam (WITA)</th>
                                <th scope="col" class="px-4 py-2 font-medium">Mata kuliah</th>
                                <th scope="col" class="px-4 py-2 font-medium">Kelas</th>
                                <th scope="col" class="px-4 py-2 font-medium">Ruang</th>
                                <th scope="col" class="px-4 py-2 font-medium">Tim / periode</th>
                                <th scope="col" class="px-4 py-2 font-medium"><span class="sr-only">Aksi</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @foreach ($groupSessions as $session)
                                @php
                                    $rowHasIssue = $problemSessionIds->contains($session->id) || $session->mode === SessionMode::Unscheduled || $session->day_of_week === null;
                                    $rowSearch = strtolower(implode(' ', array_filter([
                                        (string) $groupName,
                                        $session->title,
                                        $session->participants->pluck('name')->implode(' '),
                                        match ($session->mode) {
                                            SessionMode::Online => 'online',
                                            SessionMode::Unscheduled => 'belum terjadwal',
                                            default => $session->room?->name ?? 'tanpa ruang',
                                        },
                                        $session->lecturers->pluck('name')->implode(' '),
                                    ])));
                                @endphp
                                <tr data-draft-row
                                    data-search="{{ $rowSearch }}"
                                    data-issue="{{ $rowHasIssue ? '1' : '0' }}"
                                    x-show="rowMatches($el.dataset.search || '', $el.dataset.issue === '1')"
                                    @class(['bg-danger-soft/60' => $problemSessionIds->contains($session->id)])>
                                    <td class="px-4 py-3">
                                        @if ($session->mode === SessionMode::Unscheduled || $session->day_of_week === null)
                                            <x-status-badge variant="warning">Belum terjadwal</x-status-badge>
                                            @if ($session->source_raw['waktu'] ?? null)<span class="block text-xs text-muted">Sumber: {{ $session->source_raw['waktu'] }}</span>@endif
                                        @else
                                            {{ Wita::dayName($session->day_of_week) }} {{ $clock($session->start_minute) }}–{{ $clock($session->end_minute) }}
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 font-medium">{{ $session->title }}</td>
                                    <td class="px-4 py-3">{{ $session->participants->pluck('name')->implode(' + ') }}</td>
                                    <td class="px-4 py-3">{{ match ($session->mode) { SessionMode::Online => 'Online', SessionMode::Unscheduled => '—', default => $session->room?->name ?? 'Tanpa ruang' } }}</td>
                                    <td class="px-4 py-3 text-muted">
                                        {{ $session->lecturers->map(fn ($lecturer) => ($lecturer->pivot->role === 'lead' ? 'Pengampu: ' : 'Mitra: ').$lecturer->name)->implode(' • ') ?: '—' }}
                                        @unless ($session->phase === SessionPhase::Full)<span class="block text-xs">{{ strtolower($session->phase->label()) }}</span>@endunless
                                    </td>
                                    <td class="px-4 py-3 text-right whitespace-nowrap">
                                        @if ($isDraft)
                                            <form method="POST" action="{{ route('admin.drafts.sessions.lock', [$version, $session]) }}" class="inline">
                                                @csrf
                                                <button type="submit" class="inline-flex size-8 items-center justify-center rounded-control border border-border bg-surface text-primary hover:bg-primary-soft hover:border-primary/40 transition cursor-pointer" aria-label="{{ $session->is_locked ? 'Buka kunci' : 'Kunci' }} sesi" title="{{ $session->is_locked ? 'Buka kunci' : 'Kunci' }} sesi">
                                                    <i data-lucide="{{ $session->is_locked ? 'lock' : 'lock-open' }}" class="size-3.5" aria-hidden="true"></i>
                                                </button>
                                            </form>
                                            @if ($session->is_locked)
                                                <button type="button" disabled class="inline-flex size-8 items-center justify-center rounded-control border border-border bg-page text-muted opacity-50 cursor-not-allowed" aria-label="Edit dinonaktifkan karena {{ $session->title }} terkunci" title="Buka kunci sesi terlebih dahulu untuk mengubah penempatan">
                                                    <i data-lucide="pencil" class="size-3.5" aria-hidden="true"></i>
                                                </button>
                                            @else
                                                <a href="{{ route('admin.drafts.show', [$version, 'tinjau' => $view, 'sesi' => $session->id]) }}" class="inline-flex size-8 items-center justify-center rounded-control border border-border bg-surface text-primary hover:bg-primary-soft hover:border-primary/40 transition cursor-pointer" aria-label="Ubah penempatan {{ $session->title }}" title="Ubah penempatan {{ $session->title }}">
                                                    <i data-lucide="pencil" class="size-3.5" aria-hidden="true"></i>
                                                </a>
                                            @endif
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-table-scroll>
            </x-card>
            </div>
        @empty
            <x-empty-state title="Belum ada jadwal" message="Impor jadwal perkuliahan atau susun otomatis agar draf berisi sesi." icon="calendar">
                <x-button variant="soft" :href="route('admin.imports.create')">Impor Excel atau CSV</x-button>
            </x-empty-state>
        @endforelse
        </div>
    </div>
@endsection

@push('dialogs')
    @if ($isDraft && $editing)
        <x-modal name="edit-session" title="Ubah jadwal" :show="true" width="max-w-[520px]">
            <p class="-mt-3 mb-4 text-sm text-muted">{{ $editing->participants->pluck('name')->implode(' + ') }} • {{ $editing->title }}. Perubahan masuk ke draf v{{ $version->version_no }}; jadwal resmi tidak berubah sampai draf ditetapkan.</p>
            <form method="POST" action="{{ route('admin.drafts.sessions.update', [$version, $editing, 'tinjau' => $view]) }}" class="flex flex-col gap-4" x-data="{ mode: @js(old('mode', $editing->mode->value)) }">
                @csrf
                @method('PUT')
                <x-field label="Mode perkuliahan" name="mode" hint="Online tidak memesan ruang; waktu dosen dan kelas tetap diperiksa.">
                    <x-select name="mode" x-model="mode" :options="['offline' => 'Tatap muka', 'online' => 'Online', 'unscheduled' => 'Belum terjadwal']" :value="$editing->mode->value" />
                </x-field>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-3" x-show="mode !== 'unscheduled'">
                    <x-field label="Hari" name="day_of_week">
                        <x-select name="day_of_week" :options="collect(config('sicma.scheduler.days'))->mapWithKeys(fn ($day) => [$day => Wita::dayName($day)])" :value="$editing->day_of_week" placeholder="Pilih hari" />
                    </x-field>
                    <x-field label="Jam mulai" name="start_time">
                        <x-input type="time" name="start_time" :min="config('sicma.operating_hours.start')" :max="config('sicma.operating_hours.end')" :value="$editing->start_minute !== null ? sprintf('%02d:%02d', intdiv($editing->start_minute, 60), $editing->start_minute % 60) : null" />
                    </x-field>
                    <x-field label="Jam selesai" name="end_time">
                        <x-input type="time" name="end_time" :min="config('sicma.operating_hours.start')" :max="config('sicma.operating_hours.end')" :value="$editing->end_minute !== null ? sprintf('%02d:%02d', intdiv($editing->end_minute, 60), $editing->end_minute % 60) : null" />
                    </x-field>
                </div>
                <div x-show="mode === 'offline'" class="flex flex-col gap-2">
                    <x-field label="Ruang" name="room_id">
                        <x-select name="room_id" :options="$rooms" :value="$editing->room_id" placeholder="Pilih ruang" />
                    </x-field>
                    @if ($alternatives->isNotEmpty())
                        <p class="text-xs text-muted">Ruang lain yang tidak bentrok pada jadwal tersimpan ({{ $editing->day_of_week ? Wita::dayName($editing->day_of_week).' '.$clock($editing->start_minute).'–'.$clock($editing->end_minute).' WITA' : 'slot ini' }}): {{ $alternatives->take(8)->pluck('name')->implode(', ') }}{{ $alternatives->count() > 8 ? ', …' : '' }}</p>
                    @endif
                </div>
                <x-field label="Catatan revisi (opsional)" name="note">
                    <x-input name="note" placeholder="Contoh: perbaikan bentrok AE 107 Senin" />
                </x-field>
                <div class="flex flex-wrap justify-end gap-3">
                    <x-button variant="secondary" :href="route('admin.drafts.show', [$version, 'tinjau' => $view])">Batal</x-button>
                    <x-button type="submit">Simpan ke draf</x-button>
                </div>
            </form>
        </x-modal>
    @endif

@endpush
