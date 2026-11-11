@use('App\Enums\SessionPhase')
@use('App\Enums\TeachingRole')
@extends('layouts.admin')

@section('title', 'Pembagian SKS')
@section('breadcrumb', 'Pembagian Mengajar')

@section('breadcrumb-actions')
    <x-button variant="secondary" :href="route('admin.dashboard')">Dashboard</x-button>
@endsection

@section('content')
    @php
        $allLecturersList = ($allLecturerOptions ?? $lecturerOptionsEnriched)->values()->all();
        $singleCount = $singleTeacherCount ?? 0;
        $teamCount = $teamTeacherCount ?? 0;
    @endphp
    <div class="flex flex-col gap-6"
         x-data="teachingManager(@js($offerings->pluck('id')->all()), @js($lecturerOptionsByOffering), @js($allLecturersList))"
         :data-editing="(quickModalOpen || batchModalOpen || rekapModalOpen || selectedIds.length > 0) ? 'true' : null"
         @sicma:navigated.document="quickModalOpen = false; batchModalOpen = false; rekapModalOpen = false">
        <x-page-header title="Pembagian mengajar dan beban SKS" subtitle="Bagi mata kuliah ke dosen yang layak. Klik langsung pada nama dosen atau tombol Atur Tim untuk mengganti pengajar, peran, atau periode pertemuan.">
            @if ($period)
                <x-slot:actions>
                    <x-button variant="secondary" icon="chart-column-increasing" @click="rekapModalOpen = true">Rekap Beban SKS Dosen</x-button>
                    <x-button variant="secondary" icon="calendar" :href="route('admin.teaching.phases.preview')">Atur Fase Pertemuan</x-button>
                    <x-button icon="zap" :href="route('admin.teaching.allocation.preview')">Bagi Beban Otomatis</x-button>
                </x-slot:actions>
            @endif
        </x-page-header>

        @if (session('allocation_draft'))
            <x-alert variant="info">
                Draf jadwal baru sudah memuat tim pengajar hasil pembagian.
                <a class="font-semibold underline" href="{{ route('admin.drafts.show', session('allocation_draft')) }}">Tinjau draf dan periksa penempatannya</a>.
            </x-alert>
        @endif

        <x-card>
            <x-schedule-steps :current="2" />
        </x-card>

        <x-tabs :items="[
            ['label' => 'Penugasan Dosen & SKS', 'href' => route('admin.teaching.index'), 'active' => true],
            ['label' => 'Bagi Beban Otomatis', 'href' => route('admin.teaching.allocation.preview'), 'active' => false],
            ['label' => 'Atur Fase Pertemuan (UTS/UAS)', 'href' => route('admin.teaching.phases.preview'), 'active' => false],
        ]" />



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
                    <a href="{{ route('admin.teaching.index', array_merge(request()->query(), ['filter' => 'tunggal', 'page' => 1])) }}"
                       @class([
                           'rounded-full px-3.5 py-1.5 text-xs font-semibold transition',
                           'bg-primary text-white' => $filter === 'tunggal',
                           'bg-page text-muted hover:text-ink hover:bg-border' => $filter !== 'tunggal',
                       ])>
                        Dosen Tunggal / Tanpa Mitra ({{ number_format($singleCount) }})
                    </a>
                    <a href="{{ route('admin.teaching.index', array_merge(request()->query(), ['filter' => 'tim_mitra', 'page' => 1])) }}"
                       @class([
                           'rounded-full px-3.5 py-1.5 text-xs font-semibold transition',
                           'bg-success text-white' => $filter === 'tim_mitra',
                           'bg-success-soft/60 text-success hover:bg-success-soft' => $filter !== 'tim_mitra',
                       ])>
                        Tim 2 Dosen ({{ number_format($teamCount) }})
                    </a>
                    <a href="{{ route('admin.teaching.index', array_merge(request()->query(), ['filter' => 'lengkap', 'page' => 1])) }}"
                       @class([
                           'rounded-full px-3.5 py-1.5 text-xs font-semibold transition',
                           'bg-primary text-white' => $filter === 'lengkap',
                           'bg-page text-muted hover:text-ink hover:bg-border' => $filter !== 'lengkap',
                       ])>
                        ✅ Sudah Ada Pengampu ({{ number_format($withLead) }})
                    </a>
                </div>
            </div>

            {{-- Form Pencarian & Filter --}}
            <form method="GET" action="{{ route('admin.teaching.index') }}" class="grid grid-cols-1 items-end gap-3 sm:grid-cols-2 lg:grid-cols-12">
                <input type="hidden" name="filter" value="{{ $filter }}">

                <div class="sm:col-span-2 lg:col-span-3">
                    <label class="mb-1 block text-xs font-medium text-muted">Cari Mata Kuliah / Kelas / Dosen</label>
                    <div class="relative">
                        <i data-lucide="search" class="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted"></i>
                        <input type="text" name="q" value="{{ $keyword }}" placeholder="Ketik nama MK, kode, kelas, dosen..."
                               class="w-full rounded-control border border-border bg-surface pl-9 pr-3 py-2 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                    </div>
                </div>

                <div class="sm:col-span-1 lg:col-span-3">
                    <label class="mb-1 block text-xs font-medium text-muted">Program Studi</label>
                    <select name="prodi" @change="$el.form.requestSubmit()" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                        <option value="">Semua Program Studi</option>
                        @foreach ($studyPrograms as $sp)
                            <option value="{{ $sp }}" @selected($prodi === $sp)>{{ $sp }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="sm:col-span-1 lg:col-span-2">
                    <label class="mb-1 block text-xs font-medium text-muted">Semester</label>
                    <select name="semester" @change="$el.form.requestSubmit()" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                        <option value="">Semua Semester</option>
                        @foreach ($semesterOptions as $sOpt)
                            <option value="{{ $sOpt['value'] }}" @selected($semester === (string) $sOpt['value'])>{{ $sOpt['label'] }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="sm:col-span-1 lg:col-span-2">
                    <label class="mb-1 block text-xs font-medium text-muted">Per Halaman</label>
                    <select name="per_page" @change="$el.form.requestSubmit()" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                        @foreach ([25, 50, 100] as $n)
                            <option value="{{ $n }}" @selected($perPage == $n)>{{ $n }} baris</option>
                        @endforeach
                    </select>
                </div>

                <div class="flex gap-2 sm:col-span-1 lg:col-span-2">
                    <button type="submit" class="inline-flex h-[38px] flex-1 items-center justify-center gap-1.5 rounded-control bg-primary px-3 text-xs font-semibold text-white hover:bg-primary/90 transition" title="Terapkan Filter">
                        <i data-lucide="search" class="size-3.5"></i>
                        <span>Cari</span>
                    </button>
                    @if ($keyword !== '' || $prodi !== '' || $semester !== '' || $filter !== 'semua')
                        <a href="{{ route('admin.teaching.index') }}" class="inline-flex h-[38px] items-center justify-center gap-1 rounded-control border border-border bg-surface px-2.5 text-xs font-medium text-muted hover:text-ink hover:bg-page transition" title="Reset Filter">
                            <i data-lucide="rotate-ccw" class="size-3.5"></i>
                            <span>Reset</span>
                        </a>
                    @endif
                </div>
            </form>
        </x-card>

        {{-- TABEL GRID DATA-HEAVY (SPREADSHEET-LIKE) --}}
        <div class="overflow-hidden rounded-card border border-border bg-surface shadow-xs">
            <div class="overflow-x-auto">
                <table class="w-full border-collapse text-left text-sm">
                    <thead class="border-b border-border bg-page text-xs font-semibold text-muted">
                        <tr>
                            <th class="w-10 px-3 py-3 text-center">
                                <input type="checkbox"
                                       @change="toggleSelectAll($event.target.checked)"
                                       :checked="isAllSelected()"
                                       class="size-4 rounded border-border text-primary focus:ring-primary cursor-pointer"
                                       title="Pilih semua di halaman ini">
                            </th>
                            <th class="w-36 px-4 py-3">Kelas</th>
                            <th class="px-4 py-3">Mata Kuliah & Kode</th>
                            <th class="w-20 px-3 py-3 text-center">SKS</th>
                            <th class="px-4 py-3">Dosen Pengampu (SKS)</th>
                            <th class="px-4 py-3">Dosen Mitra (SKS)</th>
                            <th class="w-24 px-3 py-3 text-center">Skema</th>
                            <th class="w-28 px-4 py-3 text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($offerings as $offering)
                            @php
                                $leadAssignment = $offering->teachingAssignments->firstWhere('role.value', 'lead');
                                $partnerAssignments = $offering->teachingAssignments->where('role.value', 'partner')->values();
                                $teamPayload = $offering->teachingAssignments->map(fn ($a) => [
                                    'id' => $a->id,
                                    'lecturer_id' => $a->lecturer_id,
                                    'lecturer_name' => $a->lecturer->name,
                                    'role' => $a->role->value,
                                    'role_label' => $a->role === TeachingRole::Lead ? 'Pengampu' : 'Mitra',
                                    'phase' => $a->phase->value,
                                    'phase_label' => $a->phaseLabel(),
                                    'is_locked' => (bool) $a->is_locked,
                                ])->values()->all();
                                $offeringPayload = [
                                    'id' => $offering->id,
                                    'course' => $offering->course->name,
                                    'classGroup' => $offering->classGroup->name,
                                    'sks' => $offering->course->sks,
                                    'team' => $teamPayload,
                                ];
                                $leadPayload = $leadAssignment ? [
                                    'id' => $leadAssignment->id,
                                    'lecturer_id' => $leadAssignment->lecturer_id,
                                    'role' => $leadAssignment->role->value,
                                    'phase' => $leadAssignment->phase->value,
                                ] : null;
                                $leadSks = $offering->course->sks;
                                $isSelectedFromQuery = request()->integer('penawaran') === $offering->id;
                            @endphp
                            <tr @class(['border-b border-border transition hover:bg-primary-soft/20', 'bg-primary-soft/20 ring-1 ring-inset ring-primary/30' => $isSelectedFromQuery])
                                :class="{ 'bg-primary-soft/30': selectedIds.map(Number).includes({{ $offering->id }}) }">
                                {{-- Checkbox --}}
                                <td class="w-10 px-3 py-3 text-center">
                                    <input type="checkbox"
                                           :value="{{ $offering->id }}"
                                           x-model.number="selectedIds"
                                           class="size-4 rounded border-border text-primary focus:ring-primary cursor-pointer">
                                </td>

                                {{-- Kelas (Kompak) --}}
                                @php
                                    $prodiAbbr = match ($offering->classGroup->study_program) {
                                        'Pendidikan Teknik Informatika dan Komputer' => 'PTIK',
                                        'Teknik Komputer' => 'TEKOM',
                                        default => $offering->classGroup->study_program ?? 'JTIK',
                                    };
                                    $hasCohortInName = $offering->classGroup->cohort_year && str_contains($offering->classGroup->name, (string) $offering->classGroup->cohort_year);
                                    $cohortSubtitle = ! $hasCohortInName && $offering->classGroup->cohort_year ? ' \''.substr((string) $offering->classGroup->cohort_year, -2) : '';
                                @endphp
                                <td class="w-36 px-4 py-3 whitespace-nowrap">
                                    <div class="flex flex-col" title="{{ $offering->classGroup->study_program ?? 'JTIK' }}">
                                        <span class="font-bold text-ink text-sm">{{ $offering->classGroup->name }}</span>
                                        <span class="text-xs text-muted">{{ $prodiAbbr }}{{ $cohortSubtitle }} • {{ $offering->classGroup->student_count ? $offering->classGroup->student_count.' mhs' : '—' }}</span>
                                    </div>
                                </td>

                                {{-- Mata Kuliah & Kode --}}
                                <td class="px-4 py-3">
                                    <div class="flex flex-col">
                                        <div class="flex items-center gap-2">
                                            <span class="font-bold text-ink text-sm">{{ $offering->course->name }}</span>
                                            @if ($isSelectedFromQuery)
                                                <span class="rounded bg-primary px-1.5 py-0.5 text-[10px] font-semibold text-white">Dipilih</span>
                                            @endif
                                        </div>
                                        <span class="font-mono text-xs text-muted">{{ $offering->course->code ?? '—' }}</span>
                                    </div>
                                </td>

                                {{-- SKS --}}
                                <td class="w-20 px-3 py-3 text-center whitespace-nowrap">
                                    <span class="inline-flex items-center justify-center rounded-full bg-primary-soft px-2.5 py-0.5 text-xs font-bold text-primary">
                                        {{ $offering->course->sks }} SKS
                                    </span>
                                </td>

                                {{-- Dosen Pengampu (SKS) --}}
                                <td class="px-4 py-3">
                                    @if ($leadAssignment)
                                        <div class="flex items-center gap-1.5 flex-wrap">
                                            @if ($leadAssignment->is_locked)
                                                <span class="font-semibold text-ink text-sm">{{ $leadAssignment->lecturer->name }}</span>
                                                <span title="Penugasan dikunci" class="text-primary inline-flex items-center">
                                                    <i data-lucide="lock" class="size-3.5"></i>
                                                </span>
                                            @else
                                                <button type="button"
                                                        @click="openEditAssignment(@js($offeringPayload), @js($leadPayload))"
                                                        class="font-semibold text-ink text-sm hover:text-primary hover:underline text-left cursor-pointer"
                                                        title="Klik untuk mengubah dosen pengampu atau fase pertemuan">
                                                    {{ $leadAssignment->lecturer->name }}
                                                </button>
                                            @endif
                                            <span class="inline-flex items-center rounded bg-page px-1.5 py-0.5 text-[11px] font-medium text-muted border border-border whitespace-nowrap">
                                                {{ $leadSks }} SKS{{ $leadAssignment->phase !== SessionPhase::Full ? ' • ' . ($leadAssignment->phase === SessionPhase::BeforeUts ? 'UTS' : 'UAS') : '' }}
                                            </span>
                                        </div>
                                    @else
                                        <span class="inline-flex items-center gap-1 text-xs font-semibold text-danger">
                                            <i data-lucide="circle-alert" class="size-3.5 shrink-0"></i>
                                            <span>Belum Ada</span>
                                        </span>
                                    @endif
                                </td>

                                {{-- Dosen Mitra (SKS) --}}
                                <td class="px-4 py-3">
                                    @if ($partnerAssignments->isNotEmpty())
                                        <div class="flex flex-col gap-1.5">
                                            @foreach ($partnerAssignments as $partnerAssignment)
                                                @php
                                                    $partnerPayload = [
                                                        'id' => $partnerAssignment->id,
                                                        'lecturer_id' => $partnerAssignment->lecturer_id,
                                                        'role' => $partnerAssignment->role->value,
                                                        'phase' => $partnerAssignment->phase->value,
                                                    ];
                                                    $partnerSks = $offering->course->sks;
                                                @endphp
                                                <div class="flex items-center gap-1.5 flex-wrap">
                                                    @if ($partnerAssignment->is_locked)
                                                        <span class="font-medium text-ink text-xs">{{ $partnerAssignment->lecturer->name }}</span>
                                                        <span title="Penugasan dikunci" class="text-primary inline-flex items-center">
                                                            <i data-lucide="lock" class="size-3"></i>
                                                        </span>
                                                    @else
                                                        <button type="button"
                                                                @click="openEditAssignment(@js($offeringPayload), @js($partnerPayload))"
                                                                class="font-medium text-ink text-xs hover:text-primary hover:underline text-left cursor-pointer"
                                                                title="Klik untuk mengubah dosen mitra atau fase pertemuan">
                                                            {{ $partnerAssignment->lecturer->name }}
                                                        </button>
                                                    @endif
                                                    <span class="inline-flex items-center rounded bg-page px-1.5 py-0.5 text-[10px] font-medium text-muted border border-border whitespace-nowrap">
                                                        {{ $partnerSks }} SKS{{ $partnerAssignment->phase !== SessionPhase::Full ? ' • ' . ($partnerAssignment->phase === SessionPhase::BeforeUts ? 'UTS' : 'UAS') : '' }}
                                                    </span>
                                                </div>
                                            @endforeach
                                        </div>
                                    @else
                                        <span class="text-xs text-muted italic">— Dosen Tunggal —</span>
                                    @endif
                                </td>

                                {{-- Skema --}}
                                <td class="w-24 px-3 py-3 text-center whitespace-nowrap">
                                    @if (! $leadAssignment && $partnerAssignments->isEmpty())
                                        <span class="inline-flex items-center rounded-full bg-danger-soft px-2.5 py-0.5 text-[11px] font-semibold text-danger border border-danger/20">
                                            Kosong
                                        </span>
                                    @elseif ($partnerAssignments->isNotEmpty())
                                        <span class="inline-flex items-center rounded-full bg-primary-soft px-2.5 py-0.5 text-[11px] font-semibold text-primary border border-primary/20">
                                            Team ({{ $partnerAssignments->count() + 1 }})
                                        </span>
                                    @else
                                        <span class="inline-flex items-center rounded-full bg-page px-2.5 py-0.5 text-[11px] font-medium text-muted border border-border">
                                            Tunggal
                                        </span>
                                    @endif
                                </td>

                                {{-- Aksi Cepat --}}
                                <td class="w-28 px-4 py-3 text-right whitespace-nowrap">
                                    @if ($leadAssignment)
                                        <button type="button"
                                                @click="openEditAssignment(@js($offeringPayload), @js($leadPayload))"
                                                class="inline-flex items-center gap-1.5 rounded-control border border-border bg-surface px-2.5 py-1 text-xs font-semibold text-primary hover:bg-primary-soft hover:border-primary/40 transition cursor-pointer"
                                                title="Kelola tim pengajar kelas ini">
                                            <i data-lucide="pencil" class="size-3.5"></i>
                                            <span>Atur Tim</span>
                                        </button>
                                    @else
                                        <button type="button"
                                                @click="openQuickAssign({{ $offering->id }}, @js($offering->course->name), @js($offering->classGroup->name), 'lead', {{ $offering->course->sks }}, @js($teamPayload))"
                                                class="inline-flex items-center gap-1.5 rounded-control border border-danger/40 bg-danger-soft/40 px-2.5 py-1 text-xs font-semibold text-danger hover:bg-danger-soft transition cursor-pointer"
                                                title="Tugaskan pengampu kelas ini">
                                            <i data-lucide="user-plus" class="size-3.5"></i>
                                            <span>Tugaskan</span>
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="py-12 text-center text-muted">
                                    <i data-lucide="search-x" class="mx-auto size-8 text-muted/60 mb-2"></i>
                                    <p class="font-medium text-ink">Tidak ditemukan penawaran mata kuliah yang sesuai filter.</p>
                                    <p class="text-xs text-muted mt-1">Coba sesuaikan kata kunci atau bersihkan filter pencarian.</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Pagination & Data Summary Footer --}}
            @if ($offerings->total() > 0)
                <div class="border-t border-border bg-page/50 px-4 py-3">
                    @if ($offerings->hasPages())
                        {{ $offerings->links() }}
                    @else
                        <div class="flex flex-col sm:flex-row items-center justify-between gap-3 text-xs text-muted py-1">
                            <div>
                                Menampilkan <span class="font-semibold text-ink">1</span> sampai <span class="font-semibold text-ink">{{ $offerings->total() }}</span> dari <span class="font-semibold text-ink">{{ number_format($offerings->total()) }}</span> kelas penawaran
                            </div>
                            <span class="inline-flex items-center justify-center min-w-[32px] h-7 px-2.5 rounded-control border border-border bg-surface text-ink text-xs font-semibold">
                                Halaman 1 dari 1
                            </span>
                        </div>
                    @endif
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
             class="fixed bottom-6 left-1/2 z-40 flex -translate-x-1/2 flex-wrap items-center gap-3 rounded-xl border border-primary/30 bg-ink px-6 py-3.5 text-surface shadow-2xl">
            <div class="flex items-center gap-2">
                <i data-lucide="circle-check" class="size-5 text-success"></i>
                <span class="text-sm font-semibold"><span x-text="selectedIds.length"></span> kelas dipilih</span>
                <button type="button"
                        x-show="!isAllSelected()"
                        @click="toggleSelectAll(true)"
                        class="ml-1 rounded-md border border-surface/20 bg-surface/10 px-2 py-0.5 text-[11px] font-semibold text-surface hover:bg-surface/20 transition cursor-pointer">
                    Pilih Semua Tampil
                </button>
            </div>
            <div class="h-4 w-px bg-surface/20"></div>
            <button type="button"
                    @click="batchModalOpen = true; $nextTick(() => window.renderIcons())"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3.5 py-1.5 text-xs font-semibold text-white shadow-xs hover:bg-primary/90 transition cursor-pointer">
                <i data-lucide="zap" class="size-3.5"></i>
                <span>Tugaskan Dosen Sekaligus</span>
            </button>
            <button type="button"
                    @click="selectedIds = []"
                    class="text-xs text-surface/70 hover:text-white transition ml-2 cursor-pointer">
                Batalkan Pilihan
            </button>
        </div>

        {{-- MODAL 1: ATUR TIM / TAMBAH & EDIT PENUGASAN DOSEN --}}
        <div x-show="quickModalOpen" x-cloak data-modal-name="quick-assign-modal" class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-ink/80 backdrop-blur-sm" @click="quickModalOpen = false"></div>
            <div class="relative flex max-h-[90vh] w-full max-w-xl flex-col overflow-y-auto rounded-2xl bg-surface p-6 shadow-2xl">
                <div class="flex items-start justify-between border-b border-border pb-3">
                    <div>
                        <span class="text-xs font-semibold text-primary" x-text="editingAssignmentId ? 'Ubah Penugasan Dosen' : 'Tambah Pengajar Kelas'"></span>
                        <h3 class="text-lg font-bold text-ink" x-text="activeOffering.course"></h3>
                        <p class="text-xs text-muted">
                            Kelas: <span class="font-semibold text-ink" x-text="activeOffering.classGroup"></span>
                            <template x-if="activeOffering.sks">
                                <span> • <span class="font-semibold text-ink" x-text="`${activeOffering.sks} SKS`"></span></span>
                            </template>
                        </p>
                    </div>
                    <button type="button" @click="quickModalOpen = false" class="text-muted hover:text-ink">
                        <i data-lucide="x" class="size-5"></i>
                    </button>
                </div>

                {{-- Daftar Anggota Tim Saat Ini pada Kelas Ini --}}
                <template x-if="activeOffering.team && activeOffering.team.length > 0">
                    <div class="mt-4 rounded-xl border border-border bg-page p-3">
                        <div class="mb-2 flex items-center justify-between">
                            <span class="text-xs font-semibold text-ink">Tim Pengajar Saat Ini</span>
                            <button type="button"
                                    @click="switchToCreate('partner')"
                                    class="inline-flex items-center gap-1 rounded-md bg-primary-soft px-2 py-1 text-[11px] font-semibold text-primary hover:bg-primary hover:text-white transition">
                                <span>+ Tambah Anggota Baru</span>
                            </button>
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <template x-for="member in activeOffering.team" :key="member.id">
                                <div class="flex items-center justify-between gap-2 rounded-lg border px-3 py-2 text-xs transition"
                                     :class="editingAssignmentId === member.id ? 'border-primary bg-primary-soft/40' : 'border-border bg-surface'">
                                    <div class="flex flex-col">
                                        <div class="flex items-center gap-1.5">
                                            <span class="rounded px-1.5 py-0.5 text-[10px] font-bold uppercase"
                                                  :class="member.role === 'lead' ? 'bg-primary text-white' : 'bg-page text-muted border border-border'"
                                                  x-text="member.role_label"></span>
                                            <span class="font-semibold text-ink" x-text="member.lecturer_name"></span>
                                        </div>
                                        <span class="mt-0.5 text-[11px] text-muted">
                                            Periode: <strong class="text-ink" x-text="member.phase_label"></strong>
                                        </span>
                                    </div>
                                    <div class="flex items-center gap-1.5">
                                        {{-- Toggle Lock --}}
                                        <form method="POST" :action="`/admin/pembagian-mengajar/${member.id}/kunci`" class="inline">
                                            @csrf
                                            <button type="submit"
                                                    class="rounded-md border border-border bg-surface px-2 py-1 text-[11px] font-medium transition hover:border-primary hover:text-primary"
                                                    :class="member.is_locked ? 'text-primary' : 'text-muted'"
                                                    :title="member.is_locked ? 'Klik untuk buka kunci' : 'Klik untuk kunci penugasan'">
                                                <span x-text="member.is_locked ? '🔒 Terkunci' : '🔓 Kunci'"></span>
                                            </button>
                                        </form>

                                        {{-- Edit Button --}}
                                        <template x-if="!member.is_locked">
                                            <button type="button"
                                                    @click="openEditAssignment(activeOffering, member)"
                                                    class="inline-flex items-center gap-1 rounded-control px-2.5 py-1 text-xs font-semibold transition cursor-pointer"
                                                    :class="editingAssignmentId === member.id ? 'bg-primary text-white' : 'border border-border bg-surface text-primary hover:border-primary/40 hover:bg-primary-soft'">
                                                <i data-lucide="pencil" class="size-3.5"></i>
                                                <span x-text="editingAssignmentId === member.id ? 'Sedang Diedit' : 'Edit'"></span>
                                            </button>
                                        </template>

                                        {{-- Delete Button --}}
                                        <template x-if="!member.is_locked">
                                            <form method="POST" :action="`/admin/pembagian-mengajar/${member.id}`" class="inline" onsubmit="return confirm('Hapus pengajar ini dari tim?')">
                                                @csrf
                                                <input type="hidden" name="_method" value="DELETE">
                                                <button type="submit"
                                                        class="inline-flex items-center gap-1 rounded-control border border-danger/30 bg-danger-soft/30 px-2.5 py-1 text-xs font-semibold text-danger hover:bg-danger hover:text-white transition cursor-pointer"
                                                        title="Hapus pengajar dari tim">
                                                    <i data-lucide="trash-2" class="size-3.5"></i>
                                                    <span>Hapus</span>
                                                </button>
                                            </form>
                                        </template>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>

                <form method="POST"
                      :action="editingAssignmentId ? `/admin/pembagian-mengajar/${editingAssignmentId}` : '{{ route('admin.teaching.store') }}'"
                      class="mt-4 flex flex-col gap-4">
                    @csrf
                    <template x-if="editingAssignmentId">
                        <input type="hidden" name="_method" value="PUT">
                    </template>
                    <input type="hidden" name="course_offering_id" :value="activeOffering.id">

                    <div>
                        <div class="mb-1.5 flex items-center justify-between">
                            <label class="text-xs font-semibold text-ink">
                                <span x-text="editingAssignmentId ? 'Ganti / Pilih Dosen Pengajar' : 'Pilih Dosen Pengajar Baru'"></span>
                                <span class="text-danger">*</span>
                            </label>
                            <span class="text-[11px] text-muted" x-show="quickLecturers.length > 0" x-text="`${quickLecturers.length} dosen rekomendasi MK ini`"></span>
                        </div>

                        <div class="relative mb-2">
                            <input type="text"
                                   x-model="quickSearch"
                                   placeholder="Ketik nama dosen untuk memfilter daftar pilihan di bawah..."
                                   class="w-full rounded-control border border-border bg-page px-3 py-1.5 text-xs text-ink focus:border-primary focus:bg-surface focus:outline-none">
                        </div>

                        <select name="lecturer_id" x-model="quickLecturerId" required class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                            <option value="">-- Pilih Dosen Pengajar --</option>
                            <template x-if="quickLecturers.length > 0">
                                <optgroup label="★ Dosen Pengampu Mata Kuliah Ini (Rekomendasi)">
                                    <template x-for="lecturer in quickLecturers" :key="'rec-' + lecturer.id">
                                        <option :value="String(lecturer.id)"
                                                :selected="String(lecturer.id) === String(quickLecturerId)"
                                                x-text="`${lecturer.name} — ${lecturer.sks_info}${lecturer.verified ? '' : ' • Perlu verifikasi'}`"></option>
                                    </template>
                                </optgroup>
                            </template>
                            <template x-if="otherLecturers.length > 0">
                                <optgroup label="Semua Dosen Aktif Lainnya">
                                    <template x-for="lecturer in otherLecturers" :key="'all-' + lecturer.id">
                                        <option :value="String(lecturer.id)"
                                                :selected="String(lecturer.id) === String(quickLecturerId)"
                                                x-text="`${lecturer.name} — ${lecturer.sks_info}${lecturer.verified ? '' : ' • Perlu verifikasi'}`"></option>
                                    </template>
                                </optgroup>
                            </template>
                        </select>
                        <p class="mt-1 text-[11px] text-muted">
                            Dosen rekomendasi diurutkan di bagian atas berdasarkan riwayat mengajar & bidang keahlian mata kuliah ini.
                        </p>
                    </div>

                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-muted">Peran Dosen</label>
                            <select name="role" x-model="activeOffering.role" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                                <option value="lead">Pengampu (Utama)</option>
                                <option value="partner">Mitra (Pendamping)</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-muted">Periode Mengajar</label>
                            <select name="phase" x-model="quickPhase" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                                <option value="full">Satu semester penuh (1–14)</option>
                                <option value="before_uts">Pertemuan 1–7 (sampai UTS)</option>
                                <option value="after_uts">Pertemuan 8–14 (sampai UAS)</option>
                            </select>
                        </div>
                    </div>

                    <div class="mt-2 flex items-center justify-end gap-2 border-t border-border pt-4">
                        <x-button variant="secondary" type="button" @click="quickModalOpen = false">Batal</x-button>
                        <x-button variant="primary" type="submit" icon="check">
                            <span x-text="editingAssignmentId ? 'Simpan Perubahan' : 'Simpan Penugasan'"></span>
                        </x-button>
                    </div>
                </form>
            </div>
        </div>

        {{-- MODAL 2: BATCH ASSIGN MASSAL --}}
        <div x-show="batchModalOpen" x-cloak data-modal-name="batch-assign-modal" class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-ink/80 backdrop-blur-sm" @click="batchModalOpen = false"></div>
            <div class="relative w-full max-w-lg rounded-2xl bg-surface p-6 shadow-2xl">
                <div class="flex items-start justify-between border-b border-border pb-3">
                    <div>
                        <span class="text-xs font-semibold text-primary">Penyesuaian Massal</span>
                        <h3 class="text-lg font-bold text-ink">Tugaskan Dosen (<span class="text-primary" x-text="selectedIds.length"></span> Kelas Terpilih)</h3>
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

                    <div class="rounded-lg border border-primary/20 bg-primary-soft/40 p-3 text-xs text-ink">
                        Dosen dan peran yang dipilih di bawah ini akan diterapkan serentak ke <strong x-text="selectedIds.length"></strong> kelas penawaran yang sedang dicentang.
                    </div>

                    <div>
                        <label class="mb-1 block text-xs font-medium text-muted">Pilih Dosen <span class="text-danger">*</span></label>
                        <select name="lecturer_id" required class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                            <option value="">-- Pilih Dosen Terverifikasi --</option>
                            @foreach ($lecturerOptionsEnriched as $lec)
                                <option value="{{ $lec['id'] }}">
                                    {{ $lec['name'] }} — {{ $lec['sks_info'] }}
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
                                <option value="before_uts">Pertemuan 1–7 (sampai UTS)</option>
                                <option value="after_uts">Pertemuan 8–14 (sampai UAS)</option>
                            </select>
                        </div>
                    </div>

                    <div class="rounded-lg bg-page p-3 text-xs text-muted">
                        <i data-lucide="info" class="size-4 inline text-primary mr-1"></i>
                        Jika memilih peran <strong>Pengampu (Utama)</strong>, pengampu lama yang tidak dikunci pada kelas terpilih akan diperbarui. Penugasan yang sudah dikunci tidak akan diubah.
                    </div>

                    <div class="mt-2 flex items-center justify-end gap-2 border-t border-border pt-4">
                        <x-button variant="secondary" type="button" @click="batchModalOpen = false">Batal</x-button>
                        <x-button variant="primary" type="submit" icon="check">Terapkan ke Kelas Terpilih</x-button>
                    </div>
                </form>
            </div>
        </div>

        {{-- MODAL 3: REKAP LENGKAP BEBAN DOSEN --}}
        <div x-show="rekapModalOpen" x-cloak data-modal-name="rekap-load-modal" class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-ink/80 backdrop-blur-sm" @click="rekapModalOpen = false"></div>
            <div class="relative flex max-h-[85vh] w-full max-w-4xl flex-col rounded-2xl bg-surface p-6 shadow-2xl">
                <div class="flex items-start justify-between border-b border-border pb-3">
                    <div>
                        <span class="text-xs font-semibold text-primary">Semester Aktif</span>
                        <h3 class="text-xl font-bold text-ink">Rekap Beban SKS Dosen</h3>
                        <p class="text-xs text-muted">Target dan maksimum memakai SKS Semester, dihitung satu kali per mata kuliah di setiap kelas. SKS × Pertemuan mengikuti jumlah pertemuan setiap pengajar.</p>
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
                                <th class="py-2.5 px-3 text-center font-semibold">SKS × Pertemuan</th>
                                <th class="py-2.5 px-3 text-center font-semibold">Target</th>
                                <th class="py-2.5 px-3 text-center font-semibold">Maks.</th>
                                <th class="py-2.5 px-3 text-center font-semibold">SKS Semester</th>
                                <th class="py-2.5 px-3 text-center font-semibold">Sisa</th>
                                <th class="py-2.5 px-4 font-semibold">Status Beban</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @forelse ($loads as $load)
                                <tr x-show="!lecturerSearch || '{{ strtolower(addslashes($load['lecturer']->name)) }}'.includes(lecturerSearch.toLowerCase())">
                                    <td class="py-2.5 px-4 font-medium text-ink">{{ $load['lecturer']->name }}</td>
                                    <td class="py-2.5 px-3 text-center font-semibold text-primary">
                                        {{ $load['meeting_sks'] }}
                                        @if ($load['pending_phases'] > 0)
                                            <span class="block text-[10px] text-muted">{{ $load['pending_phases'] }} penugasan perlu atur fase</span>
                                        @endif
                                    </td>
                                    <td class="py-2.5 px-3 text-center">{{ $load['target'] ?? '—' }}</td>
                                    <td class="py-2.5 px-3 text-center">{{ $load['max'] ?? '—' }}</td>
                                    <td class="py-2.5 px-3 text-center">
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
                                    <td colspan="7" class="py-8 text-center text-muted">Belum ada pembagian mengajar.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="mt-4 flex items-center justify-between border-t border-border pt-3 text-xs text-muted">
                    <span>SKS Semester menjumlahkan bobot mata kuliah pada setiap kelas yang diajar masing-masing dosen.</span>
                    <x-button variant="secondary" type="button" @click="rekapModalOpen = false">Tutup</x-button>
                </div>
            </div>
        </div>
    </div>
@endsection
