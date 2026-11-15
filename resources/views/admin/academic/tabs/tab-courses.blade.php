            @php
                $courseStats = [
                    'total' => $courses->count(),
                    'total_sks' => $courses->sum('sks'),
                    'avg_sks' => $courses->count() > 0 ? round($courses->avg('sks'), 1) : 0,
                    'offered' => $courses->where('offerings_count', '>', 0)->count(),
                    'unoffered' => $courses->where('offerings_count', 0)->count(),
                    'total_classes' => $courses->sum('offerings_count'),
                ];
                $distinctSks = $courses->pluck('sks')->unique()->sort()->values();
            @endphp

            <div class="flex flex-col gap-4 admin-page-stack"
                 @keydown.escape.window="deleteCourseModalOpen = false"
                 x-data="{
                     search: '',
                     statusFilter: 'semua',
                     sksFilter: '',
                     deleteCourseModalOpen: false,
                     deleteCourseUrl: '',
                     deleteCourseName: '',
                     confirmDeleteCourse(url, name) {
                         this.deleteCourseUrl = url;
                         this.deleteCourseName = name;
                         this.deleteCourseModalOpen = true;
                     },
                     courseList: [
                         @foreach ($courses as $c)
                             { id: {{ $c->id }}, search: @js(mb_strtolower($c->code.' '.$c->name)), status: '{{ $c->offerings_count > 0 ? 'ditawarkan' : 'belum' }}', sks: '{{ $c->sks }}' },
                         @endforeach
                     ],
                     isRowMatch(searchStr, status, sks) {
                         const q = this.search.trim().toLowerCase();
                         const matchesSearch = !q || searchStr.includes(q);
                         const matchesStatus = this.statusFilter === 'semua' || status === this.statusFilter;
                         const matchesSks = !this.sksFilter || sks === this.sksFilter;
                         return matchesSearch && matchesStatus && matchesSks;
                     },
                     get matchCount() {
                         const q = this.search.trim().toLowerCase();
                         return this.courseList.filter(c => {
                             const matchesSearch = !q || c.search.includes(q);
                             const matchesStatus = this.statusFilter === 'semua' || c.status === this.statusFilter;
                             const matchesSks = !this.sksFilter || c.sks === this.sksFilter;
                             return matchesSearch && matchesStatus && matchesSks;
                         }).length;
                     },
                     get hasActiveFilters() {
                         return this.search.trim() !== '' || this.statusFilter !== 'semua' || this.sksFilter !== '';
                     },
                     resetFilters() {
                         this.search = '';
                         this.statusFilter = 'semua';
                         this.sksFilter = '';
                     }
                 }">

                {{-- Card Konten & Toolbar Filter --}}
                <x-card padding="p-4" class="admin-table-card flex flex-col gap-3">
                    {{-- Baris Filter Tabs Status --}}
                    <div class="flex flex-wrap items-center gap-1.5 border-b border-border pb-2.5">
                        <button type="button" @click="statusFilter = 'semua'"
                                :class="statusFilter === 'semua' ? 'bg-primary text-white' : 'bg-page text-muted hover:text-ink hover:bg-border'"
                                class="rounded-full px-3 py-1 text-xs font-semibold transition cursor-pointer">
                            Semua ({{ $courseStats['total'] }})
                        </button>
                        <button type="button" @click="statusFilter = 'ditawarkan'"
                                :class="statusFilter === 'ditawarkan' ? 'bg-success text-white' : 'bg-success-soft/60 text-success hover:bg-success-soft'"
                                class="rounded-full px-3 py-1 text-xs font-semibold transition cursor-pointer">
                            Aktif Ditawarkan ({{ $courseStats['offered'] }})
                        </button>
                        <button type="button" @click="statusFilter = 'belum'"
                                :class="statusFilter === 'belum' ? 'bg-primary text-white' : 'bg-page text-muted hover:text-ink hover:bg-border'"
                                class="rounded-full px-3 py-1 text-xs font-semibold transition cursor-pointer">
                            Belum Ditawarkan ({{ $courseStats['unoffered'] }})
                        </button>
                    </div>

                    {{-- Baris Input Pencarian, Filter SKS, dan Reset --}}
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                        <div class="relative flex-1 min-w-[200px]">
                            <i data-lucide="search" class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted" aria-hidden="true"></i>
                            <input id="course-search" type="search" x-model="search" data-table-search
                                   aria-label="Cari Kode MK atau Nama Mata Kuliah"
                                   placeholder="Cari kode (misal: TKM2301) atau nama mata kuliah..."
                                   class="w-full rounded-control border border-border bg-surface pl-9 pr-8 py-2 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                            <button type="button" x-show="search.trim() !== ''" x-cloak @click="search = ''" aria-label="Bersihkan pencarian" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-muted hover:text-ink cursor-pointer" title="Bersihkan pencarian">
                                <i data-lucide="x" class="size-3.5" aria-hidden="true"></i>
                            </button>
                        </div>

                        <select id="course-sks-filter" x-model="sksFilter" aria-label="Filter Bobot SKS" class="w-full sm:w-48 rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                            <option value="">Semua Bobot SKS</option>
                            @foreach ($distinctSks as $sksVal)
                                <option value="{{ $sksVal }}">{{ $sksVal }} SKS</option>
                            @endforeach
                        </select>

                        <button type="button" @click="resetFilters()"
                                x-show="hasActiveFilters"
                                x-cloak
                                class="inline-flex h-[38px] shrink-0 items-center justify-center gap-1.5 rounded-control border border-border px-3 text-xs font-semibold text-muted hover:text-ink hover:bg-page transition cursor-pointer">
                            <i data-lucide="rotate-ccw" class="size-3.5" aria-hidden="true"></i>
                            <span>Reset</span>
                        </button>
                    </div>

                    {{-- TABEL MATA KULIAH --}}
                    <x-table-scroll>
                        <x-slot:title>Menampilkan <strong class="text-ink" x-text="matchCount"></strong> dari {{ $courseStats['total'] }} mata kuliah<span x-show="hasActiveFilters" x-cloak class="ml-1.5 font-medium text-primary">• Filter aktif</span></x-slot:title>
                        <x-slot:headerActions>
                            <x-table-density-toggle />
                        </x-slot:headerActions>
                        <table class="w-full border-collapse text-left text-sm">
                            <thead class="sticky top-0 z-10 border-b border-border bg-page text-xs font-semibold text-muted">
                                <tr>
                                    <th scope="col" class="w-32 px-4 py-3">Kode MK</th>
                                    <th scope="col" class="px-4 py-3">Nama Mata Kuliah</th>
                                    <th scope="col" class="w-28 px-4 py-3 text-center">Bobot SKS</th>
                                    <th scope="col" class="w-48 px-4 py-3 text-center">Status Penawaran</th>
                                    <th scope="col" class="w-36 px-4 py-3 text-right">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                @forelse ($courses as $course)
                                    @php
                                        $searchStr = mb_strtolower($course->code.' '.$course->name);
                                        $isOffered = $course->offerings_count > 0;
                                        $statusKey = $isOffered ? 'ditawarkan' : 'belum';
                                    @endphp
                                    <tr data-search="{{ $searchStr }}"
                                        data-status="{{ $statusKey }}"
                                        data-sks="{{ $course->sks }}"
                                        x-show="isRowMatch($el.dataset.search, $el.dataset.status, $el.dataset.sks)"
                                        class="border-b border-border transition hover:bg-primary-soft/20">

                                        {{-- Kode MK --}}
                                        <td class="px-4 py-3">
                                            <span class="inline-flex items-center rounded-md border border-border bg-page px-2 py-1 font-mono text-xs font-bold text-ink">
                                                {{ $course->code }}
                                            </span>
                                        </td>

                                        {{-- Nama Mata Kuliah --}}
                                        <td class="px-4 py-3">
                                            <div class="flex items-center gap-2.5">
                                                <div class="flex size-7 shrink-0 items-center justify-center rounded-md bg-primary-soft text-primary">
                                                    <i data-lucide="book-open" class="size-3.5"></i>
                                                </div>
                                                <span class="font-semibold text-ink">{{ $course->name }}</span>
                                            </div>
                                        </td>

                                        {{-- Bobot SKS --}}
                                        <td class="px-4 py-3 text-center">
                                            <span class="inline-flex items-center justify-center rounded-full bg-primary-soft px-2.5 py-0.5 text-xs font-bold text-primary">
                                                {{ $course->sks }} SKS
                                            </span>
                                        </td>

                                        {{-- Status Penawaran --}}
                                        <td class="px-4 py-3 text-center">
                                            @if ($isOffered)
                                                <a href="{{ route('admin.academic.index', ['tab' => 'penawaran']) }}"
                                                   class="inline-flex items-center gap-1.5 rounded-full border border-success/30 bg-success-soft/70 px-2.5 py-1 text-xs font-semibold text-success hover:bg-success-soft transition"
                                                   title="Lihat rombel di tab Penawaran Perkuliahan">
                                                    <span class="size-1.5 rounded-full bg-success"></span>
                                                    <span>{{ $course->offerings_count }} Rombel Ditawarkan</span>
                                                    <i data-lucide="arrow-right" class="size-3" aria-hidden="true"></i>
                                                </a>
                                            @else
                                                <span class="inline-flex items-center gap-1.5 rounded-full border border-border bg-page px-2.5 py-1 text-xs font-medium text-muted">
                                                    <span class="size-1.5 rounded-full bg-muted/40"></span>
                                                    <span>Belum Ditawarkan</span>
                                                </span>
                                            @endif
                                        </td>

                                        {{-- Aksi --}}
                                        <td class="px-4 py-3 text-right">
                                            <div class="flex items-center justify-end gap-1.5">
                                                <button type="button"
                                                        class="inline-flex items-center gap-1 rounded-control border border-border bg-surface px-2.5 py-1.5 text-xs font-semibold text-primary hover:bg-primary-soft hover:border-primary/40 transition cursor-pointer"
                                                        @click="$dispatch('course-open-form', @js([
                                                            'id' => $course->id,
                                                            'code' => $course->code,
                                                            'name' => $course->name,
                                                            'sks' => $course->sks,
                                                            'curriculum' => $course->curriculum,
                                                        ]))">
                                                    <i data-lucide="pencil" class="size-3.5" aria-hidden="true"></i>
                                                    <span>Edit</span>
                                                </button>

                                                @if ($isOffered)
                                                    <span class="inline-flex items-center gap-1 rounded-control border border-border bg-page px-2.5 py-1.5 text-xs font-medium text-muted/50 cursor-not-allowed"
                                                          title="Tidak dapat dihapus karena masih digunakan pada {{ $course->offerings_count }} rombel penawaran kelas. Hapus penawarannya terlebih dahulu.">
                                                        <i data-lucide="trash-2" class="size-3.5" aria-hidden="true"></i>
                                                        <span>Hapus</span>
                                                    </span>
                                                @else
                                                    <button type="button"
                                                            @click="confirmDeleteCourse('{{ route('admin.academic.courses.destroy', $course) }}', @js($course->name.' ('.$course->code.')'))"
                                                            class="inline-flex items-center gap-1 rounded-control border border-danger/30 bg-danger-soft/30 px-2.5 py-1.5 text-xs font-semibold text-danger hover:bg-danger hover:text-white transition cursor-pointer">
                                                        <i data-lucide="trash-2" class="size-3.5" aria-hidden="true"></i>
                                                        <span>Hapus</span>
                                                    </button>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="py-12 text-center text-muted">
                                            <i data-lucide="book-open" class="mx-auto size-8 text-muted/60 mb-2" aria-hidden="true"></i>
                                            <p class="font-medium text-ink">Belum ada data mata kuliah.</p>
                                        </td>
                                    </tr>
                                @endforelse

                                @if ($courses->isNotEmpty())
                                    {{-- Pesan jika pencarian menghasilkan 0 baris --}}
                                    <tr x-show="matchCount === 0" x-cloak>
                                        <td colspan="5" class="py-12 text-center">
                                            <div class="flex flex-col items-center justify-center gap-2">
                                                <i data-lucide="search-x" class="size-8 text-muted/60" aria-hidden="true"></i>
                                                <p class="text-sm font-semibold text-ink">Tidak ada mata kuliah yang cocok</p>
                                                <p class="text-xs text-muted">Tidak ditemukan mata kuliah dengan filter atau kata kunci tersebut.</p>
                                                <button type="button" @click="resetFilters()"
                                                        class="mt-2 inline-flex items-center gap-1.5 rounded-control border border-border bg-surface px-3 py-1.5 text-xs font-semibold text-primary hover:bg-primary-soft transition cursor-pointer">
                                                    <i data-lucide="rotate-ccw" class="size-3.5" aria-hidden="true"></i>
                                                    <span>Reset Filter</span>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                @endif
                            </tbody>
                        </table>
                    </x-table-scroll>
                </x-card>

                {{-- MODAL KONFIRMASI HAPUS MATA KULIAH --}}
                <div x-show="deleteCourseModalOpen" x-cloak role="dialog" aria-modal="true" aria-labelledby="delete-course-modal-title" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                    <div class="absolute inset-0 bg-ink/80 backdrop-blur-sm" @click="deleteCourseModalOpen = false"></div>
                    <div class="relative w-full max-w-md rounded-2xl bg-surface p-6 shadow-2xl">
                        <div class="flex items-start justify-between border-b border-border pb-3">
                            <h3 id="delete-course-modal-title" class="text-base font-bold text-ink">Hapus mata kuliah?</h3>
                            <button type="button" @click="deleteCourseModalOpen = false" aria-label="Tutup dialog" class="cursor-pointer rounded-md p-1 text-muted hover:bg-slate-100 hover:text-ink">
                                <i data-lucide="x" class="size-4" aria-hidden="true"></i>
                            </button>
                        </div>
                        <p class="mt-3 text-sm text-muted">
                            Mata kuliah <strong class="text-ink" x-text="deleteCourseName"></strong> akan dihapus permanen dan tindakan ini tidak dapat dibatalkan.
                        </p>
                        <form method="POST" :action="deleteCourseUrl" class="mt-5 flex justify-end gap-2">
                            @csrf
                            @method('DELETE')
                            <x-button type="button" variant="ghost" @click="deleteCourseModalOpen = false">Batal</x-button>
                            <x-button type="submit" variant="danger" icon="trash-2">Hapus Mata Kuliah</x-button>
                        </form>
                    </div>
                </div>
            </div>
