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
                    selectedIds: [],
                    visibleIds: @js($offerings->pluck('id')->map(fn ($id) => (int) $id)->values()->all()),
                    visibleCount: {{ $offerings->count() }},
                    deleteModalOpen: false,
                    offeringToDelete: { id: null, name: '', deleteUrl: '' },
                    batchDeleteModalOpen: false,

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
                x-init="
                    $watch('search', () => updateVisibleCount());
                    $watch('filterProdi', () => updateVisibleCount());
                    $watch('filterCohort', () => updateVisibleCount());
                    $watch('filterStatus', () => updateVisibleCount());
                    $watch('filterScheduled', () => updateVisibleCount());
                    $watch('filterSks', () => updateVisibleCount());
                    $watch('filterClass', () => updateVisibleCount());
                "
                class="flex flex-col gap-5">

                    {{-- Ringkasan Metrik Cepat Penawaran --}}
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                        <button type="button"
                                @click="resetFilters()"
                                class="group relative flex flex-col justify-between rounded-xl border p-4 text-left transition-all duration-150"
                                :class="{
                                    'border-primary bg-primary-soft/30 shadow-xs ring-1 ring-primary/40': !filterStatus && !filterScheduled,
                                    'border-border bg-surface hover:border-primary/50 hover:bg-page hover:shadow-xs': filterStatus || filterScheduled
                                }">
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-xs font-semibold text-muted group-hover:text-ink transition">Total Penawaran</span>
                                <div class="flex size-7 items-center justify-center rounded-lg bg-page border border-border text-muted group-hover:text-primary group-hover:border-primary/40 transition">
                                    <i data-lucide="book-open" class="size-4"></i>
                                </div>
                            </div>
                            <div class="mt-3 flex flex-col">
                                <span class="text-2xl font-bold tracking-tight text-ink">{{ number_format($offeringStats['total']) }}</span>
                                <span class="text-[11px] text-muted">{{ number_format($offeringStats['total_sks']) }} Total SKS ditawarkan</span>
                            </div>
                        </button>

                        <button type="button"
                                @click="toggleStatusFilter('unassigned')"
                                class="group relative flex flex-col justify-between rounded-xl border p-4 text-left transition-all duration-150"
                                :class="{
                                    'border-warning bg-warning-soft/40 shadow-xs ring-1 ring-warning/50': filterStatus === 'unassigned',
                                    'border-warning/40 bg-warning-soft/20 hover:border-warning hover:bg-warning-soft/30 hover:shadow-xs': filterStatus !== 'unassigned' && {{ $offeringStats['unassigned'] }} > 0,
                                    'border-border bg-surface hover:border-warning/50 hover:bg-page hover:shadow-xs': filterStatus !== 'unassigned' && {{ $offeringStats['unassigned'] }} === 0
                                }">
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-xs font-semibold text-warning-ink">Belum Ada Dosen</span>
                                <div class="flex size-7 items-center justify-center rounded-lg bg-warning-soft border border-warning/30 text-warning-ink">
                                    <i data-lucide="triangle-alert" class="size-4"></i>
                                </div>
                            </div>
                            <div class="mt-3 flex flex-col">
                                <span class="text-2xl font-bold tracking-tight text-warning-ink">{{ number_format($offeringStats['unassigned']) }}</span>
                                <span class="text-[11px] text-muted">Perlu pembagian SKS dosen</span>
                            </div>
                        </button>

                        <button type="button"
                                @click="toggleStatusFilter('assigned')"
                                class="group relative flex flex-col justify-between rounded-xl border p-4 text-left transition-all duration-150"
                                :class="{
                                    'border-primary bg-primary-soft/30 shadow-xs ring-1 ring-primary/40': filterStatus === 'assigned',
                                    'border-border bg-surface hover:border-primary/50 hover:bg-page hover:shadow-xs': filterStatus !== 'assigned'
                                }">
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-xs font-semibold text-muted group-hover:text-ink transition">Sudah Ada Dosen</span>
                                <div class="flex size-7 items-center justify-center rounded-lg bg-page border border-border text-muted group-hover:text-primary group-hover:border-primary/40 transition">
                                    <i data-lucide="users" class="size-4"></i>
                                </div>
                            </div>
                            <div class="mt-3 flex flex-col">
                                <span class="text-2xl font-bold tracking-tight text-ink">{{ number_format($offeringStats['assigned']) }}</span>
                                <span class="text-[11px] text-muted">Pengampu sudah ditetapkan</span>
                            </div>
                        </button>

                        <button type="button"
                                @click="toggleScheduledFilter('scheduled')"
                                class="group relative flex flex-col justify-between rounded-xl border p-4 text-left transition-all duration-150"
                                :class="{
                                    'border-success bg-success-soft/40 shadow-xs ring-1 ring-success/40': filterScheduled === 'scheduled',
                                    'border-border bg-surface hover:border-success/50 hover:bg-page hover:shadow-xs': filterScheduled !== 'scheduled'
                                }">
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-xs font-semibold text-muted group-hover:text-ink transition">Sudah Terjadwal</span>
                                <div class="flex size-7 items-center justify-center rounded-lg bg-page border border-border text-muted group-hover:text-success group-hover:border-success/40 transition">
                                    <i data-lucide="clock" class="size-4"></i>
                                </div>
                            </div>
                            <div class="mt-3 flex flex-col">
                                <span class="text-2xl font-bold tracking-tight text-ink">{{ number_format($offeringStats['scheduled']) }}</span>
                                <span class="text-[11px] text-muted">Telah dialokasikan di draf</span>
                            </div>
                        </button>

                        <button type="button"
                                @click="toggleScheduledFilter('unscheduled')"
                                class="group relative flex flex-col justify-between rounded-xl border p-4 text-left transition-all duration-150"
                                :class="{
                                    'border-primary bg-primary-soft/30 shadow-xs ring-1 ring-primary/40': filterScheduled === 'unscheduled',
                                    'border-border bg-surface hover:border-primary/50 hover:bg-page hover:shadow-xs': filterScheduled !== 'unscheduled'
                                }">
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-xs font-semibold text-muted group-hover:text-ink transition">Belum Terjadwal</span>
                                <div class="flex size-7 items-center justify-center rounded-lg bg-page border border-border text-muted group-hover:text-primary group-hover:border-primary/40 transition">
                                    <i data-lucide="circle-alert" class="size-4"></i>
                                </div>
                            </div>
                            <div class="mt-3 flex flex-col">
                                <span class="text-2xl font-bold tracking-tight text-ink">{{ number_format($offeringStats['total'] - $offeringStats['scheduled']) }}</span>
                                <span class="text-[11px] text-muted">Belum memiliki sesi</span>
                            </div>
                        </button>
                    </div>

                    {{-- Toolbar Filter & Pencarian Cepat --}}
                    <x-card class="flex flex-col gap-4 p-5 shadow-xs">
                        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border pb-3.5">
                            <div class="flex items-center gap-2">
                                <i data-lucide="filter" class="size-4 text-primary"></i>
                                <span class="text-xs font-bold uppercase tracking-wider text-ink">Filter & Pencarian Penawaran</span>
                            </div>
                            <div class="flex items-center gap-3">
                                <span class="text-xs text-muted">
                                    Menampilkan <span class="font-semibold text-ink" x-text="visibleCount"></span> dari <span class="font-semibold text-ink">{{ $offerings->count() }}</span> penawaran
                                </span>
                            </div>
                        </div>

                        {{-- Baris Filter 1: Search & Prodi & Angkatan & Status Dosen --}}
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-12 items-end">
                            <div class="sm:col-span-5">
                                <label class="mb-1.5 block text-xs font-medium text-muted">Cari Mata Kuliah / Kode / Kelas / Dosen</label>
                                <div class="relative">
                                    <i data-lucide="search" class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted"></i>
                                    <input type="search" x-model="search" placeholder="Ketik nama MK, kode, kelas, atau dosen..." aria-label="Cari penawaran"
                                           class="w-full rounded-control border border-border bg-surface py-2 pl-9 pr-8 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary shadow-2xs">
                                    <button type="button" x-show="search.length > 0" @click="search = ''" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-muted hover:text-ink">
                                        <i data-lucide="x" class="size-3.5"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="sm:col-span-3">
                                <label class="mb-1.5 block text-xs font-medium text-muted">Program Studi</label>
                                <select x-model="filterProdi" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary shadow-2xs">
                                    <option value="">Semua Program Studi</option>
                                    @foreach ($offeringPrograms as $prog)
                                        <option value="{{ $prog }}">{{ $prog }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="sm:col-span-2">
                                <label class="mb-1.5 block text-xs font-medium text-muted">Angkatan</label>
                                <select x-model="filterCohort" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary shadow-2xs">
                                    <option value="">Semua Angkatan</option>
                                    @foreach ($offeringCohorts as $cohort)
                                        <option value="{{ $cohort }}">Angkatan {{ $cohort }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="sm:col-span-2">
                                <label class="mb-1.5 block text-xs font-medium text-muted">Status Dosen</label>
                                <select x-model="filterStatus" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary shadow-2xs">
                                    <option value="">Semua Status Dosen</option>
                                    <option value="unassigned">⚠️ Belum Ada Dosen</option>
                                    <option value="assigned">✅ Sudah Ada Dosen</option>
                                </select>
                            </div>
                        </div>

                        {{-- Baris Filter 2: SKS & Kelas & Status Jadwal & Reset --}}
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-12 items-end pt-1">
                            <div class="sm:col-span-3">
                                <label class="mb-1.5 block text-xs font-medium text-muted">Filter Kelas Spesifik</label>
                                <select x-model="filterClass" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary shadow-2xs">
                                    <option value="">Semua Kelas</option>
                                    @foreach ($offeringClasses as $cls)
                                        <option value="{{ $cls->id }}">{{ $cls->name }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="sm:col-span-2">
                                <label class="mb-1.5 block text-xs font-medium text-muted">Bobot SKS</label>
                                <select x-model="filterSks" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary shadow-2xs">
                                    <option value="">Semua SKS</option>
                                    @foreach ($offeringSksList as $sks)
                                        <option value="{{ $sks }}">{{ $sks }} SKS</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="sm:col-span-3">
                                <label class="mb-1.5 block text-xs font-medium text-muted">Status Penjadwalan</label>
                                <select x-model="filterScheduled" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary shadow-2xs">
                                    <option value="">Semua Status Jadwal</option>
                                    <option value="scheduled">Sudah Terjadwal (Ada Sesi)</option>
                                    <option value="unscheduled">Belum Dijadwalkan</option>
                                </select>
                            </div>

                            <div class="sm:col-span-4 flex items-center justify-end gap-2">
                                <button type="button"
                                        x-show="hasActiveFilters()"
                                        @click="resetFilters()"
                                        class="inline-flex h-[38px] items-center justify-center gap-1.5 rounded-control border border-border bg-surface px-3 text-xs font-semibold text-muted hover:text-ink hover:bg-page transition shadow-2xs"
                                        title="Hapus semua filter aktif">
                                    <i data-lucide="rotate-ccw" class="size-3.5"></i>
                                    <span>Reset Filter</span>
                                </button>
                                <a href="{{ route('admin.teaching.index') }}"
                                   class="inline-flex h-[38px] items-center justify-center gap-1.5 rounded-control border border-primary/30 bg-primary-soft/40 px-3 text-xs font-semibold text-primary hover:bg-primary hover:text-white transition shadow-2xs"
                                   title="Buka halaman Pembagian Mengajar Dosen">
                                    <i data-lucide="users" class="size-3.5"></i>
                                    <span>Ke Pembagian SKS &rarr;</span>
                                </a>
                            </div>
                        </div>
                    </x-card>

                    {{-- TABEL DATA PENAWARAN --}}
                    <div class="overflow-hidden rounded-card border border-border bg-surface shadow-xs">
                        <div class="overflow-x-auto">
                            <table class="w-full border-collapse text-left text-sm">
                                <thead class="border-b border-border bg-page text-xs font-semibold text-muted">
                                    <tr>
                                        <th class="w-10 px-4 py-3 text-center">
                                            <input type="checkbox"
                                                   @change="toggleSelectAll($event.target.checked)"
                                                   :checked="isAllSelected()"
                                                   class="size-4 rounded border-border text-primary focus:ring-primary cursor-pointer"
                                                   title="Pilih semua yang tampil">
                                        </th>
                                        <th class="px-4 py-3">Kelas Rombel</th>
                                        <th class="px-4 py-3">Mata Kuliah Ditawarkan</th>
                                        <th class="px-4 py-3 text-center">Bobot SKS</th>
                                        <th class="px-4 py-3 text-center">Status Pengampu</th>
                                        <th class="px-4 py-3 text-right">Aksi</th>
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
                                                                @click="confirmDelete({{ $offering->id }}, '{{ addslashes($offering->course->name.' ('.$offering->classGroup->name.')') }}', '{{ route('admin.academic.offerings.destroy', $offering) }}')"
                                                                class="inline-flex items-center gap-1 rounded-control border border-danger/30 bg-danger-soft/30 px-2 py-1 text-xs font-semibold text-danger hover:bg-danger hover:text-white transition cursor-pointer"
                                                                title="Hapus penawaran kuliah ini">
                                                            <i data-lucide="trash-2" class="size-3.5"></i>
                                                            <span>Hapus</span>
                                                        </button>
                                                    @endif
                                                </div>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="6" class="py-10 text-center text-muted">Belum ada penawaran perkuliahan.</td></tr>
                                    @endforelse

                                    {{-- Empty State ketika Filter tidak menemukan hasil --}}
                                    <tr x-show="visibleCount === 0" x-cloak>
                                        <td colspan="6" class="py-12 text-center text-muted">
                                            <div class="mx-auto flex size-12 items-center justify-center rounded-full bg-page border border-border text-muted mb-3">
                                                <i data-lucide="search-x" class="size-6 text-muted/70"></i>
                                            </div>
                                            <p class="font-medium text-ink text-sm">Tidak ada penawaran yang sesuai dengan filter pencarian.</p>
                                            <p class="text-xs text-muted mt-1">Coba sesuaikan kata kunci atau bersihkan kriteria filter.</p>
                                            <div class="mt-4">
                                                <button type="button" @click="resetFilters()" class="inline-flex items-center gap-1.5 rounded-lg border border-border bg-surface px-3 py-1.5 text-xs font-semibold text-primary hover:bg-page transition shadow-2xs">
                                                    <i data-lucide="rotate-ccw" class="size-3.5"></i>
                                                    Reset semua filter
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    {{-- FLOATING BATCH ACTION BAR --}}
                    <div x-show="selectedIds.length > 0"
                        x-cloak
                        x-transition:enter="transition ease-out duration-200"
                        x-transition:enter-start="opacity-0 translate-y-4"
                        x-transition:enter-end="opacity-100 translate-y-0"
                        x-transition:leave="transition ease-in duration-150"
                        x-transition:leave-start="opacity-100 translate-y-0"
                        x-transition:leave-end="opacity-0 translate-y-4"
                        class="fixed bottom-6 left-1/2 z-40 flex -translate-x-1/2 flex-wrap items-center gap-3 rounded-xl border border-primary/30 bg-ink px-6 py-3.5 text-surface shadow-2xl">
                        <div class="flex items-center gap-2">
                            <i data-lucide="circle-check" class="size-5 text-success"></i>
                            <span class="text-sm font-semibold"><span x-text="selectedIds.length"></span> penawaran dipilih</span>
                            <button type="button"
                                    x-show="!isAllSelected()"
                                    @click="toggleSelectAll(true)"
                                    class="ml-1 rounded-md border border-surface/20 bg-surface/10 px-2 py-0.5 text-[11px] font-semibold text-surface hover:bg-surface/20 transition cursor-pointer">
                                Pilih Semua Tampil
                            </button>
                        </div>
                        <div class="h-4 w-px bg-surface/20"></div>

                        <button type="button" @click="batchDeleteModalOpen = true" class="inline-flex items-center gap-1.5 rounded-lg bg-danger px-3.5 py-1.5 text-xs font-semibold text-white shadow-xs hover:bg-danger/90 transition cursor-pointer">
                            <i data-lucide="trash-2" class="size-3.5"></i>
                            Hapus Penawaran Terpilih
                        </button>

                        <button type="button" @click="selectedIds = []" class="text-xs text-surface/70 hover:text-white transition ml-2 cursor-pointer">
                            Batalkan Pilihan
                        </button>
                    </div>

                    {{-- MODAL KONFIRMASI HAPUS SATUAN --}}
                    <div x-show="deleteModalOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
                        <div class="absolute inset-0 bg-ink/80 backdrop-blur-sm" @click="deleteModalOpen = false"></div>
                        <div class="relative w-full max-w-md rounded-2xl bg-surface p-6 shadow-2xl">
                            <div class="flex items-start justify-between border-b border-border pb-3">
                                <h3 class="text-lg font-bold text-ink">Hapus Penawaran Kuliah?</h3>
                                <button type="button" @click="deleteModalOpen = false" class="text-muted hover:text-ink">
                                    <i data-lucide="x" class="size-5"></i>
                                </button>
                            </div>
                            <p class="mt-3 text-xs text-muted">
                                Apakah Anda yakin ingin menghapus penawaran <strong class="text-ink" x-text="offeringToDelete.name"></strong>?
                                Pembagian SKS dosen pada penawaran ini juga akan ikut dihapus.
                            </p>
                            <form method="POST" :action="offeringToDelete.deleteUrl" class="mt-5 flex justify-end gap-2">
                                @csrf
                                @method('DELETE')
                                <x-button variant="secondary" type="button" @click="deleteModalOpen = false">Batal</x-button>
                                <x-button variant="danger" type="submit" icon="trash-2">Hapus Penawaran</x-button>
                            </form>
                        </div>
                    </div>

                    {{-- MODAL KONFIRMASI HAPUS BATCH --}}
                    <div x-show="batchDeleteModalOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
                        <div class="absolute inset-0 bg-ink/80 backdrop-blur-sm" @click="batchDeleteModalOpen = false"></div>
                        <div class="relative w-full max-w-md rounded-2xl bg-surface p-6 shadow-2xl">
                            <div class="flex items-start justify-between border-b border-border pb-3">
                                <div>
                                    <span class="text-xs font-semibold text-danger">Penyesuaian Massal</span>
                                    <h3 class="text-lg font-bold text-ink">Hapus (<span x-text="selectedIds.length"></span> Penawaran Terpilih)?</h3>
                                </div>
                                <button type="button" @click="batchDeleteModalOpen = false" class="text-muted hover:text-ink">
                                    <i data-lucide="x" class="size-5"></i>
                                </button>
                            </div>
                            <div class="mt-4 rounded-lg border border-danger/20 bg-danger-soft/40 p-3 text-xs text-ink">
                                Seluruh <strong x-text="selectedIds.length"></strong> penawaran yang sedang dicentang akan dihapus beserta pembagian SKS dosennya. Penawaran yang telah memiliki sesi jadwal di draf otomatis dilindungi dan tidak akan dihapus.
                            </div>
                            <form method="POST" action="{{ route('admin.academic.offerings.batch-delete') }}" class="mt-5">
                                @csrf
                                <template x-for="id in selectedIds" :key="id">
                                    <input type="hidden" name="offering_ids[]" :value="id">
                                </template>
                                <div class="flex justify-end gap-2">
                                    <x-button variant="secondary" type="button" @click="batchDeleteModalOpen = false">Batal</x-button>
                                    <x-button variant="danger" type="submit" icon="trash-2">Terapkan Hapus ke Penawaran Terpilih</x-button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            @endif
