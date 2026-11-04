@use('App\Enums\SessionPhase')
@use('App\Enums\TeachingRole')
@extends('layouts.admin')

@section('title', 'Pembagian SKS')
@section('breadcrumb', 'Pembagian Mengajar')

@section('breadcrumb-actions')
    <x-button variant="secondary" :href="route('admin.dashboard')">Dashboard</x-button>
@endsection

@section('content')
    <div class="flex flex-col gap-6" x-data="teachingManager()">
        <x-page-header title="Pembagian mengajar dan beban SKS" subtitle="Bagi mata kuliah ke dosen yang layak. Tampilan matriks memudahkan pengelolaan massal ratusan kelas layaknya spreadsheet." />

        <x-card>
            <x-stepper :steps="['Data', 'Tim mengajar', 'Draf jadwal', 'Periksa', 'Tetapkan']" :current="2" />
        </x-card>

        @if ($withoutLead > 0)
            <x-alert variant="danger">
                <strong>{{ $withoutLead }} dari {{ $totalCount }} kelas</strong> belum memiliki dosen pengampu. Klik tombol <em>"+ Tugaskan Pengampu"</em> langsung pada baris mata kuliah atau gunakan centang kotak untuk penugasan massal.
            </x-alert>
        @else
            <x-alert variant="success">
                Semua {{ $totalCount }} penawaran kelas telah memiliki dosen pengampu utama! Anda dapat menambahkan dosen mitra atau mengunci penugasan.
            </x-alert>
        @endif

        {{-- Ringkasan Metrik Cepat --}}
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
            <x-card class="flex flex-col gap-1 p-4">
                <span class="text-xs font-medium text-muted">Total Penawaran Kelas</span>
                <span class="text-2xl font-bold text-ink">{{ number_format($totalCount) }}</span>
                <span class="text-xs text-muted">Semester aktif</span>
            </x-card>
            <x-card class="flex flex-col gap-1 p-4 border-danger/30 bg-danger-soft/10">
                <span class="text-xs font-medium text-danger">Belum Ada Pengampu</span>
                <span class="text-2xl font-bold text-danger">{{ number_format($withoutLead) }}</span>
                <span class="text-xs text-danger/80">Perlu segera ditetapkan</span>
            </x-card>
            <x-card class="flex flex-col gap-1 p-4 border-success/30 bg-success-soft/10">
                <span class="text-xs font-medium text-success">Sudah Ada Pengampu</span>
                <span class="text-2xl font-bold text-success">{{ number_format($withLead) }}</span>
                <span class="text-xs text-success/80">Telah terisi</span>
            </x-card>
            <x-card class="flex flex-col gap-1 p-4">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium text-muted">Dosen Terverifikasi</span>
                    <button type="button" @click="rekapModalOpen = true" class="text-xs font-semibold text-primary hover:underline">Lihat Rekap</button>
                </div>
                <span class="text-2xl font-bold text-ink">{{ number_format($lecturerOptionsEnriched->count()) }}</span>
                <span class="text-xs text-muted">Siap ditugaskan</span>
            </x-card>
        </div>

        {{-- Toolbar Filter & Pencarian Cepat --}}
        <x-card class="flex flex-col gap-4">
            <div class="flex flex-wrap items-center justify-between gap-4 border-b border-border pb-4">
                {{-- Tabs Filter Status --}}
                <div class="flex flex-wrap items-center gap-2">
                    <a href="{{ route('admin.teaching.index', array_merge(request()->query(), ['filter' => 'semua', 'page' => 1])) }}"
                       @class([
                           'rounded-full px-3.5 py-1.5 text-xs font-semibold transition',
                           'bg-primary text-white' => $filter === 'semua',
                           'bg-page text-muted hover:text-ink hover:bg-border' => $filter !== 'semua',
                       ])>
                        Semua ({{ number_format($totalCount) }})
                    </a>
                    <a href="{{ route('admin.teaching.index', array_merge(request()->query(), ['filter' => 'tanpa_pengampu', 'page' => 1])) }}"
                       @class([
                           'rounded-full px-3.5 py-1.5 text-xs font-semibold transition',
                           'bg-danger text-white' => $filter === 'tanpa_pengampu',
                           'bg-danger-soft/60 text-danger hover:bg-danger-soft' => $filter !== 'tanpa_pengampu',
                       ])>
                        ⚠️ Belum Ada Pengampu ({{ number_format($withoutLead) }})
                    </a>
                    <a href="{{ route('admin.teaching.index', array_merge(request()->query(), ['filter' => 'lengkap', 'page' => 1])) }}"
                       @class([
                           'rounded-full px-3.5 py-1.5 text-xs font-semibold transition',
                           'bg-success text-white' => $filter === 'lengkap',
                           'bg-success-soft/60 text-success hover:bg-success-soft' => $filter !== 'lengkap',
                       ])>
                        ✅ Sudah Lengkap ({{ number_format($withLead) }})
                    </a>
                </div>

                {{-- Tombol Buka Rekap Beban Dosen --}}
                <div class="flex items-center gap-2">
                    <x-button variant="secondary" icon="chart-column-increasing" @click="rekapModalOpen = true">
                        Rekap Beban SKS Dosen
                    </x-button>
                </div>
            </div>

            {{-- Form Pencarian & Filter Prodi --}}
            <form method="GET" action="{{ route('admin.teaching.index') }}" class="grid grid-cols-1 items-end gap-3 sm:grid-cols-12">
                <input type="hidden" name="filter" value="{{ $filter }}">

                <div class="sm:col-span-6">
                    <label class="mb-1 block text-xs font-medium text-muted">Cari Mata Kuliah / Kelas</label>
                    <div class="relative">
                        <i data-lucide="search" class="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted"></i>
                        <input type="text" name="q" value="{{ $keyword }}" placeholder="Ketik nama mata kuliah, kode MK, atau nama kelas..."
                               class="w-full rounded-control border border-border bg-surface pl-9 pr-3 py-2 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                    </div>
                </div>

                <div class="sm:col-span-3">
                    <label class="mb-1 block text-xs font-medium text-muted">Program Studi</label>
                    <select name="prodi" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                        <option value="">Semua Program Studi</option>
                        @foreach ($studyPrograms as $sp)
                            <option value="{{ $sp }}" @selected($prodi === $sp)>{{ $sp }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="sm:col-span-2">
                    <label class="mb-1 block text-xs font-medium text-muted">Per Halaman</label>
                    <select name="per_page" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                        @foreach ([25, 50, 100] as $n)
                            <option value="{{ $n }}" @selected($perPage == $n)>{{ $n }} baris</option>
                        @endforeach
                    </select>
                </div>

                <div class="flex gap-2 sm:col-span-1">
                    <button type="submit" class="flex h-[38px] w-full items-center justify-center rounded-control bg-primary text-white hover:bg-primary/90" title="Terapkan Filter">
                        <i data-lucide="arrow-right" class="size-4"></i>
                    </button>
                    @if ($keyword !== '' || $prodi !== '' || $filter !== 'semua')
                        <a href="{{ route('admin.teaching.index') }}" class="flex h-[38px] w-[38px] shrink-0 items-center justify-center rounded-control border border-border text-muted hover:text-ink" title="Reset Filter">
                            <i data-lucide="rotate-ccw" class="size-4"></i>
                        </a>
                    @endif
                </div>
            </form>
        </x-card>

        {{-- TABEL GRID DATA-HEAVY (SPREADSHEET-LIKE) --}}
        <div class="overflow-hidden rounded-card border border-border bg-surface shadow-sm">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-border bg-page text-xs font-medium text-muted">
                        <tr>
                            <th class="w-10 px-4 py-3 text-center">
                                <input type="checkbox"
                                       @change="toggleSelectAll($event.target.checked)"
                                       :checked="isAllSelected()"
                                       class="size-4 rounded border-border text-primary focus:ring-primary cursor-pointer"
                                       title="Pilih semua di halaman ini">
                            </th>
                            <th class="px-4 py-3 font-semibold">Mata Kuliah & Kode</th>
                            <th class="px-4 py-3 font-semibold">Kelas / Prodi</th>
                            <th class="px-3 py-3 text-center font-semibold">SKS</th>
                            <th class="px-4 py-3 font-semibold">Dosen Pengampu (Lead)</th>
                            <th class="px-4 py-3 font-semibold">Dosen Mitra (Partner)</th>
                            <th class="px-3 py-3 text-right font-semibold">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @php
                            $pageOfferingIds = $offerings->pluck('id')->all();
                        @endphp
                        @forelse ($offerings as $offering)
                            @php
                                $leadAssignment = $offering->teachingAssignments->firstWhere('role.value', 'lead');
                                $partnerAssignments = $offering->teachingAssignments->where('role.value', 'partner');
                            @endphp
                            <tr class="transition hover:bg-primary-soft/20" :class="{ 'bg-primary-soft/30': selectedIds.includes({{ $offering->id }}) }">
                                {{-- Checkbox --}}
                                <td class="px-4 py-3 text-center">
                                    <input type="checkbox"
                                           value="{{ $offering->id }}"
                                           x-model="selectedIds"
                                           class="size-4 rounded border-border text-primary focus:ring-primary cursor-pointer">
                                </td>

                                {{-- Mata Kuliah --}}
                                <td class="px-4 py-3">
                                    <div class="flex flex-col">
                                        <span class="font-semibold text-ink">{{ $offering->course->name }}</span>
                                        <span class="text-xs text-muted">{{ $offering->course->code ?? '—' }} • Kurikulum {{ $offering->course->curriculum ?? '—' }}</span>
                                    </div>
                                </td>

                                {{-- Kelas / Prodi --}}
                                <td class="px-4 py-3">
                                    <div class="flex flex-col">
                                        <span class="font-medium text-ink">{{ $offering->classGroup->name }}</span>
                                        <span class="text-xs text-muted">{{ $offering->classGroup->study_program ?? 'JTIK' }} ({{ $offering->classGroup->student_count ?? 0 }} mhs)</span>
                                    </div>
                                </td>

                                {{-- SKS --}}
                                <td class="px-3 py-3 text-center">
                                    <span class="inline-flex items-center rounded-md bg-page px-2 py-0.5 text-xs font-semibold text-ink ring-1 ring-border">
                                        {{ $offering->course->sks }} SKS
                                    </span>
                                </td>

                                {{-- Dosen Pengampu (Lead) --}}
                                <td class="px-4 py-3">
                                    @if ($leadAssignment)
                                        <div class="inline-flex items-center gap-2 rounded-lg border border-border bg-page px-2.5 py-1 text-xs">
                                            <span class="font-semibold text-ink">{{ $leadAssignment->lecturer->name }}</span>
                                            <span class="text-muted text-[10px]">({{ $leadAssignment->phase->label() }})</span>

                                            {{-- Lock Toggle --}}
                                            <form method="POST" action="{{ route('admin.teaching.lock', $leadAssignment) }}" class="inline">
                                                @csrf
                                                <button type="submit" class="text-primary hover:text-primary/70 ml-1" title="{{ $leadAssignment->is_locked ? 'Terkunci (klik untuk buka)' : 'Buka kunci (klik untuk mengunci)' }}">
                                                    <i data-lucide="{{ $leadAssignment->is_locked ? 'lock' : 'lock-open' }}" class="size-3.5"></i>
                                                </button>
                                            </form>

                                            {{-- Delete Button --}}
                                            @unless ($leadAssignment->is_locked)
                                                <form method="POST" action="{{ route('admin.teaching.destroy', $leadAssignment) }}" class="inline" onsubmit="return confirm('Hapus pengampu dari penawaran ini?')">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="text-danger hover:text-danger/70" title="Hapus pengampu">
                                                        <i data-lucide="x" class="size-3.5"></i>
                                                    </button>
                                                </form>
                                            @endunless
                                        </div>
                                    @else
                                        <button type="button"
                                                @click="openQuickAssign({{ $offering->id }}, '{{ addslashes($offering->course->name) }}', '{{ addslashes($offering->classGroup->name) }}', 'lead')"
                                                class="inline-flex items-center gap-1.5 rounded-lg border border-dashed border-danger/60 bg-danger-soft/30 px-2.5 py-1 text-xs font-semibold text-danger hover:bg-danger-soft transition">
                                            <i data-lucide="user-plus" class="size-3.5"></i>
                                            Tugaskan Pengampu
                                        </button>
                                    @endif
                                </td>

                                {{-- Dosen Mitra (Partner) --}}
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        @foreach ($partnerAssignments as $partnerAssignment)
                                            <div class="inline-flex items-center gap-1.5 rounded-lg border border-border bg-page px-2 py-0.5 text-xs">
                                                <span class="text-ink">{{ $partnerAssignment->lecturer->name }}</span>
                                                <span class="text-muted text-[10px]">({{ $partnerAssignment->phase->label() }})</span>

                                                <form method="POST" action="{{ route('admin.teaching.lock', $partnerAssignment) }}" class="inline">
                                                    @csrf
                                                    <button type="submit" class="text-primary hover:text-primary/70" title="Kunci/Buka">
                                                        <i data-lucide="{{ $partnerAssignment->is_locked ? 'lock' : 'lock-open' }}" class="size-3"></i>
                                                    </button>
                                                </form>

                                                @unless ($partnerAssignment->is_locked)
                                                    <form method="POST" action="{{ route('admin.teaching.destroy', $partnerAssignment) }}" class="inline" onsubmit="return confirm('Hapus mitra?')">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit" class="text-danger hover:text-danger/70" title="Hapus mitra">
                                                            <i data-lucide="x" class="size-3"></i>
                                                        </button>
                                                    </form>
                                                @endunless
                                            </div>
                                        @endforeach

                                        <button type="button"
                                                @click="openQuickAssign({{ $offering->id }}, '{{ addslashes($offering->course->name) }}', '{{ addslashes($offering->classGroup->name) }}', 'partner')"
                                                class="inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-xs text-muted hover:text-primary hover:bg-page transition"
                                                title="Tambah dosen mitra">
                                            <i data-lucide="plus" class="size-3"></i>
                                            <span>Mitra</span>
                                        </button>
                                    </div>
                                </td>

                                {{-- Aksi Cepat --}}
                                <td class="px-3 py-3 text-right">
                                    <button type="button"
                                            @click="openQuickAssign({{ $offering->id }}, '{{ addslashes($offering->course->name) }}', '{{ addslashes($offering->classGroup->name) }}', 'lead')"
                                            class="text-muted hover:text-primary p-1 rounded hover:bg-page"
                                            title="Atur tim pengajar">
                                        <i data-lucide="edit-3" class="size-4"></i>
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="py-12 text-center text-muted">
                                    <i data-lucide="search-x" class="mx-auto size-8 text-muted/60 mb-2"></i>
                                    <p class="font-medium text-ink">Tidak ditemukan penawaran mata kuliah yang sesuai filter.</p>
                                    <p class="text-xs text-muted mt-1">Coba sesuaikan kata kunci atau bersihkan filter pencarian.</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Pagination Footer --}}
            @if ($offerings->hasPages())
                <div class="border-t border-border bg-page/50 px-4 py-3">
                    {{ $offerings->links() }}
                </div>
            @endif
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
             class="fixed bottom-6 left-1/2 z-40 flex -translate-x-1/2 items-center gap-4 rounded-xl border border-primary/30 bg-ink px-6 py-3.5 text-surface shadow-2xl">
            <div class="flex items-center gap-2">
                <i data-lucide="check-circle" class="size-5 text-success"></i>
                <span class="text-sm font-semibold"><span x-text="selectedIds.length"></span> kelas dipilih</span>
            </div>
            <div class="h-4 w-px bg-surface/20"></div>
            <button type="button"
                    @click="batchModalOpen = true"
                    class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-xs font-semibold text-white shadow-sm hover:bg-primary/90 transition">
                <i data-lucide="zap" class="size-4"></i>
                Tugaskan Dosen Sekaligus
            </button>
            <button type="button"
                    @click="selectedIds = []"
                    class="text-xs text-surface/70 hover:text-white transition">
                Batalkan Pilihan
            </button>
        </div>

        {{-- MODAL 1: QUICK ASSIGN TUNGGAL --}}
        <div x-show="quickModalOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-ink/80 backdrop-blur-sm" @click="quickModalOpen = false"></div>
            <div class="relative w-full max-w-lg rounded-2xl bg-surface p-6 shadow-2xl">
                <div class="flex items-start justify-between border-b border-border pb-3">
                    <div>
                        <span class="text-xs font-semibold text-primary">Penugasan Dosen</span>
                        <h3 class="text-lg font-bold text-ink" x-text="activeOffering.course"></h3>
                        <p class="text-xs text-muted">Kelas: <span class="font-medium text-ink" x-text="activeOffering.classGroup"></span></p>
                    </div>
                    <button type="button" @click="quickModalOpen = false" class="text-muted hover:text-ink">
                        <i data-lucide="x" class="size-5"></i>
                    </button>
                </div>

                <form method="POST" action="{{ route('admin.teaching.store') }}" class="mt-4 flex flex-col gap-4">
                    @csrf
                    <input type="hidden" name="course_offering_id" :value="activeOffering.id">

                    <div>
                        <label class="mb-1 block text-xs font-medium text-muted">Pilih Dosen Pengajar <span class="text-danger">*</span></label>
                        <select name="lecturer_id" required class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                            <option value="">-- Pilih Dosen Terverifikasi --</option>
                            @foreach ($lecturerOptionsEnriched as $lec)
                                <option value="{{ $lec['id'] }}">
                                    {{ $lec['name'] }} — [{{ $lec['sks_info'] }} • {{ $lec['status'] }}]
                                </option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-[11px] text-muted">Informasi beban SKS dosen diperbarui secara real-time.</p>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-muted">Peran Dosen</label>
                            <select name="role" x-model="activeOffering.role" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                                <option value="lead">Pengampu (Utama)</option>
                                <option value="partner">Mitra (Pendamping)</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-muted">Periode Mengajar</label>
                            <select name="phase" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                                <option value="full">Satu semester penuh</option>
                                <option value="before_uts">Sebelum UTS</option>
                                <option value="after_uts">Setelah UTS</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="mb-1 block text-xs font-medium text-muted">SKS Diakui (Opsional)</label>
                        <input type="number" step="0.5" name="credited_sks" placeholder="Kosongkan jika dihitung otomatis dari SKS MK"
                               class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                    </div>

                    <div class="mt-2 flex items-center justify-end gap-2 border-t border-border pt-4">
                        <x-button variant="secondary" type="button" @click="quickModalOpen = false">Batal</x-button>
                        <x-button variant="primary" type="submit" icon="check">Simpan Penugasan</x-button>
                    </div>
                </form>
            </div>
        </div>

        {{-- MODAL 2: BATCH ASSIGN MASSAL --}}
        <div x-show="batchModalOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-ink/80 backdrop-blur-sm" @click="batchModalOpen = false"></div>
            <div class="relative w-full max-w-lg rounded-2xl bg-surface p-6 shadow-2xl">
                <div class="flex items-start justify-between border-b border-border pb-3">
                    <div>
                        <span class="text-xs font-semibold text-primary">Penugasan Massal (Batch)</span>
                        <h3 class="text-lg font-bold text-ink">Tugaskan Dosen ke <span class="text-primary" x-text="selectedIds.length"></span> Kelas</h3>
                        <p class="text-xs text-muted">Dosen yang dipilih akan langsung ditugaskan ke seluruh kelas yang Anda centang.</p>
                    </div>
                    <button type="button" @click="batchModalOpen = false" class="text-muted hover:text-ink">
                        <i data-lucide="x" class="size-5"></i>
                    </button>
                </div>

                <form method="POST" action="{{ route('admin.teaching.batch') }}" class="mt-4 flex flex-col gap-4">
                    @csrf
                    <template x-for="id in selectedIds" :key="id">
                        <input type="hidden" name="offering_ids[]" :value="id">
                    </template>

                    <div>
                        <label class="mb-1 block text-xs font-medium text-muted">Pilih Dosen <span class="text-danger">*</span></label>
                        <select name="lecturer_id" required class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                            <option value="">-- Pilih Dosen Terverifikasi --</option>
                            @foreach ($lecturerOptionsEnriched as $lec)
                                <option value="{{ $lec['id'] }}">
                                    {{ $lec['name'] }} — [{{ $lec['sks_info'] }} • {{ $lec['status'] }}]
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-muted">Peran Dosen</label>
                            <select name="role" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                                <option value="lead">Pengampu (Utama)</option>
                                <option value="partner">Mitra (Pendamping)</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-muted">Periode Mengajar</label>
                            <select name="phase" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                                <option value="full">Satu semester penuh</option>
                                <option value="before_uts">Sebelum UTS</option>
                                <option value="after_uts">Setelah UTS</option>
                            </select>
                        </div>
                    </div>

                    <div class="rounded-lg bg-page p-3 text-xs text-muted">
                        <i data-lucide="info" class="size-4 inline text-primary mr-1"></i>
                        Penugasan ini tidak akan menimpa penugasan yang sudah dikunci.
                    </div>

                    <div class="mt-2 flex items-center justify-end gap-2 border-t border-border pt-4">
                        <x-button variant="secondary" type="button" @click="batchModalOpen = false">Batal</x-button>
                        <x-button variant="primary" type="submit" icon="zap">Terapkan ke Semua Kelas Terpilih</x-button>
                    </div>
                </form>
            </div>
        </div>

        {{-- MODAL 3: REKAP LENGKAP BEBAN DOSEN --}}
        <div x-show="rekapModalOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-ink/80 backdrop-blur-sm" @click="rekapModalOpen = false"></div>
            <div class="relative flex max-h-[85vh] w-full max-w-4xl flex-col rounded-2xl bg-surface p-6 shadow-2xl">
                <div class="flex items-start justify-between border-b border-border pb-3">
                    <div>
                        <span class="text-xs font-semibold text-primary">Rekapitulasi Jam Mengajar</span>
                        <h3 class="text-xl font-bold text-ink">Beban SKS Dosen vs Target</h3>
                        <p class="text-xs text-muted">Data beban dibandingkan target per dosen untuk semester aktif.</p>
                    </div>
                    <button type="button" @click="rekapModalOpen = false" class="text-muted hover:text-ink">
                        <i data-lucide="x" class="size-5"></i>
                    </button>
                </div>

                <div class="my-3">
                    <input type="text"
                           x-model="lecturerSearch"
                           placeholder="Filter nama dosen pada tabel rekap..."
                           class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                </div>

                <div class="overflow-y-auto border border-border rounded-lg">
                    <table class="w-full text-left text-sm">
                        <thead class="sticky top-0 border-b border-border bg-page text-xs text-muted">
                            <tr>
                                <th class="py-2.5 px-4 font-semibold">Dosen</th>
                                <th class="py-2.5 px-3 text-center font-semibold">Target</th>
                                <th class="py-2.5 px-3 text-center font-semibold">Maks.</th>
                                <th class="py-2.5 px-3 text-center font-semibold">Terbagi</th>
                                <th class="py-2.5 px-3 text-center font-semibold">Sisa</th>
                                <th class="py-2.5 px-4 font-semibold">Status Beban</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @forelse ($loads as $load)
                                <tr x-show="!lecturerSearch || '{{ strtolower(addslashes($load['lecturer']->name)) }}'.includes(lecturerSearch.toLowerCase())">
                                    <td class="py-2.5 px-4 font-medium text-ink">{{ $load['lecturer']->name }}</td>
                                    <td class="py-2.5 px-3 text-center">{{ $load['target'] ?? '—' }}</td>
                                    <td class="py-2.5 px-3 text-center">{{ $load['max'] ?? '—' }}</td>
                                    <td class="py-2.5 px-3 text-center font-semibold text-primary">
                                        {{ $load['assigned'] }}
                                        @if ($load['pending'] > 0)
                                            <span class="block text-[10px] text-muted">+{{ $load['pending'] }} pending</span>
                                        @endif
                                    </td>
                                    <td class="py-2.5 px-3 text-center">{{ $load['remaining'] ?? '—' }}</td>
                                    <td class="py-2.5 px-4">
                                        <x-status-badge :variant="$load['tone']">{{ $load['status'] }}</x-status-badge>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="py-8 text-center text-muted">Belum ada pembagian mengajar.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="mt-4 flex items-center justify-between border-t border-border pt-3 text-xs text-muted">
                    <span>Dua pengajar tidak otomatis masing-masing menerima SKS penuh.</span>
                    <x-button variant="secondary" type="button" @click="rekapModalOpen = false">Tutup</x-button>
                </div>
            </div>
        </div>
    </div>

    <script>
        function teachingManager() {
            const pageOfferingIds = @js($offerings->pluck('id')->all());

            return {
                selectedIds: [],
                quickModalOpen: false,
                batchModalOpen: false,
                rekapModalOpen: false,
                lecturerSearch: '',
                activeOffering: {
                    id: null,
                    course: '',
                    classGroup: '',
                    role: 'lead'
                },

                isAllSelected() {
                    return pageOfferingIds.length > 0 && pageOfferingIds.every(id => this.selectedIds.includes(id));
                },

                toggleSelectAll(checked) {
                    if (checked) {
                        pageOfferingIds.forEach(id => {
                            if (!this.selectedIds.includes(id)) {
                                this.selectedIds.push(id);
                            }
                        });
                    } else {
                        this.selectedIds = this.selectedIds.filter(id => !pageOfferingIds.includes(id));
                    }
                },

                openQuickAssign(id, courseName, className, role) {
                    this.activeOffering = {
                        id: id,
                        course: courseName,
                        classGroup: className,
                        role: role || 'lead'
                    };
                    this.quickModalOpen = true;
                    this.$nextTick(() => {
                        if (window.renderIcons) window.renderIcons();
                    });
                }
            };
        }
    </script>
@endsection
