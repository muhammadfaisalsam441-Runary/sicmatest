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

    <div class="flex flex-col gap-6">
        <x-page-header :subtitle="$isDraft
            ? ($version->basedOn ? 'Jadwal resmi v'.$version->basedOn->version_no.' tetap dipakai aplikasi ketua tingkat sampai draf ini ditetapkan.' : 'Jadwal resmi yang berlaku tetap dipakai sampai draf ini ditetapkan.')
            : 'Versi ini '.strtolower($version->status->label()).'. Perubahan dibuat lewat draf revisi baru.'"
            :title="($isDraft ? 'Draf jadwal ' : 'Jadwal ').$version->period->name">
            <x-slot:actions>
                <x-status-badge variant="primary">{{ $isDraft ? 'Draf' : $version->status->label() }} v{{ $version->version_no }}</x-status-badge>
                <x-button variant="secondary" icon="download" :href="route('admin.drafts.excel', [$version, 'tinjau' => $view])" download>Unduh Excel</x-button>
                <x-button variant="secondary" icon="download" :href="route('admin.drafts.pdf', [$version, 'tinjau' => $view])" download>Unduh PDF</x-button>
                @if ($isDraft)
                    <x-button :href="route('admin.drafts.inspect', $version)">Buka Pemeriksaan</x-button>
                @endif
            </x-slot:actions>
        </x-page-header>

        <x-card>
            <x-schedule-steps :current="$isDraft ? 3 : 5" :version="$version" />
        </x-card>

        @include('admin.partials.scheduling-tabs', [
            'version' => $version,
            'isDraft' => $isDraft,
            'reviewStatus' => $reviewStatus,
        ])

        <x-card class="flex flex-wrap gap-x-8 gap-y-2 text-sm" padding="px-6 py-4">
            <span><span class="font-semibold">{{ $version->source->label() }}</span> {{ Wita::stamp($version->created_at) }}</span>
            <span><span class="font-semibold">{{ $isDraft ? 'Pemeriksaan:' : 'Status versi:' }}</span> {{ $isDraft ? $reviewStatus['label'] : $version->status->label() }}</span>
            <span><span class="font-semibold">Sesi</span> {{ $counts['total'] }} total • {{ $counts['timed'] }} punya waktu • {{ $counts['unscheduled'] }} tanpa waktu • {{ $counts['online'] }} online</span>
            <span><span class="font-semibold">Dikunci</span> {{ $counts['locked'] }} sesi</span>
            <span><span class="font-semibold">Berlaku mulai</span> {{ Wita::date($version->effective_from) }}</span>
        </x-card>

        @if ($isDraft)
            <x-info-box title="Pemeriksaan tersedia pada halaman tersendiri">
                <p>Temuan wajib, peringatan, dan kesiapan penetapan dapat dipantau melalui <a class="font-semibold text-primary hover:underline" href="{{ route('admin.drafts.inspect', $version) }}">Pemeriksaan Jadwal</a>.</p>
            </x-info-box>
        @endif

        <x-tabs :items="collect(['kelas' => 'Tinjau per kelas', 'dosen' => 'Tinjau per dosen', 'ruang' => 'Tinjau per ruang'])
            ->map(fn ($label, $key) => ['label' => $label, 'href' => route('admin.drafts.show', [$version, 'tinjau' => $key]), 'active' => $view === $key])->values()->all()" />

        @forelse ($groups as $groupName => $groupSessions)
            <x-card class="flex flex-col gap-3">
                <h3 class="text-lg font-semibold">{{ $groupName }} <span class="text-sm font-normal text-muted">• {{ $groupSessions->count() }} sesi</span></h3>
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-border text-xs text-muted">
                        <tr>
                            <th class="py-2 pr-4 font-medium">Hari & jam (WITA)</th>
                            <th class="py-2 pr-4 font-medium">Mata kuliah</th>
                            <th class="py-2 pr-4 font-medium">Kelas</th>
                            <th class="py-2 pr-4 font-medium">Ruang</th>
                            <th class="py-2 pr-4 font-medium">Tim / periode</th>
                            <th class="py-2 font-medium"><span class="sr-only">Aksi</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @foreach ($groupSessions as $session)
                            <tr @class(['bg-danger-soft/60' => $problemSessionIds->contains($session->id)])>
                                <td class="py-3 pr-4">
                                    @if ($session->mode === SessionMode::Unscheduled || $session->day_of_week === null)
                                        <x-status-badge variant="warning">Belum terjadwal</x-status-badge>
                                        @if ($session->source_raw['waktu'] ?? null)<span class="block text-xs text-muted">Sumber: {{ $session->source_raw['waktu'] }}</span>@endif
                                    @else
                                        {{ Wita::dayName($session->day_of_week) }} {{ $clock($session->start_minute) }}–{{ $clock($session->end_minute) }}
                                    @endif
                                </td>
                                <td class="py-3 pr-4 font-medium">{{ $session->title }}</td>
                                <td class="py-3 pr-4">{{ $session->participants->pluck('name')->implode(' + ') }}</td>
                                <td class="py-3 pr-4">{{ match ($session->mode) { SessionMode::Online => 'Online', SessionMode::Unscheduled => '—', default => $session->room?->name ?? 'Tanpa ruang' } }}</td>
                                <td class="py-3 pr-4 text-muted">
                                    {{ $session->lecturers->map(fn ($lecturer) => ($lecturer->pivot->role === 'lead' ? 'Pengampu: ' : 'Mitra: ').$lecturer->name)->implode(' • ') ?: '—' }}
                                    @unless ($session->phase === SessionPhase::Full)<span class="block text-xs">{{ strtolower($session->phase->label()) }}</span>@endunless
                                </td>
                                <td class="py-3 text-right whitespace-nowrap">
                                    @if ($isDraft)
                                        <form method="POST" action="{{ route('admin.drafts.sessions.lock', [$version, $session]) }}" class="inline">
                                            @csrf
                                            <button type="submit" class="inline-flex size-8 items-center justify-center rounded-control border border-border bg-surface text-primary hover:bg-primary-soft hover:border-primary/40 transition cursor-pointer" aria-label="{{ $session->is_locked ? 'Buka kunci' : 'Kunci' }} sesi" title="{{ $session->is_locked ? 'Buka kunci' : 'Kunci' }} sesi">
                                                <i data-lucide="{{ $session->is_locked ? 'lock' : 'lock-open' }}" class="size-3.5"></i>
                                            </button>
                                        </form>
                                        @unless ($session->is_locked)
                                            <a href="{{ route('admin.drafts.show', [$version, 'tinjau' => $view, 'sesi' => $session->id]) }}" class="inline-flex size-8 items-center justify-center rounded-control border border-border bg-surface text-primary hover:bg-primary-soft hover:border-primary/40 transition cursor-pointer" aria-label="Ubah penempatan {{ $session->title }}" title="Ubah penempatan {{ $session->title }}">
                                                <i data-lucide="pencil" class="size-3.5"></i>
                                            </a>
                                        @endunless
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-card>
        @empty
            <x-empty-state title="Belum ada jadwal" message="Impor jadwal perkuliahan atau susun otomatis agar draf berisi sesi." icon="calendar">
                <x-button variant="soft" :href="route('admin.imports.create')">Impor Excel atau CSV</x-button>
            </x-empty-state>
        @endforelse
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
                <div class="grid grid-cols-3 gap-3" x-show="mode !== 'unscheduled'">
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
                        <p class="text-xs text-muted">Pilihan lain yang tidak bentrok pada jam ini: {{ $alternatives->take(8)->pluck('name')->implode(', ') }}{{ $alternatives->count() > 8 ? ', …' : '' }}</p>
                    @endif
                </div>
                <x-field label="Catatan revisi (opsional)" name="note">
                    <x-input name="note" placeholder="Contoh: perbaikan bentrok AE 107 Senin" />
                </x-field>
                <div class="flex justify-end gap-3">
                    <x-button variant="secondary" :href="route('admin.drafts.show', [$version, 'tinjau' => $view])">Batal</x-button>
                    <x-button type="submit">Simpan ke draf</x-button>
                </div>
            </form>
        </x-modal>
    @endif

@endpush
