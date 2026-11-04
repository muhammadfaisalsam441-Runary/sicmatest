@use('App\Enums\IssueSeverity')
@use('App\Enums\SessionMode')
@use('App\Enums\SessionPhase')
@use('App\Support\Wita')
@extends('layouts.admin')

@section('title', 'Susun Jadwal')
@section('breadcrumb', 'Susun Jadwal|'.($isDraft ? 'Draf v'.$version->version_no : 'Resmi v'.$version->version_no))

@section('breadcrumb-actions')
    <x-button variant="secondary" :href="route('admin.schedules.index')">Kelola Jadwal</x-button>
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
                @if ($isDraft)
                    <form method="POST" action="{{ route('admin.drafts.validate', $version) }}">
                        @csrf
                        <x-button type="submit" variant="soft" icon="refresh-cw">Periksa ulang</x-button>
                    </form>
                    @if ($canPublish)
                        <x-button :variant="$blocking->isEmpty() ? 'primary' : 'secondary'" x-data x-on:click="$dispatch('open-modal', 'publish-draft')">Tetapkan jadwal</x-button>
                    @endif
                @endif
            </x-slot:actions>
        </x-page-header>

        <x-card>
            <x-stepper :steps="['Data', 'Tim mengajar', 'Draf jadwal', 'Periksa', 'Tetapkan']" :current="$isDraft ? ($blocking->isEmpty() ? 4 : 3) : 6" />
        </x-card>

        <x-card class="flex flex-wrap gap-x-8 gap-y-2 text-sm" padding="px-6 py-4">
            <span><span class="font-semibold">{{ $version->source->label() }}</span> {{ Wita::stamp($version->created_at) }}</span>
            <span><span class="font-semibold">Status draf:</span> {{ $blocking->isEmpty() ? 'siap ditetapkan' : 'perlu pemeriksaan' }}</span>
            <span><span class="font-semibold">Sesi</span> {{ $counts['total'] }} total • {{ $counts['timed'] }} punya waktu • {{ $counts['unscheduled'] }} tanpa waktu • {{ $counts['online'] }} online</span>
            <span><span class="font-semibold">Dikunci</span> {{ $counts['locked'] }} sesi</span>
            <span><span class="font-semibold">Berlaku mulai</span> {{ Wita::date($version->effective_from) }}</span>
        </x-card>

        @if ($isDraft)
            <x-card class="flex flex-col gap-3">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold">{{ $blocking->isEmpty() ? 'Tidak ada masalah wajib' : 'Selesaikan '.$blocking->count().' masalah wajib dulu.' }}</h2>
                    <span class="text-xs text-muted">Dosen diperiksa sesuai periode sebelum atau setelah UTS.</span>
                </div>
                @foreach ($blocking as $issue)
                    <div class="flex items-start justify-between gap-4 rounded-control bg-danger-soft px-4 py-3 text-sm">
                        <span><x-status-badge variant="danger">Masalah wajib</x-status-badge> <span class="ml-2 text-ink">{{ $issue->message }}</span></span>
                        @if (! empty($issue->session_ids))
                            <a href="{{ route('admin.drafts.show', [$version, 'tinjau' => $view, 'sesi' => $issue->session_ids[0]]) }}" class="shrink-0 font-semibold text-primary hover:underline">Lihat sesi</a>
                        @endif
                    </div>
                @endforeach
                @foreach ($warnings as $issue)
                    <div class="flex items-start justify-between gap-4 rounded-control bg-page px-4 py-3 text-sm ring-1 ring-border">
                        <span><x-status-badge variant="warning">Peringatan</x-status-badge> <span class="ml-2 text-muted">{{ $issue->message }}</span></span>
                    </div>
                @endforeach
            </x-card>
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
                                            <button type="submit" class="inline-flex size-9 items-center justify-center rounded-control border border-border text-primary hover:bg-primary-soft/60" aria-label="{{ $session->is_locked ? 'Buka kunci' : 'Kunci' }} sesi">
                                                <i data-lucide="{{ $session->is_locked ? 'lock' : 'lock-open' }}" class="size-4"></i>
                                            </button>
                                        </form>
                                        @unless ($session->is_locked)
                                            <a href="{{ route('admin.drafts.show', [$version, 'tinjau' => $view, 'sesi' => $session->id]) }}" class="inline-flex size-9 items-center justify-center rounded-control border border-border text-primary hover:bg-primary-soft/60" aria-label="Ubah penempatan {{ $session->title }}">
                                                <i data-lucide="pencil" class="size-4"></i>
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
                        <x-select name="day_of_week" :options="[1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu']" :value="$editing->day_of_week" placeholder="Pilih hari" />
                    </x-field>
                    <x-field label="Jam mulai" name="start_time">
                        <x-input type="time" name="start_time" :value="$editing->start_minute !== null ? sprintf('%02d:%02d', intdiv($editing->start_minute, 60), $editing->start_minute % 60) : null" />
                    </x-field>
                    <x-field label="Jam selesai" name="end_time">
                        <x-input type="time" name="end_time" :value="$editing->end_minute !== null ? sprintf('%02d:%02d', intdiv($editing->end_minute, 60), $editing->end_minute % 60) : null" />
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

    @if ($isDraft && auth()->user()->can('publish-schedule'))
        <x-modal name="publish-draft" :title="'Tetapkan draf v'.$version->version_no.' sebagai jadwal resmi?'" :show="$errors->has('effective_from')" width="max-w-[520px]">
            <div class="flex flex-col gap-4">
                <p class="text-sm text-muted">Setelah ditetapkan, dosen dan ketua tingkat mengikuti jadwal ini. Perubahan berikutnya dibuat sebagai revisi baru.</p>
                <ul class="flex flex-col gap-2 text-sm">
                    @foreach ([
                        [$issues->whereIn('code', ['ROOM_CONFLICT', 'LECTURER_CONFLICT', 'CLASS_CONFLICT'])->isEmpty(), 'Tidak ada bentrok ruang, dosen, atau kelas'],
                        [$issues->where('code', 'LEADERSHIP_THURSDAY')->isEmpty(), 'Pimpinan tidak dijadwalkan hari Kamis'],
                        [$issues->whereIn('code', ['SESSION_WITHOUT_TIME', 'SESSION_WITHOUT_ROOM'])->isEmpty(), 'Seluruh sesi punya waktu dan ruang'],
                        [$issues->where('code', 'RESERVATION_CONFLICT')->isEmpty(), 'Tidak ada reservasi yang terdampak (diperiksa ulang saat disimpan)'],
                        [true, 'Jadwal online tidak mengunci ruang fisik'],
                    ] as [$ok, $label])
                        <li class="flex items-center gap-2 {{ $ok ? 'text-success' : 'text-danger' }}">
                            <i data-lucide="{{ $ok ? 'circle-check' : 'circle-alert' }}" class="size-4"></i>{{ $label }}
                        </li>
                    @endforeach
                </ul>
                <form method="POST" action="{{ route('admin.drafts.publish', $version) }}" class="flex flex-col gap-4">
                    @csrf
                    <input type="hidden" name="lock_version" value="{{ $version->lock_version }}">
                    <x-field label="Berlaku mulai" name="effective_from">
                        <x-input type="date" name="effective_from" :value="max(now()->toDateString(), $version->effective_from->toDateString())" :min="now()->toDateString()" required />
                    </x-field>
                    <x-field label="Catatan revisi (opsional)" name="note">
                        <x-input name="note" placeholder="Contoh: perbaikan bentrok AE 107 Senin" />
                    </x-field>
                    <p class="text-xs text-muted">Sistem memeriksa ulang data terbaru. Jika ada perubahan serentak atau masalah wajib, penetapan dihentikan untuk ditinjau ulang.</p>
                    <div class="flex justify-end gap-3">
                        <x-button variant="secondary" x-on:click="$dispatch('close-modal', 'publish-draft')">Batal</x-button>
                        <x-button type="submit">Tetapkan sebagai resmi</x-button>
                    </div>
                </form>
            </div>
        </x-modal>
    @endif
@endpush
