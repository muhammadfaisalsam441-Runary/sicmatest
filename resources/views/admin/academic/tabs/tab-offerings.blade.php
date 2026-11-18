            @if (! $period)
                <div class="rounded-xl border border-warning/40 bg-warning-soft/30 p-6 text-center">
                    <i data-lucide="triangle-alert" class="mx-auto size-8 text-warning-ink mb-2"></i>
                    <h3 class="font-bold text-ink text-base">Belum Ada Semester Aktif</h3>
                    <p class="text-xs text-muted mt-1 max-w-md mx-auto">Untuk mengelola penawaran perkuliahan, aktifkan atau buat semester terlebih dahulu di tab Semester & Kalender.</p>
                    <div class="mt-4">
                        <x-button variant="secondary" :href="route('admin.academic.index', ['tab' => 'semester'])">Buka Kalender Semester</x-button>
                    </div>
                </div>
            @else
                <div x-data="{
                    search: '',
                    filterProdi: '',
                    filterCohort: '',
                    filterStatus: '',
                    filterScheduled: '',
                    filterSks: '',
                    filterClass: '',
                    classNamesById: @js($offeringClasses->pluck('name', 'id')),
                    selectedIds: [],
                    visibleIds: @js($offerings->pluck('id')->map(fn ($id) => (int) $id)->values()->all()),
                    visibleCount: {{ $offerings->count() }},
                    deleteModalOpen: false,
                    offeringToDelete: { id: null, name: '', deleteUrl: '' },
                    batchDeleteModalOpen: false,

                    advancedFilterCount() {
                        return [this.filterProdi, this.filterCohort, this.filterClass, this.filterSks].filter(Boolean).length;
                    },

                    matches(el) {
                        if (!el || !el.dataset) return true;
                        const q = this.search.trim().toLowerCase();
                        if (q && !el.dataset.search.includes(q)) return false;
                        if (this.filterProdi && el.dataset.prodi !== this.filterProdi) return false;
                        if (this.filterCohort && el.dataset.cohort !== this.filterCohort) return false;
                        if (this.filterStatus && el.dataset.status !== this.filterStatus) return false;
                        if (this.filterScheduled && el.dataset.scheduled !== this.filterScheduled) return false;
                        if (this.filterSks && el.dataset.sks !== this.filterSks) return false;
                        if (this.filterClass && el.dataset.classId !== this.filterClass) return false;
                        return true;
                    },

                    updateVisibleCount() {
                        this.$nextTick(() => {
                            const rows = this.$el.querySelectorAll('tbody tr[data-search]');
                            let count = 0;
                            const ids = [];
                            rows.forEach(r => {
                                if (this.matches(r)) {
                                    count++;
                                    const cb = r.querySelector('input[type=\'checkbox\'][data-offering-id]');
                                    if (cb && cb.dataset.offeringId) {
                                        ids.push(parseInt(cb.dataset.offeringId, 10));
                                    }
                                }
                            });
                            this.visibleIds = ids;
                            this.visibleCount = count;
                        });
                    },

                    resetFilters() {
                        this.search = '';
                        this.filterProdi = '';
                        this.filterCohort = '';
                        this.filterStatus = '';
                        this.filterScheduled = '';
                        this.filterSks = '';
                        this.filterClass = '';
                        this.updateVisibleCount();
                    },

                    hasActiveFilters() {
                        return !!(this.search.trim() || this.filterProdi || this.filterCohort || this.filterStatus || this.filterScheduled || this.filterSks || this.filterClass);
                    },

                    toggleStatusFilter(val) {
                        this.filterStatus = this.filterStatus === val ? '' : val;
                        this.updateVisibleCount();
                    },

                    toggleScheduledFilter(val) {
                        this.filterScheduled = this.filterScheduled === val ? '' : val;
                        this.updateVisibleCount();
                    },

                    toggleSelectAll(checked) {
                        const current = this.selectedIds.map(Number);
                        if (checked) {
                            this.selectedIds = Array.from(new Set([...current, ...this.visibleIds]));
                        } else {
                            this.selectedIds = current.filter(id => !this.visibleIds.includes(id));
                        }
                    },

                    isAllSelected() {
                        if (this.visibleIds.length === 0) return false;
                        const current = this.selectedIds.map(Number);
                        return this.visibleIds.every(id => current.includes(Number(id)));
                    },

                    confirmDelete(id, name, deleteUrl) {
                        this.offeringToDelete = { id, name, deleteUrl };
                        this.deleteModalOpen = true;
                    }
                }"
                :data-editing="(deleteModalOpen || batchDeleteModalOpen || selectedIds.length > 0) ? 'true' : null"
                @keydown.escape.window="deleteModalOpen = false; batchDeleteModalOpen = false"
                x-init="
                    $watch('search', () => updateVisibleCount());
                    $watch('filterProdi', () => updateVisibleCount());
                    $watch('filterCohort', () => updateVisibleCount());
                    $watch('filterStatus', () => updateVisibleCount());
                    $watch('filterScheduled', () => updateVisibleCount());
                    $watch('filterSks', () => updateVisibleCount());
                    $watch('filterClass', () => updateVisibleCount());
                "
                class="flex flex-col gap-4 admin-page-stack">

                    {{-- Toolbar Filter & Pencarian Cepat --}}
                    <x-card padding="p-4" class="admin-table-card flex flex-col gap-3 shadow-xs">
                        {{-- Baris Pill Status Ringkas --}}
                        <div class="flex flex-wrap items-center gap-1.5 border-b border-border pb-2.5">
                            <button type="button"
                                    @click="filterStatus = ''; filterScheduled = ''; updateVisibleCount()"
                                    class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold transition cursor-pointer"
                                    :class="!filterStatus && !filterScheduled ? 'bg-primary text-white' : 'bg-page text-muted hover:bg-border hover:text-ink'">
                                <span>Semua</span>
                                <span class="rounded-full px-1.5 py-0.5 text-[10px]" :class="!filterStatus && !filterScheduled ? 'bg-white/20 text-white' : 'bg-surface text-muted'">{{ number_format($offeringStats['total']) }}</span>
                            </button>
                            <button type="button"
                                    @click="toggleStatusFilter('unassigned')"
                                    class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold transition cursor-pointer"
                                    :class="filterStatus === 'unassigned' ? 'bg-warning text-white' : 'bg-warning-soft/80 text-warning-ink hover:bg-warning-soft'">
                                <i data-lucide="triangle-alert" class="size-3.5" aria-hidden="true"></i>
                                <span>Belum Ada Dosen</span>
                                <span class="rounded-full px-1.5 py-0.5 text-[10px]" :class="filterStatus === 'unassigned' ? 'bg-white/20 text-white' : 'bg-surface/80 text-warning-ink'">{{ number_format($offeringStats['unassigned']) }}</span>
                            </button>
                            <button type="button"
                                    @click="toggleStatusFilter('assigned')"
                                    class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold transition cursor-pointer"
                                    :class="filterStatus === 'assigned' ? 'bg-primary text-white' : 'bg-page text-muted hover:bg-border hover:text-ink'">
                                <i data-lucide="users" class="size-3.5" aria-hidden="true"></i>
                                <span>Sudah Ada Dosen</span>
                                <span class="rounded-full px-1.5 py-0.5 text-[10px]" :class="filterStatus === 'assigned' ? 'bg-white/20 text-white' : 'bg-surface text-muted'">{{ number_format($offeringStats['assigned']) }}</span>
                            </button>
                            <button type="button"
                                    @click="toggleScheduledFilter('scheduled')"
                                    class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold transition cursor-pointer"
                                    :class="filterScheduled === 'scheduled' ? 'bg-success text-white' : 'bg-success-soft/70 text-success hover:bg-success-soft'">
                                <i data-lucide="clock" class="size-3.5" aria-hidden="true"></i>
                                <span>Sudah Terjadwal</span>
                                <span class="rounded-full px-1.5 py-0.5 text-[10px]" :class="filterScheduled === 'scheduled' ? 'bg-white/20 text-white' : 'bg-surface/80 text-success'">{{ number_format($offeringStats['scheduled']) }}</span>
                            </button>
                            <button type="button"
                                    @click="toggleScheduledFilter('unscheduled')"
                                    class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold transition cursor-pointer"
                                    :class="filterScheduled === 'unscheduled' ? 'bg-primary text-white' : 'bg-page text-muted hover:bg-border hover:text-ink'">
                                <i data-lucide="circle-alert" class="size-3.5" aria-hidden="true"></i>
                                <span>Belum Terjadwal</span>
                                <span class="rounded-full px-1.5 py-0.5 text-[10px]" :class="filterScheduled === 'unscheduled' ? 'bg-white/20 text-white' : 'bg-surface text-muted'">{{ number_format($offeringStats['total'] - $offeringStats['scheduled']) }}</span>
                            </button>
                        </div>

                        {{-- Baris Pencarian + Toggle Filter Lanjutan + Chip Aktif + Tautan Pembagian SKS --}}
                        <div x-data="filterDrawer('offerings-filters', false)" class="flex flex-col gap-2">
                            <div class="flex flex-wrap items-center gap-2">
                                <div class="relative flex-1 min-w-[220px]">
                                    <i data-lucide="search" class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted" aria-hidden="true"></i>
                                    <input id="offering-search" type="search" x-model="search" data-table-search
                                           autocorrect="off" autocapitalize="none" spellcheck="false"
                                           aria-label="Cari Mata Kuliah, Kode, Kelas, atau Dosen"
                                           placeholder="Cari mata kuliah, kode, kelas, atau dosen..."
                                           class="w-full rounded-control border border-border bg-surface py-2 pl-9 pr-8 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary shadow-2xs">
                                    <button type="button" x-show="search.length > 0" x-cloak @click="search = ''" aria-label="Bersihkan pencarian" class="absolute right-2.5 top-1/2 -translate-y-1/2 cursor-pointer text-muted hover:text-ink">
                                        <i data-lucide="x" class="size-3.5" aria-hidden="true"></i>
                                    </button>
                                </div>

                                <button type="button"
                                        @click="toggle()"
                                        :aria-expanded="open.toString()"
                                        class="inline-flex h-[38px] shrink-0 cursor-pointer items-center gap-1.5 rounded-control border px-3 text-xs font-semibold transition"
                                        :class="open || advancedFilterCount() > 0 ? 'border-primary/40 bg-primary-soft/50 text-primary' : 'border-border bg-surface text-muted hover:bg-page hover:text-ink'">
                                    <i data-lucide="sliders-horizontal" class="size-3.5" aria-hidden="true"></i>
                                    <span>Filter lanjutan</span>
                                    <span x-show="advancedFilterCount() > 0" x-cloak class="rounded-full bg-primary px-1.5 py-0.5 text-[10px] font-bold text-white" x-text="advancedFilterCount()"></span>
                                    <i data-lucide="chevron-down" class="size-3.5 transition-transform duration-150" :class="open ? 'rotate-180' : ''" aria-hidden="true"></i>
                                </button>

                                {{-- Chip Filter Lanjutan Aktif --}}
                                <div x-show="advancedFilterCount() > 0" x-cloak class="flex flex-wrap items-center gap-1.5">
                                    <button type="button" x-show="filterProdi" @click="filterProdi = ''" class="inline-flex items-center gap-1 rounded-full border border-primary/30 bg-primary-soft/60 px-2.5 py-1 text-xs font-medium text-primary hover:bg-primary-soft cursor-pointer">
                                        <span x-text="'Prodi: ' + filterProdi"></span>
                                        <i data-lucide="x" class="size-3" aria-hidden="true"></i>
                                    </button>
                                    <button type="button" x-show="filterCohort" @click="filterCohort = ''" class="inline-flex items-center gap-1 rounded-full border border-primary/30 bg-primary-soft/60 px-2.5 py-1 text-xs font-medium text-primary hover:bg-primary-soft cursor-pointer">
                                        <span x-text="'Angkatan ' + filterCohort"></span>
                                        <i data-lucide="x" class="size-3" aria-hidden="true"></i>
                                    </button>
                                    <button type="button" x-show="filterClass" @click="filterClass = ''" class="inline-flex items-center gap-1 rounded-full border border-primary/30 bg-primary-soft/60 px-2.5 py-1 text-xs font-medium text-primary hover:bg-primary-soft cursor-pointer">
                                        <span x-text="'Kelas: ' + (classNamesById[filterClass] ?? filterClass)"></span>
                                        <i data-lucide="x" class="size-3" aria-hidden="true"></i>
                                    </button>
                                    <button type="button" x-show="filterSks" @click="filterSks = ''" class="inline-flex items-center gap-1 rounded-full border border-primary/30 bg-primary-soft/60 px-2.5 py-1 text-xs font-medium text-primary hover:bg-primary-soft cursor-pointer">
                                        <span x-text="filterSks + ' SKS'"></span>
                                        <i data-lucide="x" class="size-3" aria-hidden="true"></i>
                                    </button>
                                </div>

                                <button type="button"
                                        x-show="hasActiveFilters()"
                                        x-cloak
                                        @click="resetFilters()"
                                        class="inline-flex h-[38px] shrink-0 cursor-pointer items-center justify-center gap-1.5 rounded-control border border-border bg-surface px-3 text-xs font-semibold text-muted hover:text-ink hover:bg-page transition"
                                        title="Hapus semua filter aktif">
                                    <i data-lucide="rotate-ccw" class="size-3.5" aria-hidden="true"></i>
                                    <span>Reset</span>
                                </button>

                                <a href="{{ route('admin.teaching.index') }}"
                                   class="ml-auto inline-flex h-[38px] shrink-0 items-center justify-center gap-1.5 rounded-control border border-primary/30 bg-primary-soft/40 px-3 text-xs font-semibold text-primary hover:bg-primary hover:text-white transition"
                                   title="Buka halaman Pembagian Mengajar Dosen">
                                    <i data-lucide="users" class="size-3.5" aria-hidden="true"></i>
                                    <span>Ke Pembagian SKS &rarr;</span>
                                </a>
                            </div>

                            {{-- Drawer Filter Lanjutan --}}
                            <div x-show="open" x-cloak class="grid grid-cols-1 gap-2 border-t border-border/70 pt-2 sm:grid-cols-2 lg:grid-cols-4">
                                <select id="offering-prodi-filter" x-model="filterProdi" aria-label="Filter Program Studi" class="w-full rounded-control border border-border bg-surface px-3 py-1.5 text-xs text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                                    <option value="">Semua Program Studi</option>
                                    @foreach ($offeringPrograms as $prog)
                                        <option value="{{ $prog }}">{{ $prog }}</option>
                                    @endforeach
                                </select>

                                <select id="offering-cohort-filter" x-model="filterCohort" aria-label="Filter Angkatan" class="w-full rounded-control border border-border bg-surface px-3 py-1.5 text-xs text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                                    <option value="">Semua Angkatan</option>
                                    @foreach ($offeringCohorts as $cohort)
                                        <option value="{{ $cohort }}">Angkatan {{ $cohort }}</option>
                                    @endforeach
                                </select>

                                <select id="offering-class-filter" x-model="filterClass" aria-label="Filter Kelas Spesifik" class="w-full rounded-control border border-border bg-surface px-3 py-1.5 text-xs text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                                    <option value="">Semua Kelas</option>
                                    @foreach ($offeringClasses as $cls)
                                        <option value="{{ $cls->id }}">{{ $cls->name }}</option>
                                    @endforeach
                                </select>

                                <select id="offering-sks-filter" x-model="filterSks" aria-label="Filter Bobot SKS" class="w-full rounded-control border border-border bg-surface px-3 py-1.5 text-xs text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                                    <option value="">Semua SKS</option>
                                    @foreach ($offeringSksList as $sks)
                                        <option value="{{ $sks }}">{{ $sks }} SKS</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        {{-- TABEL DATA PENAWARAN --}}
                        <x-table-scroll>
                            <x-slot:title>Menampilkan <strong class="text-ink" x-text="visibleCount"></strong> dari <strong class="text-ink">{{ $offerings->count() }}</strong> penawaran • {{ number_format($offeringStats['total_sks']) }} SKS</x-slot:title>
                            <x-slot:headerActions>
                                <x-table-density-toggle />
                            </x-slot:headerActions>
                            <table class="w-full border-collapse text-left text-sm">
                                <thead class="sticky top-0 z-10 border-b border-border bg-page text-xs font-semibold text-muted">
                                    <tr>
                                        <th scope="col" class="w-10 px-4 py-3 text-center">
                                            <input type="checkbox"
                                                   @change="toggleSelectAll($event.target.checked)"
                                                   :checked="isAllSelected()"
                                                   aria-label="Pilih semua penawaran yang tampil"
                                                   class="size-4 rounded border-border text-primary focus:ring-primary cursor-pointer"
                                                   title="Pilih semua yang tampil">
                                        </th>
                                        <th scope="col" class="px-4 py-3">Kelas Rombel</th>
                                        <th scope="col" class="px-4 py-3">Mata Kuliah Ditawarkan</th>
                                        <th scope="col" class="px-4 py-3 text-center">Bobot SKS</th>
                                        <th scope="col" class="px-4 py-3 text-center">Status Pengampu</th>
                                        <th scope="col" class="px-4 py-3 text-right">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border">
                                    @forelse ($offerings as $offering)
                                        @php
                                            $lecturerNames = $offering->teachingAssignments->pluck('lecturer.name')->implode(' ');
                                            $offeringSearchText = mb_strtolower($offering->classGroup->name.' '.$offering->classGroup->study_program.' '.$offering->classGroup->cohort_year.' '.$offering->course->code.' '.$offering->course->name.' '.$lecturerNames);
                                            $isAssigned = $offering->teachingAssignments->isNotEmpty();
                                            $isScheduled = $offering->scheduleSessions->isNotEmpty();
                                        @endphp
                                        <tr data-search="{{ $offeringSearchText }}"
                                            data-prodi="{{ $offering->classGroup->study_program ?? '' }}"
                                            data-cohort="{{ $offering->classGroup->cohort_year ?? '' }}"
                                            data-status="{{ $isAssigned ? 'assigned' : 'unassigned' }}"
                                            data-scheduled="{{ $isScheduled ? 'scheduled' : 'unscheduled' }}"
                                            data-sks="{{ (int) $offering->course->sks }}"
                                            data-class-id="{{ $offering->classGroup->id }}"
                                            x-show="matches($el)"
                                            class="border-b border-border transition hover:bg-primary-soft/20"
                                            :class="{ 'bg-primary-soft/30': selectedIds.includes({{ $offering->id }}) }">
                                            {{-- Checkbox --}}
                                            <td class="px-4 py-3 text-center">
                                                <input type="checkbox"
                                                       value="{{ $offering->id }}"
                                                       x-model.number="selectedIds"
                                                       data-offering-id="{{ $offering->id }}"
                                                       aria-label="Pilih penawaran {{ $offering->course->name }} untuk {{ $offering->classGroup->name }}"
                                                       class="size-4 rounded border-border text-primary focus:ring-primary cursor-pointer">
                                            </td>

                                            {{-- Kelas Rombel --}}
                                            <td class="px-4 py-3">
                                                <div class="flex flex-col">
                                                    <span class="font-bold text-ink text-sm">{{ $offering->classGroup->name }}</span>
                                                    <div class="flex flex-wrap items-center gap-1 text-xs text-muted">
                                                        <span>{{ $offering->classGroup->study_program ?? 'Umum' }}</span>
                                                        <span>•</span>
                                                        <span>Angkatan {{ $offering->classGroup->cohort_year ?? '—' }}</span>
                                                    </div>
                                                </div>
                                            </td>

                                            {{-- Mata Kuliah Ditawarkan --}}
                                            <td class="px-4 py-3">
                                                <div class="flex items-center gap-2.5">
                                                    <div class="flex size-7 shrink-0 items-center justify-center rounded-md bg-primary-soft text-primary">
                                                        <i data-lucide="book-open" class="size-3.5"></i>
                                                    </div>
                                                    <div class="flex flex-col">
                                                        <span class="font-bold text-ink text-sm">{{ $offering->course->name }}</span>
                                                        <span class="font-mono text-xs text-muted">{{ $offering->course->code }}</span>
                                                    </div>
                                                </div>
                                            </td>

                                            {{-- Bobot SKS --}}
                                            <td class="px-4 py-3 text-center">
                                                <span class="inline-flex items-center justify-center rounded-full bg-primary-soft px-2.5 py-0.5 text-xs font-bold text-primary">
                                                    {{ $offering->course->sks }} SKS
                                                </span>
                                            </td>

                                            {{-- Status Pengampu --}}
                                            <td class="px-4 py-3 text-center">
                                                @if ($isAssigned)
                                                    <span class="inline-flex items-center gap-1.5 rounded-full border border-success/30 bg-success-soft/70 px-2.5 py-1 text-xs font-semibold text-success">
                                                        <span class="size-1.5 rounded-full bg-success"></span>
                                                        Sudah Ada Dosen
                                                    </span>
                                                @else
                                                    <span class="inline-flex items-center gap-1.5 rounded-full border border-warning/30 bg-warning-soft/70 px-2.5 py-1 text-xs font-semibold text-warning-ink">
                                                        <span class="size-1.5 rounded-full bg-warning"></span>
                                                        Belum Ada Dosen
                                                    </span>
                                                @endif
                                            </td>

                                            {{-- Aksi Cepat --}}
                                            <td class="px-4 py-3 text-right">
                                                <div class="flex items-center justify-end gap-1.5">
                                                    {{-- Tombol Atur SKS & Dosen --}}
                                                    <a href="{{ route('admin.teaching.index', ['penawaran' => $offering->id]) }}"
                                                       class="inline-flex items-center gap-1 rounded-control border border-primary/30 bg-primary-soft/60 px-2.5 py-1 text-xs font-semibold text-primary hover:bg-primary hover:text-white transition cursor-pointer"
                                                       title="Bagi SKS pengampu di Pembagian Mengajar">
                                                        <i data-lucide="users" class="size-3.5"></i>
                                                        <span>Atur SKS &rarr;</span>
                                                    </a>

                                                    {{-- Tombol Hapus --}}
                                                    @if ($isScheduled)
                                                        <button type="button"
                                                                disabled
                                                                class="inline-flex items-center gap-1 rounded-control border border-border bg-page px-2 py-1 text-xs font-medium text-muted/40 cursor-not-allowed"
                                                                title="Tidak dapat dihapus karena sudah memiliki sesi jadwal di draf. Hapus sesi terlebih dahulu.">
                                                            <i data-lucide="trash-2" class="size-3.5"></i>
                                                            <span>Hapus</span>
                                                        </button>
                                                    @else
                                                        <button type="button"
                                                                @click="confirmDelete({{ $offering->id }}, @js($offering->course->name.' ('.$offering->classGroup->name.')'), '{{ route('admin.academic.offerings.destroy', $offering) }}')"
                                                                class="inline-flex items-center gap-1 rounded-control border border-danger/30 bg-danger-soft/30 px-2 py-1 text-xs font-semibold text-danger hover:bg-danger hover:text-white transition cursor-pointer"
                                                                title="Hapus penawaran kuliah ini">
                                                            <i data-lucide="trash-2" class="size-3.5" aria-hidden="true"></i>
                                                            <span>Hapus</span>
                                                        </button>
                                                    @endif
                                                </div>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="6" class="py-10 text-center text-muted">Belum ada penawaran perkuliahan.</td></tr>
                                    @endforelse

                                    @if ($offerings->isNotEmpty())
                                        {{-- Empty State ketika Filter tidak menemukan hasil --}}
                                        <tr x-show="visibleCount === 0" x-cloak>
                                            <td colspan="6" class="py-12 text-center text-muted">
                                                <div class="mx-auto flex size-12 items-center justify-center rounded-full bg-page border border-border text-muted mb-3">
                                                    <i data-lucide="search-x" class="size-6 text-muted/70" aria-hidden="true"></i>
                                                </div>
                                                <p class="font-medium text-ink text-sm">Tidak ada penawaran yang sesuai dengan filter pencarian.</p>
                                                <p class="text-xs text-muted mt-1">Coba sesuaikan kata kunci atau bersihkan kriteria filter.</p>
                                                <div class="mt-4">
                                                    <button type="button" @click="resetFilters()" class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border border-border bg-surface px-3 py-1.5 text-xs font-semibold text-primary hover:bg-page transition shadow-2xs">
                                                        <i data-lucide="rotate-ccw" class="size-3.5" aria-hidden="true"></i>
                                                        Reset semua filter
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    @endif
                                </tbody>
                            </table>
                        </x-table-scroll>
                    </x-card>

                    {{-- FLOATING BATCH ACTION BAR --}}
                    <x-batch-action-bar unit="penawaran">
                        <button type="button" @click="batchDeleteModalOpen = true" class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg bg-danger px-3.5 py-1.5 text-xs font-semibold text-white shadow-xs transition hover:bg-danger/90">
                            <i data-lucide="trash-2" class="size-3.5" aria-hidden="true"></i>
                            Hapus Penawaran Terpilih
                        </button>
                    </x-batch-action-bar>

                    @include('admin.academic.modals.offering-delete-modals')
                </div>
            @endif
