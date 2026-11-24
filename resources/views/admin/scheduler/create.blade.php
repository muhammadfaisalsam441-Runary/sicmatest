@use('App\Enums\OptimizationJobStatus')
@use('App\Services\Scheduling\SchedulerService')
@use('App\Support\Wita')
@extends('layouts.admin')

@section('title', 'Penyusunan & Draf')
@section('breadcrumb', 'Penyusunan & Draf')

@section('content')
    @php
        $scope = old('scope', 'semester');
        $openSchedulerModal = $readiness !== null
            || $errors->hasAny(['scope', 'class_ids', 'class_ids.*', 'time_limit'])
            || request()->boolean('susun');
        $openImportModal = $errors->hasAny(['period_id', 'file'])
            || request()->boolean('impor');
        $hasRunningJob = $lastJob && in_array($lastJob->status, [OptimizationJobStatus::Queued, OptimizationJobStatus::Running], true);
    @endphp

    <div class="flex flex-col gap-6">
        <x-page-header title="Penyusunan & Draf Jadwal"
                       :subtitle="($period?->name ?? 'Belum ada semester aktif').' • Kelola draf jadwal, impor Excel/CSV, atau susun otomatis sebelum ditetapkan.'.($official ? ' Jadwal resmi v'.$official->version_no.' tetap dilindungi.' : '')">
            @if ($period)
                <x-slot:actions>
                    <x-button size="sm" icon="sparkles" x-data x-on:click="$dispatch('open-modal', 'auto-scheduler-modal')">
                        Susun Otomatis
                    </x-button>
                    <x-button variant="secondary" size="sm" icon="upload" x-data x-on:click="$dispatch('open-modal', 'import-schedule-modal')">
                        Impor Excel / CSV
                    </x-button>
                    <form method="POST" action="{{ route('admin.drafts.store') }}">
                        @csrf
                        <x-button type="submit" variant="secondary" size="sm" icon="plus">Tambah Draf</x-button>
                    </form>
                    <x-button variant="soft" size="sm" icon="history" x-data x-on:click="$dispatch('open-modal', 'scheduler-history-modal')">
                        Riwayat
                    </x-button>
                </x-slot:actions>
            @endif
        </x-page-header>

        @if (! $period)
            <x-empty-state title="Belum ada semester aktif" message="Atur semester di Data Akademik lebih dulu." icon="calendar" />
        @else
            @if ($hasRunningJob)
                @php
                    $runningCode = 'J-'.$lastJob->created_at->format('Ymd').'-'.str_pad((string) $lastJob->id, 2, '0', STR_PAD_LEFT);
                    $runningScope = SchedulerService::SCOPES[$lastJob->scope['type'] ?? 'semester'] ?? 'Seluruh semester';
                @endphp
                <x-card class="flex flex-wrap items-center justify-between gap-4 border-primary/30 bg-primary-soft/40" padding="px-5 py-4">
                    <div class="flex items-start gap-3">
                        <span class="mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-control bg-primary text-white">
                            <i data-lucide="clock" class="size-4" aria-hidden="true"></i>
                        </span>
                        <div class="flex flex-col gap-0.5">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-sm font-semibold text-ink">{{ $runningCode }} • {{ $lastJob->status->label() }}</span>
                                <x-status-badge variant="primary" :pulse="true">{{ $runningScope }}</x-status-badge>
                            </div>
                            <p class="text-xs text-muted">Penyusunan otomatis sedang berjalan. Hasil akan muncul sebagai draf baru setelah selesai.</p>
                        </div>
                    </div>
                    <x-button size="sm" :href="route('admin.scheduler.show', $lastJob)">Lihat Progres</x-button>
                </x-card>
            @endif

            @if ($versions->isEmpty())
                <x-empty-state title="Belum ada draf jadwal"
                               message="Mulai dengan menyusun otomatis, mengimpor file Excel/CSV, atau membuat draf manual dari jadwal resmi."
                               icon="calendar-days">
                    <div class="flex flex-wrap items-center justify-center gap-2.5">
                        <x-button size="sm" icon="sparkles" x-data x-on:click="$dispatch('open-modal', 'auto-scheduler-modal')">
                            Susun Otomatis
                        </x-button>
                        <x-button variant="secondary" size="sm" icon="upload" x-data x-on:click="$dispatch('open-modal', 'import-schedule-modal')">
                            Impor Excel / CSV
                        </x-button>
                        <form method="POST" action="{{ route('admin.drafts.store') }}">
                            @csrf
                            <x-button type="submit" variant="secondary" size="sm" icon="plus">Buat Draf Manual</x-button>
                        </form>
                    </div>
                </x-empty-state>
            @else
                @php
                    $activeCard = $versions->first();
                    $otherVersions = $versions->slice(1);
                    $isReady = $activeReviewStatus['ready'] ?? false;
                    $activeBadgeVariant = $isReady
                        ? 'success'
                        : ($activeCard->issues_count === 0 ? 'neutral' : 'warning');
                    $activeBadgeLabel = $isReady
                        ? ($activeReviewStatus['label'] ?? 'Siap ditetapkan')
                        : ($activeCard->issues_count === 0
                            ? ($activeReviewStatus['label'] ?? 'Belum diperiksa')
                            : $activeCard->issues_count.' temuan wajib');
                @endphp

                <x-card class="flex flex-col gap-4 border-primary/35 bg-primary-soft/20">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                        <div class="flex flex-col gap-1.5">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="rounded-md bg-primary px-2 py-0.5 text-xs font-semibold text-white">Draf Aktif</span>
                                <h2 class="text-lg font-semibold text-ink">Draf v{{ $activeCard->version_no }} sedang dikerjakan</h2>
                                <x-status-badge :variant="$activeBadgeVariant">{{ $activeBadgeLabel }}</x-status-badge>
                            </div>
                            <p class="text-sm text-muted">
                                {{ $activeCard->source->label() }} • {{ $activeCard->sessions_count }} sesi • Diperbarui {{ Wita::stamp($activeCard->updated_at) }}
                            </p>
                            <p class="text-xs text-muted">
                                @if ($activeReviewStatus['checked_at'] ?? null)
                                    Pemeriksaan terakhir {{ Wita::stamp($activeReviewStatus['checked_at']) }} ({{ $activeReviewStatus['label'] }})
                                @else
                                    Belum diperiksa — jalankan pemeriksaan bentrok sebelum menetapkan jadwal.
                                @endif
                            </p>
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            <x-button variant="secondary" size="sm" icon="pencil" :href="route('admin.drafts.show', $activeCard)">
                                1. Edit Sesi
                            </x-button>
                            <x-button :variant="$isReady ? 'secondary' : 'primary'" size="sm" icon="list-checks" :href="route('admin.drafts.inspect', $activeCard)">
                                2. Pemeriksaan
                            </x-button>
                            @if ($isReady)
                                <x-button size="sm" icon="check-circle" :href="route('admin.schedules.index', ['tab' => 'penetapan', 'draf' => $activeCard->id])">
                                    3. Tetapkan Jadwal
                                </x-button>
                            @endif
                            <x-button type="button"
                                      variant="danger-outline"
                                      size="sm"
                                      icon="archive"
                                      x-data
                                      x-on:click="$dispatch('open-archive-draft', { versionNo: {{ $activeCard->version_no }}, action: @js(route('admin.drafts.archive', $activeCard)), isActive: true }); $dispatch('open-modal', 'archive-draft-modal')">
                                Arsipkan
                            </x-button>
                        </div>
                    </div>
                </x-card>

                @if ($otherVersions->isNotEmpty())
                    <x-card padding="p-0" class="overflow-hidden">
                        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-border bg-page/60 px-6 py-4">
                            <div class="flex items-center gap-2">
                                <h2 class="text-base font-semibold text-ink">Draf Lainnya</h2>
                                <span class="rounded-full border border-border bg-surface px-2.5 py-0.5 text-xs font-semibold text-muted">
                                    {{ $otherVersions->count() }} draf
                                </span>
                            </div>
                            <p class="text-xs text-muted">Pilih Gunakan untuk mengganti draf aktif, atau Arsipkan jika tidak dipakai.</p>
                        </div>

                        <div class="divide-y divide-border">
                            @foreach ($otherVersions as $version)
                                <div class="flex flex-wrap items-center justify-between gap-4 px-6 py-4 transition hover:bg-page/40">
                                    <div class="flex flex-col gap-1">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="text-base font-semibold text-ink">Draf v{{ $version->version_no }}</span>
                                            <x-status-badge :variant="$version->issues_count === 0 ? 'success' : 'warning'">
                                                {{ $version->issues_count === 0 ? '0 masalah wajib' : $version->issues_count.' temuan wajib' }}
                                            </x-status-badge>
                                        </div>
                                        <p class="text-sm text-muted">
                                            {{ $version->source->label() }} • {{ $version->sessions_count }} sesi • {{ Wita::stamp($version->created_at) }}
                                        </p>
                                    </div>

                                    <div class="flex flex-wrap items-center gap-2">
                                        <form method="POST" action="{{ route('admin.drafts.activate', $version) }}">
                                            @csrf
                                            <input type="hidden" name="redirect" value="workspace">
                                            <x-button type="submit" variant="soft" size="sm" icon="check">Gunakan</x-button>
                                        </form>
                                        <x-button variant="secondary" size="sm" icon="pencil" :href="route('admin.drafts.show', $version)">Edit</x-button>
                                        <x-button variant="secondary" size="sm" icon="list-checks" :href="route('admin.drafts.inspect', $version)">Periksa</x-button>
                                        <x-button type="button"
                                                  variant="danger-outline"
                                                  size="sm"
                                                  icon="archive"
                                                  x-data
                                                  x-on:click="$dispatch('open-archive-draft', { versionNo: {{ $version->version_no }}, action: @js(route('admin.drafts.archive', $version)), isActive: false }); $dispatch('open-modal', 'archive-draft-modal')">
                                            Arsipkan
                                        </x-button>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </x-card>
                @endif
            @endif
        @endif
    </div>
@endsection

@push('dialogs')
    @if ($period)
        <x-modal name="auto-scheduler-modal" title="Susun jadwal otomatis" width="max-w-4xl" :show="$openSchedulerModal">
            <div class="flex flex-col gap-5">
                @if ($readiness)
                    <div class="flex flex-col gap-2.5 rounded-card border border-border bg-page/60 p-4">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <h3 class="text-base font-semibold text-ink">{{ $readiness['errors'] === [] ? 'Siap disusun sebagai draf' : 'Data belum siap disusun' }}</h3>
                            <span class="text-xs text-muted">{{ $readiness['in_scope'] }} sesi dalam cakupan • {{ $readiness['fixed'] }} sesi tetap diperhitungkan</span>
                        </div>
                        @foreach ($readiness['errors'] as $error)
                            <x-alert variant="danger">{{ $error }}</x-alert>
                        @endforeach
                        @foreach ($readiness['warnings'] as $warning)
                            <x-alert variant="warning">{{ $warning }}</x-alert>
                        @endforeach
                    </div>
                @endif

                <form method="POST" action="{{ route('admin.scheduler.store') }}" class="flex flex-col gap-5" x-data="{ scope: @js($scope) }">
                    @csrf

                    <div class="flex flex-col gap-3">
                        <p class="text-sm font-semibold text-ink">Tentukan cakupan penyusunan</p>
                        <div class="grid grid-cols-1 gap-2.5 sm:grid-cols-2 lg:grid-cols-4">
                            @foreach (SchedulerService::SCOPES as $value => $label)
                                <label class="flex cursor-pointer items-center gap-2 rounded-control border px-3.5 py-2.5 text-sm font-medium transition"
                                       :class="scope === @js($value) ? 'border-primary bg-primary-soft text-primary' : 'border-border hover:border-primary/40'">
                                    <input type="radio" name="scope" value="{{ $value }}" x-model="scope" class="accent-primary">
                                    <span>{{ $label }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>

                    @php
                        $groupedClasses = $classGroups->groupBy([
                            fn ($c) => $c->study_program ?: 'Program Studi Lainnya',
                            fn ($c) => $c->cohort_year ? 'Angkatan '.$c->cohort_year : 'Lainnya / Khusus',
                        ]);
                        $studyPrograms = $classGroups->pluck('study_program')->filter()->unique()->values();
                        $searchableClasses = $classGroups->map(fn ($c) => [
                            'name' => strtolower($c->name),
                            'prodi' => $c->study_program ?: 'Program Studi Lainnya',
                            'prodiLower' => strtolower($c->study_program ?? ''),
                            'cohort' => (string) ($c->cohort_year ?? ''),
                        ])->values();
                    @endphp

                    <div x-show="scope === 'kelas'"
                         x-cloak
                         x-data="{
                             search: '',
                             prodiFilter: 'semua',
                             selected: @js(array_map('strval', old('class_ids', []))),
                             allIds: @js($classGroups->pluck('id')->map(fn ($id) => (string) $id)->values()),
                             searchableClasses: @js($searchableClasses),

                             selectAll() {
                                 this.selected = [...this.allIds];
                             },
                             deselectAll() {
                                 this.selected = [];
                             },
                             toggleGroup(groupClassIds) {
                                 const strIds = groupClassIds.map(String);
                                 const allSelected = strIds.length > 0 && strIds.every(id => this.selected.includes(id));
                                 if (allSelected) {
                                     this.selected = this.selected.filter(id => !strIds.includes(id));
                                 } else {
                                     this.selected = Array.from(new Set([...this.selected, ...strIds]));
                                 }
                             },
                             isGroupSelected(groupClassIds) {
                                 const strIds = groupClassIds.map(String);
                                 return strIds.length > 0 && strIds.every(id => this.selected.includes(id));
                             },
                             countGroupSelected(groupClassIds) {
                                 const strIds = groupClassIds.map(String);
                                 return strIds.filter(id => this.selected.includes(id)).length;
                             },
                             matchesSearch(name, prodi, cohort) {
                                 if (!this.search || this.search.trim() === '') return true;
                                 const q = this.search.trim().toLowerCase();
                                 return name.includes(q) || prodi.includes(q) || String(cohort).includes(q);
                             },
                             isGroupVisible(names, prodi, cohort) {
                                 if (!this.search || this.search.trim() === '') return true;
                                 return names.some(n => this.matchesSearch(n, prodi, cohort));
                             },
                             isProdiVisible(prodiName) {
                                 if (this.prodiFilter === 'semua') return true;
                                 return this.prodiFilter === prodiName;
                             },
                             hasVisibleClasses() {
                                 return this.searchableClasses.some(c => this.isProdiVisible(c.prodi) && this.matchesSearch(c.name, c.prodiLower, c.cohort));
                             }
                         }"
                         class="flex flex-col gap-3 rounded-card border border-border bg-page/50 p-4">
                        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border pb-3">
                            <div class="flex flex-wrap items-center gap-2.5">
                                <span class="text-sm font-bold text-ink">Pilih Kelas yang Dikelola</span>
                                <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold"
                                      :class="selected.length > 0 ? 'bg-primary-soft text-primary' : 'bg-surface text-muted border border-border'">
                                    <span class="size-1.5 rounded-full" :class="selected.length > 0 ? 'bg-primary' : 'bg-muted'" aria-hidden="true"></span>
                                    <span x-text="selected.length"></span> dari {{ $classGroups->count() }} kelas terpilih
                                </span>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <button type="button"
                                        @click="selectAll()"
                                        class="inline-flex cursor-pointer items-center gap-1.5 rounded-control border border-border bg-surface px-2.5 py-1 text-xs font-semibold text-ink shadow-2xs transition hover:bg-page hover:text-primary">
                                    <i data-lucide="check" class="size-3 text-primary" aria-hidden="true"></i>
                                    <span>Pilih Semua</span>
                                </button>
                                <button type="button"
                                        @click="deselectAll()"
                                        x-show="selected.length > 0"
                                        class="inline-flex cursor-pointer items-center gap-1.5 rounded-control border border-border bg-surface px-2.5 py-1 text-xs font-semibold text-muted shadow-2xs transition hover:border-danger/30 hover:bg-danger-soft hover:text-danger">
                                    <i data-lucide="rotate-ccw" class="size-3" aria-hidden="true"></i>
                                    <span>Batalkan Pilihan</span>
                                </button>
                            </div>
                        </div>

                        <div class="flex flex-col items-stretch justify-between gap-2.5 sm:flex-row sm:items-center">
                            <div class="relative flex-1">
                                <i data-lucide="search" class="pointer-events-none absolute top-1/2 left-3 size-3.5 -translate-y-1/2 text-muted" aria-hidden="true"></i>
                                <input type="text"
                                       x-model="search"
                                       aria-label="Cari kelas atau tahun angkatan"
                                       placeholder="Cari kelas atau tahun (misal: 2024, PTIK A, TEKOM)..."
                                       class="w-full rounded-control border border-border bg-surface py-1.5 pr-7 pl-8 text-xs text-ink placeholder:text-muted transition focus:border-primary focus:ring-1 focus:ring-primary focus:outline-none">
                                <button type="button"
                                        x-show="search.length > 0"
                                        @click="search = ''"
                                        aria-label="Hapus pencarian"
                                        class="absolute top-1/2 right-2 -translate-y-1/2 cursor-pointer rounded p-0.5 text-muted transition hover:text-ink">
                                    <i data-lucide="x" class="size-3.5" aria-hidden="true"></i>
                                </button>
                            </div>

                            <div class="flex shrink-0 items-center gap-1.5 overflow-x-auto pb-1 sm:pb-0">
                                <button type="button"
                                        @click="prodiFilter = 'semua'"
                                        :class="prodiFilter === 'semua' ? 'bg-primary text-white font-semibold' : 'bg-surface text-muted border border-border hover:bg-page hover:text-ink'"
                                        class="cursor-pointer rounded-control px-2.5 py-1 text-xs whitespace-nowrap transition">
                                    Semua Prodi
                                </button>
                                @foreach ($studyPrograms as $prodiName)
                                    <button type="button"
                                            @click="prodiFilter = @js($prodiName)"
                                            :class="prodiFilter === @js($prodiName) ? 'bg-primary text-white font-semibold' : 'bg-surface text-muted border border-border hover:bg-page hover:text-ink'"
                                            class="cursor-pointer rounded-control px-2.5 py-1 text-xs whitespace-nowrap transition">
                                        {{ in_array($prodiName, ['PTIK', 'Pendidikan Teknik Informatika dan Komputer'], true) ? 'PTIK' : (in_array($prodiName, ['TEKOM', 'Teknik Komputer'], true) ? 'TEKOM' : $prodiName) }}
                                    </button>
                                @endforeach
                            </div>
                        </div>

                        <div class="flex max-h-[300px] flex-col gap-3 overflow-y-auto pr-1">
                            <p x-show="!hasVisibleClasses()" x-cloak class="py-6 text-center text-sm text-muted">
                                Tidak ada kelas yang cocok dengan pencarian atau filter program studi.
                            </p>
                            @foreach ($groupedClasses as $prodiName => $cohortGroups)
                                <div x-show="isProdiVisible(@js($prodiName))" class="flex flex-col gap-2.5">
                                    <div class="flex items-center gap-2 border-b border-border/80 pb-1">
                                        <span class="text-xs font-bold tracking-wider text-muted uppercase">{{ $prodiName }}</span>
                                    </div>

                                    @foreach ($cohortGroups as $cohortLabel => $classes)
                                        @php
                                            $cohortIds = $classes->pluck('id')->map(fn ($id) => (string) $id)->values();
                                            $classNamesLower = $classes->pluck('name')->map(fn ($n) => strtolower($n))->values();
                                        @endphp
                                        <div x-show="isGroupVisible(@js($classNamesLower), @js(strtolower($prodiName)), @js((string) $classes->first()?->cohort_year))"
                                             class="flex flex-col gap-2 rounded-control border border-border bg-surface p-3 transition">
                                            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-border/60 pb-2">
                                                <div class="flex items-center gap-2">
                                                    <span class="size-1.5 rounded-full bg-primary/70" aria-hidden="true"></span>
                                                    <span class="text-xs font-bold text-ink">{{ $cohortLabel }}</span>
                                                    <span class="rounded-full border border-border bg-page px-2 py-0.5 text-[11px] font-medium text-muted">
                                                        <span x-text="countGroupSelected(@js($cohortIds))"></span>/{{ $classes->count() }} dipilih
                                                    </span>
                                                </div>
                                                <button type="button"
                                                        @click="toggleGroup(@js($cohortIds))"
                                                        class="inline-flex cursor-pointer items-center gap-1 rounded-control border border-border bg-page px-2 py-0.5 text-[11px] font-semibold text-primary shadow-2xs transition hover:bg-primary-soft">
                                                    <i data-lucide="check" class="size-3" aria-hidden="true"></i>
                                                    <span x-text="isGroupSelected(@js($cohortIds)) ? 'Batal Pilih Angkatan' : 'Pilih Semua Angkatan'"></span>
                                                </button>
                                            </div>

                                            <div class="grid grid-cols-1 gap-1.5 pt-1 sm:grid-cols-2 md:grid-cols-4">
                                                @foreach ($classes as $class)
                                                    <div x-show="matchesSearch(@js(strtolower($class->name)), @js(strtolower($class->study_program ?? '')), @js((string) ($class->cohort_year ?? '')))">
                                                        <label class="flex cursor-pointer items-center gap-2 rounded-control border px-2.5 py-1.5 text-xs font-medium transition select-none"
                                                               :class="selected.includes('{{ $class->id }}') ? 'border-primary bg-primary-soft/50 text-primary font-semibold ring-1 ring-primary/30' : 'border-border/80 bg-page text-ink hover:border-primary/40 hover:bg-surface'">
                                                            <input type="checkbox"
                                                                   name="class_ids[]"
                                                                   value="{{ $class->id }}"
                                                                   x-model="selected"
                                                                   class="size-3.5 shrink-0 rounded accent-primary">
                                                            <span class="truncate" title="{{ $class->name }}">{{ $class->name }}</span>
                                                        </label>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @endforeach
                        </div>

                        @error('class_ids')
                            <p class="mt-1 text-xs font-medium text-danger">{{ $message }}</p>
                        @enderror
                    </div>

                    <p class="text-sm text-muted">
                        Cakupan menentukan bagian yang boleh diubah. Dasar penyusunan: {{ $base ? ($base->status->value === 'official' ? 'jadwal resmi' : 'draf').' v'.$base->version_no : 'belum ada' }}. Sesi yang dikunci di draf tidak dipindahkan.
                    </p>

                    <x-info-box title="Pencarian otomatis maksimal 60 detik">
                        <p>Proses selesai lebih cepat jika susunan lengkap ditemukan. Jika hasil belum lengkap, kamu dapat mencoba pencarian 180 detik dari halaman hasil. Semua aturan wajib tetap dipenuhi.</p>
                        <p>Sesi di luar cakupan, pimpinan yang tidak boleh hari Kamis, jeda Jumat pukul 12.00–13.10 WITA (tidak ada perkuliahan), ketersediaan dosen, dan kapasitas ruang tetap diperiksa. Hasil selalu masuk sebagai draf baru; jadwal resmi tidak berubah sampai ditetapkan.</p>
                    </x-info-box>

                    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-border pt-4">
                        <x-button variant="ghost" :href="route('admin.academic.index', ['tab' => 'aturan'])">Atur aturan</x-button>
                        <div class="flex flex-wrap items-center gap-2.5">
                            <x-button type="button" variant="secondary" x-on:click="$dispatch('close-modal', 'auto-scheduler-modal')">Batal</x-button>
                            <x-button type="submit" variant="secondary" formaction="{{ route('admin.scheduler.check') }}">Periksa kesiapan</x-button>
                            <x-button type="submit" icon="sparkles">Mulai susun</x-button>
                        </div>
                    </div>
                </form>
            </div>
        </x-modal>

        <x-modal name="import-schedule-modal" title="Impor jadwal Excel / CSV" width="max-w-2xl" :show="$openImportModal">
            <div class="flex flex-col gap-5">
                <div class="flex flex-wrap items-center justify-between gap-3 rounded-card border border-border bg-page/60 px-4 py-3">
                    <div class="flex flex-col gap-0.5">
                        <span class="text-sm font-semibold text-ink">Belum punya templat?</span>
                        <span class="text-xs text-muted">Templat resmi memuat format kolom lengkap (WITA, SKS, Dosen Pengampu &amp; Mitra).</span>
                    </div>
                    <div class="flex items-center gap-2.5">
                        <x-button variant="soft" size="sm" icon="download" :href="route('admin.schedules.template')">Unduh templat (.xlsx)</x-button>
                        <a href="{{ route('admin.schedules.template', ['format' => 'csv']) }}" class="text-xs text-muted underline transition hover:text-primary">Versi CSV</a>
                    </div>
                </div>

                <form method="POST" action="{{ route('admin.imports.store') }}" enctype="multipart/form-data" class="flex flex-col gap-4" x-data="{ fileName: '' }">
                    @csrf
                    <x-field label="Semester" name="period_id" for="import-period-id">
                        <x-select name="period_id" id="import-period-id" :options="$periods" :value="$period->id" required />
                    </x-field>

                    <x-field label="File jadwal" name="file" for="import-schedule-file" hint="Format yang didukung: .xlsx, .xls, .csv (maks. 5 MB). PDF belum dapat dibaca otomatis; simpan sebagai Excel dulu.">
                        <label class="flex cursor-pointer flex-col items-center gap-2 rounded-card border-2 border-dashed border-border bg-page px-6 py-8 text-center transition hover:border-primary">
                            <i data-lucide="file-spreadsheet" class="size-8 text-primary" aria-hidden="true"></i>
                            <span class="font-semibold text-ink" x-text="fileName ? 'File terpilih: ' + fileName : 'Pilih file jadwal'">Pilih file jadwal</span>
                            <span class="text-xs text-muted">Kolom: Kelas, Kode MK, Mata Kuliah, Hari, Jam, Ruang, Dosen, Periode</span>
                            <input type="file" id="import-schedule-file" name="file" accept=".xlsx,.xls,.csv" class="mt-2 max-w-full text-sm" x-on:change="fileName = $event.target.files[0]?.name ?? ''" required>
                        </label>
                    </x-field>

                    <x-info-box title="Tidak ada yang tersimpan sebelum kamu memeriksa hasilnya">
                        <p>Hasil impor diperiksa lebih dulu lalu masuk sebagai draf baru. Jadwal resmi tetap aman.</p>
                    </x-info-box>

                    <div class="flex justify-end gap-2.5 border-t border-border pt-4">
                        <x-button type="button" variant="secondary" x-on:click="$dispatch('close-modal', 'import-schedule-modal')">Batal</x-button>
                        <x-button type="submit">Lanjut periksa hasil</x-button>
                    </div>
                </form>

                @if ($recentImports->isNotEmpty())
                    <div class="flex flex-col gap-2 border-t border-border pt-4">
                        <p class="text-xs font-semibold tracking-wider text-muted uppercase">Impor terakhir</p>
                        <div class="flex flex-col gap-1">
                            @foreach ($recentImports->take(3) as $recent)
                                <a href="{{ route('admin.imports.show', $recent) }}" class="flex flex-wrap items-center justify-between gap-2 rounded-control px-3 py-2 text-sm transition hover:bg-primary-soft/50">
                                    <span class="font-medium text-ink">{{ $recent->original_name }}</span>
                                    <span class="text-xs text-muted">{{ $recent->status->label() }} • {{ Wita::stamp($recent->created_at) }}{{ $recent->scheduleVersion ? ' • draf v'.$recent->scheduleVersion->version_no : '' }}</span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        </x-modal>

        <x-modal name="scheduler-history-modal" title="Riwayat penyusunan otomatis" width="max-w-2xl">
            <div class="flex flex-col gap-4">
                @forelse ($recentJobs as $job)
                    <a href="{{ route('admin.scheduler.show', $job) }}" class="flex flex-wrap items-center justify-between gap-3 rounded-control border border-border px-4 py-3 text-sm transition hover:bg-primary-soft/40">
                        <div class="flex flex-col gap-0.5">
                            <span class="font-semibold text-ink">
                                J-{{ $job->created_at->format('Ymd') }}-{{ str_pad((string) $job->id, 2, '0', STR_PAD_LEFT) }} • {{ $job->status->label() }}{{ $job->solver_status ? ' • '.$job->solver_status->label() : '' }}
                            </span>
                            <span class="text-xs text-muted">
                                {{ SchedulerService::SCOPES[$job->scope['type'] ?? 'semester'] ?? '' }} • {{ $job->time_limit_seconds }} detik • {{ $job->creator?->name }} • {{ Wita::stamp($job->created_at) }}
                            </span>
                        </div>
                        <x-status-badge :variant="match ($job->status) { OptimizationJobStatus::Finished => isset($job->result['draft_version_id']) ? 'success' : 'danger', OptimizationJobStatus::Failed => 'danger', OptimizationJobStatus::Cancelled => 'neutral', default => 'primary' }">
                            {{ isset($job->result['draft_version_id']) ? 'Draf v'.$job->result['draft_version_no'] : $job->status->label() }}
                        </x-status-badge>
                    </a>
                @empty
                    <x-empty-state title="Belum ada proses" message="Proses susun jadwal otomatis akan tercatat di sini." icon="history" />
                @endforelse

                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-border pt-4">
                    <a href="{{ route('admin.scheduler.history') }}" class="text-sm font-semibold text-primary hover:underline">Lihat semua riwayat</a>
                    <x-button type="button" variant="secondary" size="sm" x-on:click="$dispatch('close-modal', 'scheduler-history-modal')">Tutup</x-button>
                </div>
            </div>
        </x-modal>

        <x-modal name="archive-draft-modal" width="max-w-md" labelled-by="archive-draft-modal-title">
            <form method="POST"
                  x-data="{ versionNo: null, action: '', isActive: false, submitting: false }"
                  x-on:open-archive-draft.window="versionNo = $event.detail.versionNo; action = $event.detail.action; isActive = Boolean($event.detail.isActive); submitting = false;"
                  x-bind:action="action"
                  x-on:submit="if (!action || submitting) { $event.preventDefault(); return; } submitting = true"
                  x-on:sicma:form-error.window="submitting = false"
                  x-on:sicma:form-complete.window="submitting = false"
                  class="flex flex-col gap-5">
                @csrf
                <input type="hidden" name="redirect" value="workspace">

                <div class="flex items-start justify-between gap-4">
                    <span class="flex size-12 items-center justify-center rounded-control bg-danger-soft text-danger">
                        <i data-lucide="archive" aria-hidden="true" class="size-5"></i>
                    </span>
                    <button type="button"
                            x-on:click="$dispatch('close-modal', 'archive-draft-modal')"
                            class="inline-flex size-10 shrink-0 cursor-pointer items-center justify-center rounded-control text-muted transition hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                            aria-label="Tutup">
                        <i data-lucide="x" aria-hidden="true" class="size-5"></i>
                    </button>
                </div>

                <div class="flex flex-col gap-2">
                    <h2 id="archive-draft-modal-title" class="text-xl font-semibold text-ink">
                        Arsipkan Draf v<span x-text="versionNo"></span>?
                    </h2>
                    <p class="text-sm leading-relaxed text-muted">
                        Draf ini akan dipindahkan dari daftar kerja agar tidak menumpuk. Jadwal resmi tetap aman dan tidak berubah.
                    </p>
                    <p x-show="isActive" x-cloak class="text-xs font-medium text-warning-ink">
                        Karena ini draf aktif, sistem akan otomatis mengalihkan draf aktif ke draf terbuka terbaru jika masih ada.
                    </p>
                </div>

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <x-button type="button" variant="secondary" x-on:click="$dispatch('close-modal', 'archive-draft-modal')">Batal</x-button>
                    <x-button type="submit" variant="danger" x-bind:disabled="submitting">Ya, arsipkan</x-button>
                </div>
            </form>
        </x-modal>
    @endif
@endpush
