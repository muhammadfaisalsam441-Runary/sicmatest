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

            <div class="flex flex-col gap-6"
                 x-data="{
                     search: '',
                     statusFilter: 'semua',
                     sksFilter: '',
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
                <x-card class="flex flex-col gap-4">
                    {{-- Baris Filter Tabs Status & Tombol Tambah --}}
                    <div class="flex flex-wrap items-center justify-between gap-4 border-b border-border pb-4">
                        <div class="flex flex-wrap items-center gap-2">
                            <button type="button" @click="statusFilter = 'semua'"
                                    :class="statusFilter === 'semua' ? 'bg-primary text-white' : 'bg-page text-muted hover:text-ink hover:bg-border'"
                                    class="rounded-full px-3.5 py-1.5 text-xs font-semibold transition cursor-pointer">
                                Semua ({{ $courseStats['total'] }})
                            </button>
                            <button type="button" @click="statusFilter = 'ditawarkan'"
                                    :class="statusFilter === 'ditawarkan' ? 'bg-success text-white' : 'bg-success-soft/60 text-success hover:bg-success-soft'"
                                    class="rounded-full px-3.5 py-1.5 text-xs font-semibold transition cursor-pointer">
                                Aktif Ditawarkan ({{ $courseStats['offered'] }})
                            </button>
                            <button type="button" @click="statusFilter = 'belum'"
                                    :class="statusFilter === 'belum' ? 'bg-primary text-white' : 'bg-page text-muted hover:text-ink hover:bg-border'"
                                    class="rounded-full px-3.5 py-1.5 text-xs font-semibold transition cursor-pointer">
                                Belum Ditawarkan ({{ $courseStats['unoffered'] }})
                            </button>
                        </div>
                    </div>

                    {{-- Baris Input Pencarian, Filter SKS, dan Reset --}}
                    <div class="grid grid-cols-1 items-end gap-3 sm:grid-cols-12">
                        <div class="sm:col-span-6">
                            <label class="mb-1 block text-xs font-medium text-muted">Cari Kode MK atau Nama Mata Kuliah</label>
                            <div class="relative">
                                <i data-lucide="search" class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted"></i>
                                <input type="text" x-model="search" placeholder="Ketik kode (misal: TKM2301) atau nama mata kuliah..."
                                       class="w-full rounded-control border border-border bg-surface pl-9 pr-8 py-2 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                                <button type="button" x-show="search.trim() !== ''" @click="search = ''" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-muted hover:text-ink cursor-pointer" title="Bersihkan pencarian">
                                    <i data-lucide="x" class="size-3.5"></i>
                                </button>
                            </div>
                        </div>

                        <div class="sm:col-span-4">
                            <label class="mb-1 block text-xs font-medium text-muted">Filter Bobot SKS</label>
                            <select x-model="sksFilter" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                                <option value="">Semua Bobot SKS</option>
                                @foreach ($distinctSks as $sksVal)
                                    <option value="{{ $sksVal }}">{{ $sksVal }} SKS</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="sm:col-span-2">
                            <button type="button" @click="resetFilters()"
                                    x-show="hasActiveFilters"
                                    x-cloak
                                    class="inline-flex h-[38px] w-full items-center justify-center gap-1.5 rounded-control border border-border px-3 text-xs font-semibold text-muted hover:text-ink hover:bg-page transition cursor-pointer">
                                <i data-lucide="rotate-ccw" class="size-3.5"></i>
                                <span>Reset Filter</span>
                            </button>
                        </div>
                    </div>

                    {{-- Informasi Jumlah Tampil --}}
                    <div class="flex items-center justify-between text-xs text-muted pt-1">
                        <div>
                            Menampilkan <span class="font-bold text-ink" x-text="matchCount"></span> dari {{ $courseStats['total'] }} mata kuliah terdaftar
                        </div>
                        <span x-show="hasActiveFilters" x-cloak class="text-xs text-primary font-medium">Filter sedang aktif</span>
                    </div>

                    {{-- TABEL MATA KULIAH --}}
                    <div class="overflow-x-auto rounded-lg border border-border">
                        <table class="w-full border-collapse text-left text-sm">
                            <thead class="border-b border-border bg-page text-xs font-semibold text-muted">
                                <tr>
                                    <th class="w-32 px-4 py-3">Kode MK</th>
                                    <th class="px-4 py-3">Nama Mata Kuliah</th>
                                    <th class="w-28 px-4 py-3 text-center">Bobot SKS</th>
                                    <th class="w-48 px-4 py-3 text-center">Status Penawaran</th>
                                    <th class="w-36 px-4 py-3 text-right">Aksi</th>
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
                                                    <i data-lucide="arrow-up-right" class="size-3"></i>
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
                                                        x-data x-on:click="$dispatch('open-modal', 'course-{{ $course->id }}')">
                                                    <i data-lucide="pencil" class="size-3.5"></i>
                                                    <span>Edit</span>
                                                </button>

                                                @if ($isOffered)
                                                    <span class="inline-flex items-center gap-1 rounded-control border border-border bg-page px-2.5 py-1.5 text-xs font-medium text-muted/50 cursor-not-allowed"
                                                          title="Tidak dapat dihapus karena masih digunakan pada {{ $course->offerings_count }} rombel penawaran kelas. Hapus penawarannya terlebih dahulu.">
                                                        <i data-lucide="trash-2" class="size-3.5"></i>
                                                        <span>Hapus</span>
                                                    </span>
                                                @else
                                                    <form method="POST" action="{{ route('admin.academic.courses.destroy', $course) }}" class="inline"
                                                          onsubmit="return confirm('Apakah Anda yakin ingin menghapus mata kuliah {{ addslashes($course->name) }} ({{ $course->code }})? Tindakan ini tidak dapat dibatalkan.');">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit" class="inline-flex items-center gap-1 rounded-control border border-danger/30 bg-danger-soft/30 px-2.5 py-1.5 text-xs font-semibold text-danger hover:bg-danger hover:text-white transition cursor-pointer">
                                                            <i data-lucide="trash-2" class="size-3.5"></i>
                                                            <span>Hapus</span>
                                                        </button>
                                                    </form>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="py-12 text-center text-muted">
                                            <i data-lucide="book-x" class="mx-auto size-8 text-muted/60 mb-2"></i>
                                            <p class="font-medium text-ink">Belum ada data mata kuliah.</p>
                                        </td>
                                    </tr>
                                @endforelse

                                {{-- Pesan jika pencarian menghasilkan 0 baris --}}
                                <tr x-show="matchCount === 0" x-cloak>
                                    <td colspan="5" class="py-12 text-center">
                                        <div class="flex flex-col items-center justify-center gap-2">
                                            <i data-lucide="search-x" class="size-8 text-muted/60"></i>
                                            <p class="text-sm font-semibold text-ink">Tidak ada mata kuliah yang cocok</p>
                                            <p class="text-xs text-muted">Tidak ditemukan mata kuliah dengan filter atau kata kunci tersebut.</p>
                                            <button type="button" @click="resetFilters()"
                                                    class="mt-2 inline-flex items-center gap-1.5 rounded-control border border-border bg-surface px-3 py-1.5 text-xs font-semibold text-primary hover:bg-primary-soft transition cursor-pointer">
                                                <i data-lucide="rotate-ccw" class="size-3.5"></i>
                                                <span>Reset Filter</span>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </x-card>
            </div>
