@use('App\Services\Scheduling\SchedulerService')
@extends('layouts.admin')

@section('title', 'Susun Jadwal')
@section('breadcrumb', 'Susun Jadwal|Pengaturan Generate')

@section('breadcrumb-actions')
    <x-button variant="secondary" :href="route('admin.scheduler.history')">Riwayat proses</x-button>
@endsection

@section('content')
    @php
        $scope = old('scope', 'semester');
    @endphp

    <div class="flex flex-col gap-6">
        <x-page-header title="Susun jadwal otomatis"
                        :subtitle="($period?->name ?? 'Semester aktif').' • Pilih bagian yang boleh berubah.'.($official ? ' Jadwal resmi v'.$official->version_no.' tetap dilindungi.' : '')" />

        <x-card>
            <x-schedule-steps :current="3" />
        </x-card>

        @include('admin.partials.scheduling-tabs')

        @if ($base && in_array($base->status->value, ['draft', 'ready'], true))
            <x-card class="flex flex-wrap items-center justify-between gap-4 border-primary/30 bg-primary-soft/30">
                <div class="flex flex-col gap-1">
                    <div class="flex items-center gap-2">
                        <span class="rounded-md bg-primary px-2 py-0.5 text-xs font-semibold text-white">Draf Aktif</span>
                        <h2 class="font-semibold text-ink">Draf v{{ $base->version_no }} sedang dikerjakan</h2>
                    </div>
                    <p class="text-sm text-muted">Kamu bisa langsung mengedit sesi atau memeriksa bentrok pada draf ini tanpa perlu menyusun ulang dari awal.</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <x-button variant="secondary" icon="calendar-cog" :href="route('admin.drafts.show', $base)">Edit Sesi Draf</x-button>
                    <x-button icon="list-checks" :href="route('admin.drafts.inspect', $base)">Buka Pemeriksaan</x-button>
                </div>
            </x-card>
        @endif

        @if (! $period)
            <x-empty-state title="Belum ada semester aktif" message="Atur semester di Data Akademik lebih dulu." icon="calendar" />
        @else
            <form method="POST" action="{{ route('admin.scheduler.store') }}" class="grid gap-6 xl:grid-cols-[1fr_380px]" x-data="{ scope: @js($scope) }">
                @csrf
                <div class="flex flex-col gap-6">
                    <x-card class="flex flex-col gap-4">
                        <h2 class="text-lg font-semibold">1. Tentukan cakupan</h2>
                        <div class="grid grid-cols-4 gap-3">
                            @foreach (SchedulerService::SCOPES as $value => $label)
                                <label class="flex cursor-pointer items-center gap-2 rounded-control border px-4 py-3 text-sm font-medium" :class="scope === @js($value) ? 'border-primary bg-primary-soft text-primary' : 'border-border'">
                                    <input type="radio" name="scope" value="{{ $value }}" x-model="scope" class="accent-primary"> {{ $label }}
                                </label>
                            @endforeach
                        </div>
                        @php
                            $groupedClasses = $classGroups->groupBy([
                                fn ($c) => $c->study_program ?: 'Program Studi Lainnya',
                                fn ($c) => $c->cohort_year ? 'Angkatan '.$c->cohort_year : 'Lainnya / Khusus',
                            ]);
                            $studyPrograms = $classGroups->pluck('study_program')->filter()->unique()->values();
                        @endphp

                        <div x-show="scope === 'kelas'"
                             x-data="{
                                 search: '',
                                 prodiFilter: 'semua',
                                 selected: @js(array_map('strval', old('class_ids', []))),
                                 allIds: @js($classGroups->pluck('id')->map(fn ($id) => (string) $id)->values()),

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
                                 }
                             }"
                             class="flex flex-col gap-3.5 rounded-card border border-border bg-page/50 p-4">

                            {{-- Header bar: Judul, Counter & Tombol Tindakan Cepat --}}
                            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border pb-3">
                                <div class="flex flex-wrap items-center gap-2.5">
                                    <span class="text-sm font-bold text-ink">Pilih Kelas yang Dikelola</span>
                                    <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold"
                                          :class="selected.length > 0 ? 'bg-primary-soft text-primary' : 'bg-surface text-muted border border-border'">
                                        <span class="size-1.5 rounded-full" :class="selected.length > 0 ? 'bg-primary' : 'bg-muted'"></span>
                                        <span x-text="selected.length"></span> dari {{ $classGroups->count() }} kelas terpilih
                                    </span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <button type="button"
                                            @click="selectAll()"
                                            class="inline-flex items-center gap-1.5 rounded-control border border-border bg-surface px-2.5 py-1 text-xs font-semibold text-ink hover:bg-page hover:text-primary transition shadow-2xs cursor-pointer">
                                        <i data-lucide="check" class="size-3 text-primary"></i>
                                        <span>Pilih Semua</span>
                                    </button>
                                    <button type="button"
                                            @click="deselectAll()"
                                            x-show="selected.length > 0"
                                            class="inline-flex items-center gap-1.5 rounded-control border border-border bg-surface px-2.5 py-1 text-xs font-semibold text-muted hover:bg-danger-soft hover:text-danger hover:border-danger/30 transition shadow-2xs cursor-pointer">
                                        <i data-lucide="rotate-ccw" class="size-3"></i>
                                        <span>Batalkan Pilihan</span>
                                    </button>
                                </div>
                            </div>

                            {{-- Toolbar Pencarian & Filter Tab Prodi --}}
                            <div class="flex flex-col sm:flex-row gap-2.5 items-stretch sm:items-center justify-between">
                                <div class="relative flex-1">
                                    <i data-lucide="search" class="absolute left-3 top-1/2 size-3.5 -translate-y-1/2 text-muted"></i>
                                    <input type="text"
                                           x-model="search"
                                           placeholder="Cari kelas atau tahun (misal: 2024, PTIK A, TEKOM)..."
                                           class="w-full rounded-control border border-border bg-surface pl-8 pr-7 py-1.5 text-xs text-ink placeholder:text-muted focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary transition">
                                    <button type="button"
                                            x-show="search.length > 0"
                                            @click="search = ''"
                                            class="absolute right-2 top-1/2 -translate-y-1/2 text-muted hover:text-ink text-xs font-bold px-1">
                                        ✕
                                    </button>
                                </div>

                                {{-- Tab Pilihan Prodi --}}
                                <div class="flex items-center gap-1.5 overflow-x-auto shrink-0 pb-1 sm:pb-0">
                                    <button type="button"
                                            @click="prodiFilter = 'semua'"
                                            :class="prodiFilter === 'semua' ? 'bg-primary text-white font-semibold' : 'bg-surface text-muted border border-border hover:bg-page hover:text-ink'"
                                            class="rounded-control px-2.5 py-1 text-xs transition cursor-pointer whitespace-nowrap">
                                        Semua Prodi
                                    </button>
                                    @foreach ($studyPrograms as $prodiName)
                                        <button type="button"
                                                @click="prodiFilter = @js($prodiName)"
                                                :class="prodiFilter === @js($prodiName) ? 'bg-primary text-white font-semibold' : 'bg-surface text-muted border border-border hover:bg-page hover:text-ink'"
                                                class="rounded-control px-2.5 py-1 text-xs transition cursor-pointer whitespace-nowrap">
                                            {{ $prodiName === 'Pendidikan Teknik Informatika dan Komputer' ? 'PTIK' : ($prodiName === 'Teknik Komputer' ? 'TEKOM' : $prodiName) }}
                                        </button>
                                    @endforeach
                                </div>
                            </div>

                            {{-- Daftar Kelompok Angkatan Kelas --}}
                            <div class="flex flex-col gap-3.5 max-h-[460px] overflow-y-auto pr-1">
                                @foreach ($groupedClasses as $prodiName => $cohortGroups)
                                    <div x-show="isProdiVisible(@js($prodiName))" class="flex flex-col gap-2.5">
                                        {{-- Header Program Studi --}}
                                        <div class="flex items-center gap-2 border-b border-border/80 pb-1">
                                            <span class="text-xs font-bold uppercase tracking-wider text-muted">
                                                {{ $prodiName }}
                                            </span>
                                        </div>

                                        @foreach ($cohortGroups as $cohortLabel => $classes)
                                            @php
                                                $cohortIds = $classes->pluck('id')->map(fn ($id) => (string) $id)->values();
                                                $classNamesLower = $classes->pluck('name')->map(fn ($n) => strtolower($n))->values();
                                            @endphp
                                            <div x-show="isGroupVisible(@js($classNamesLower), @js(strtolower($prodiName)), @js((string) $classes->first()?->cohort_year))"
                                                 class="flex flex-col gap-2 rounded-control border border-border bg-surface p-3 transition">
                                                {{-- Header Angkatan & Tombol Pilih Angkatan --}}
                                                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-border/60 pb-2">
                                                    <div class="flex items-center gap-2">
                                                        <span class="size-1.5 rounded-full bg-primary/70"></span>
                                                        <span class="text-xs font-bold text-ink">{{ $cohortLabel }}</span>
                                                        <span class="rounded-full bg-page px-2 py-0.5 text-[11px] font-medium text-muted border border-border">
                                                            <span x-text="countGroupSelected(@js($cohortIds))"></span>/{{ $classes->count() }} dipilih
                                                        </span>
                                                    </div>
                                                    <button type="button"
                                                            @click="toggleGroup(@js($cohortIds))"
                                                            class="inline-flex items-center gap-1 rounded-control border border-border bg-page px-2 py-0.5 text-[11px] font-semibold text-primary hover:bg-primary-soft transition shadow-2xs cursor-pointer">
                                                        <i data-lucide="check" class="size-3"></i>
                                                        <span x-text="isGroupSelected(@js($cohortIds)) ? 'Batal Pilih Angkatan' : 'Pilih Semua Angkatan'"></span>
                                                    </button>
                                                </div>

                                                {{-- Grid Checkbox Kelas --}}
                                                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-1.5 pt-1">
                                                    @foreach ($classes as $class)
                                                        <div x-show="matchesSearch(@js(strtolower($class->name)), @js(strtolower($class->study_program ?? '')), @js((string) ($class->cohort_year ?? '')))">
                                                            <label class="flex items-center gap-2 rounded-control border px-2.5 py-1.5 text-xs font-medium cursor-pointer transition select-none"
                                                                   :class="selected.includes('{{ $class->id }}') ? 'border-primary bg-primary-soft/50 text-primary font-semibold ring-1 ring-primary/30' : 'border-border/80 bg-page text-ink hover:border-primary/40 hover:bg-surface'">
                                                                <input type="checkbox"
                                                                       name="class_ids[]"
                                                                       value="{{ $class->id }}"
                                                                       x-model="selected"
                                                                       class="accent-primary size-3.5 rounded shrink-0">
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
                                <p class="text-xs font-medium text-danger mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                        <p class="text-sm text-muted">Cakupan menentukan bagian yang boleh diubah. Dasar penyusunan: {{ $base ? ($base->status->value === 'official' ? 'jadwal resmi' : 'draf').' v'.$base->version_no : 'belum ada' }}. Sesi yang dikunci di draf tidak dipindahkan.</p>
                    </x-card>

                    <x-info-box title="Pencarian otomatis maksimal 60 detik">
                        <p>Proses selesai lebih cepat jika susunan lengkap ditemukan. Jika hasil belum lengkap, kamu dapat mencoba pencarian 180 detik dari halaman hasil. Semua aturan wajib tetap dipenuhi.</p>
                    </x-info-box>

                    @if ($readiness)
                        <x-card class="flex flex-col gap-3">
                            <h2 class="text-lg font-semibold">{{ $readiness['errors'] === [] ? 'Siap disusun sebagai draf' : 'Data belum siap disusun' }}</h2>
                            <p class="text-sm text-muted">{{ $readiness['in_scope'] }} sesi dalam cakupan • {{ $readiness['fixed'] }} sesi tetap diperhitungkan</p>
                            @foreach ($readiness['errors'] as $error)
                                <x-alert variant="danger">{{ $error }}</x-alert>
                            @endforeach
                            @foreach ($readiness['warnings'] as $warning)
                                <div class="rounded-control bg-amber-50 px-4 py-3 text-sm text-amber-800">{{ $warning }}</div>
                            @endforeach
                        </x-card>
                    @endif
                </div>

                <div class="flex flex-col gap-6">
                    <x-info-box title="Jadwal di luar pilihan tetap diperhitungkan">
                        <p>Sesi di luar cakupan, pimpinan yang tidak boleh hari Kamis, jeda Jumat pukul 12.00–13.10 WITA (tidak ada perkuliahan), ketersediaan dosen, dan kapasitas ruang tetap diperiksa. Hasil selalu masuk sebagai draf baru; jadwal resmi tidak berubah sampai ditetapkan.</p>
                    </x-info-box>
                    <x-button type="submit" variant="secondary" formaction="{{ route('admin.scheduler.check') }}">Periksa kesiapan</x-button>
                    <x-button type="submit" icon="sparkles">Mulai susun</x-button>
                    <x-button variant="ghost" :href="route('admin.academic.index', ['tab' => 'aturan'])">Atur aturan</x-button>
                    @if ($lastJob)
                        <a href="{{ route('admin.scheduler.show', $lastJob) }}" class="text-sm text-primary hover:underline">Proses terakhir: {{ $lastJob->status->label() }}{{ $lastJob->solver_status ? ' • '.$lastJob->solver_status->label() : '' }}</a>
                    @endif
                </div>
            </form>
        @endif
    </div>
@endsection
