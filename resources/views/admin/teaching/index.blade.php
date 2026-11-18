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
        $lockUrlTemplate = route('admin.teaching.lock', ['assignment' => '__ID__']);
        $destroyUrlTemplate = route('admin.teaching.destroy', ['assignment' => '__ID__']);
        $updateUrlTemplate = route('admin.teaching.update', ['assignment' => '__ID__']);
    @endphp
    <div class="flex flex-col gap-4 admin-page-stack"
         x-data="teachingManager(@js($offerings->pluck('id')->all()), @js($lecturerOptionsByOffering), @js($allLecturersList))"
         x-effect="document.body.classList.toggle('overflow-hidden', quickModalOpen || batchModalOpen || rekapModalOpen)"
         :data-editing="(quickModalOpen || batchModalOpen || rekapModalOpen || selectedIds.length > 0) ? 'true' : null"
         @keydown.escape.window="if (quickModalOpen) { quickModalOpen = false } else if (batchModalOpen) { batchModalOpen = false } else if (rekapModalOpen) { rekapModalOpen = false }"
         @sicma:navigated.document="quickModalOpen = false; batchModalOpen = false; rekapModalOpen = false">
        <x-page-header title="Pembagian mengajar dan beban SKS">
            @if ($period)
                <x-slot:actions>
                    <x-button variant="secondary" size="sm" icon="download" :href="route('admin.teaching.export', request()->only(['filter', 'prodi', 'semester', 'q', 'sort', 'dir']))" download>Unduh CSV</x-button>
                    <x-button variant="secondary" size="sm" icon="chart-column-increasing" @click="rekapModalOpen = true">Rekap Beban SKS Dosen</x-button>
                </x-slot:actions>
            @endif
        </x-page-header>

        @if (session('allocation_draft'))
            <x-alert variant="info">
                Draf jadwal baru sudah memuat tim pengajar hasil pembagian.
                <a class="font-semibold underline" href="{{ route('admin.drafts.show', session('allocation_draft')) }}">Tinjau draf dan periksa penempatannya</a>.
            </x-alert>
        @endif

        <x-card padding="px-2 py-1.5" class="w-fit max-w-full">
            <x-schedule-steps :current="2" />
        </x-card>

        <x-tabs size="sm" :items="[
            ['label' => 'Penugasan Dosen & SKS', 'href' => route('admin.teaching.index'), 'active' => true],
            ['label' => 'Bagi Beban Otomatis', 'href' => route('admin.teaching.allocation.preview'), 'active' => false],
            ['label' => 'Atur Fase Pertemuan (UTS/UAS)', 'href' => route('admin.teaching.phases.preview'), 'active' => false],
        ]" />

        @if (! $period)
            <x-empty-state title="Belum ada semester aktif" message="Atur semester di Data Akademik lebih dulu untuk mengelola pembagian mengajar." icon="calendar" />
        @else

        {{-- Toolbar Filter & Pencarian Cepat --}}
        <x-card padding="p-4" class="admin-table-card flex flex-col gap-3">
            {{-- Tabs Filter Status (1 baris ringkas) --}}
            <div class="flex flex-wrap items-center gap-1.5 border-b border-border pb-3">
                <a href="{{ route('admin.teaching.index', array_merge(request()->query(), ['filter' => 'semua', 'page' => 1])) }}"
                   @class([
                       'inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold transition',
                       'bg-primary text-white' => $filter === 'semua',
                       'bg-page text-muted hover:text-ink hover:bg-border' => $filter !== 'semua',
                   ])>
                    Semua ({{ number_format($totalCount) }})
                </a>
                <a href="{{ route('admin.teaching.index', array_merge(request()->query(), ['filter' => 'tanpa_pengampu', 'page' => 1])) }}"
                   title="Belum Ada Pengampu"
                   @class([
                       'inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold transition',
                       'bg-danger text-white' => $filter === 'tanpa_pengampu',
                       'bg-danger-soft/60 text-danger hover:bg-danger-soft' => $filter !== 'tanpa_pengampu',
                   ])>
                    <i data-lucide="triangle-alert" class="size-3.5 shrink-0" aria-hidden="true"></i>
                    <span>Belum Ada ({{ number_format($withoutLead) }})</span>
                </a>
                <a href="{{ route('admin.teaching.index', array_merge(request()->query(), ['filter' => 'tunggal', 'page' => 1])) }}"
                   title="Dosen Tunggal / Tanpa Mitra"
                   @class([
                       'inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold transition',
                       'bg-primary text-white' => $filter === 'tunggal',
                       'bg-page text-muted hover:text-ink hover:bg-border' => $filter !== 'tunggal',
                   ])>
                    Tunggal ({{ number_format($singleCount) }})
                </a>
                <a href="{{ route('admin.teaching.index', array_merge(request()->query(), ['filter' => 'tim_mitra', 'page' => 1])) }}"
                   title="Tim 2 Dosen"
                   @class([
                       'inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold transition',
                       'bg-success text-white' => $filter === 'tim_mitra',
                       'bg-success-soft/60 text-success hover:bg-success-soft' => $filter !== 'tim_mitra',
                   ])>
                    Tim ({{ number_format($teamCount) }})
                </a>
                <a href="{{ route('admin.teaching.index', array_merge(request()->query(), ['filter' => 'lengkap', 'page' => 1])) }}"
                   title="Sudah Ada Pengampu"
                   @class([
                       'inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold transition',
                       'bg-primary text-white' => $filter === 'lengkap',
                       'bg-page text-muted hover:text-ink hover:bg-border' => $filter !== 'lengkap',
                   ])>
                    <i data-lucide="circle-check" class="size-3.5 shrink-0" aria-hidden="true"></i>
                    <span>Lengkap ({{ number_format($withLead) }})</span>
                </a>
            </div>

            {{-- Form Pencarian & Filter (1 baris kompak) --}}
            <form id="teaching-filter-form" method="GET" action="{{ route('admin.teaching.index') }}" class="flex flex-wrap items-center gap-2">
                <input type="hidden" name="filter" value="{{ $filter }}">
                @if ($sort !== 'class')
                    <input type="hidden" name="sort" value="{{ $sort }}">
                @endif
                @if ($dir !== 'asc')
                    <input type="hidden" name="dir" value="{{ $dir }}">
                @endif
                @if ($offerings->total() === 0)
                    <input type="hidden" name="per_page" value="{{ $perPage }}">
                @endif

                <div class="relative min-w-[200px] flex-1">
                    <i data-lucide="search" class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted" aria-hidden="true"></i>
                    <input id="teaching-q"
                           type="search"
                           name="q"
                           value="{{ $keyword }}"
                           data-table-search
                           aria-label="Cari Mata Kuliah, Kelas, atau Dosen"
                           @input.debounce.400ms="$el.form.requestSubmit()"
                           placeholder="Cari matkul, kode, kelas, atau dosen... (/)"
                           class="w-full rounded-control border border-border bg-surface pl-9 pr-3 py-2 text-sm text-ink placeholder:text-muted focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                </div>

                <select id="teaching-prodi"
                        name="prodi"
                        aria-label="Program Studi"
                        @change="$el.form.requestSubmit()"
                        class="w-full sm:w-52 rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                    <option value="">Semua Program Studi</option>
                    @foreach ($studyPrograms as $val => $label)
                        <option value="{{ $val }}" @selected($prodi === $val)>{{ $label }}</option>
                    @endforeach
                </select>

                <select id="teaching-semester"
                        name="semester"
                        aria-label="Semester"
                        @change="$el.form.requestSubmit()"
                        class="w-full sm:w-44 rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                    <option value="">Semua Semester</option>
                    @foreach ($semesterOptions as $sOpt)
                        <option value="{{ $sOpt['value'] }}" @selected($semester === (string) $sOpt['value'])>{{ $sOpt['label'] }}</option>
                    @endforeach
                </select>

                @if ($keyword !== '' || $prodi !== '' || $semester !== '' || $filter !== 'semua')
                    <x-button variant="secondary" size="sm" icon="rotate-ccw" :href="route('admin.teaching.index')" title="Reset Filter" class="shrink-0">Reset</x-button>
                @endif
            </form>
            {{-- TABEL GRID DATA-HEAVY (SPREADSHEET-LIKE - MENYATU DENGAN FILTER) --}}
            <x-table-scroll>
                <x-slot:title>{{ number_format($offerings->total()) }} penawaran • {{ $period->name }}</x-slot:title>
                <x-slot:headerActions>
                    <x-table-density-toggle />
                </x-slot:headerActions>
                <table class="w-full border-collapse text-left text-sm">
                    <thead class="sticky top-0 z-10 border-b border-border bg-page text-xs font-semibold text-muted">
                        <tr>
                            <th scope="col" class="w-10 px-3 py-3 text-center">
                                <input type="checkbox"
                                       @change="toggleSelectAll($event.target.checked)"
                                       :checked="isAllSelected()"
                                       aria-label="Pilih semua penawaran di halaman ini"
                                       class="size-4 rounded border-border text-primary focus:ring-primary cursor-pointer"
                                       title="Pilih semua di halaman ini (mendukung Shift+Klik)">
                            </th>
                            <x-sortable-th column="class" :sort="$sort" :dir="$dir" class="w-36 px-4 py-3">Kelas</x-sortable-th>
                            <x-sortable-th column="course" :sort="$sort" :dir="$dir" class="px-4 py-3">Mata Kuliah & Kode</x-sortable-th>
                            <x-sortable-th column="sks" :sort="$sort" :dir="$dir" align="center" class="w-20 px-3 py-3">SKS</x-sortable-th>
                            <th scope="col" class="px-4 py-3">Dosen Pengampu (SKS)</th>
                            <th scope="col" class="px-4 py-3">Dosen Mitra (SKS)</th>
                            <th scope="col" class="w-24 px-3 py-3 text-center">Skema</th>
                            <th scope="col" class="w-28 px-4 py-3 text-right">Aksi</th>
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
                                $isAlternateBlock = $classBlockAlternate[$offering->id] ?? false;
                            @endphp
                            <tr @class([
                                'border-b border-border transition',
                                'bg-primary-soft/20 ring-1 ring-inset ring-primary/30' => $isSelectedFromQuery,
                                'bg-primary-soft/45 hover:bg-primary-soft/70' => ! $isSelectedFromQuery && $isAlternateBlock,
                                'bg-surface hover:bg-page/70' => ! $isSelectedFromQuery && ! $isAlternateBlock,
                            ])
                                :class="selectedIds.map(Number).includes({{ $offering->id }}) ? '!bg-primary-soft/75 ring-1 ring-inset ring-primary/35' : ''">
                                {{-- Checkbox --}}
                                <td class="w-10 px-3 py-3 text-center">
                                    <input type="checkbox"
                                           :value="{{ $offering->id }}"
                                           x-model.number="selectedIds"
                                           aria-label="Pilih {{ $offering->course->name }} {{ $offering->classGroup->name }}"
                                           class="size-4 rounded border-border text-primary focus:ring-primary cursor-pointer">
                                </td>

                                {{-- Kelas (Kompak) --}}
                                @php
                                    $prodiAbbr = match ($offering->classGroup->study_program) {
                                        'PTIK', 'Pendidikan Teknik Informatika dan Komputer' => 'PTIK',
                                        'TEKOM', 'Teknik Komputer' => 'TEKOM',
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
                                                    <i data-lucide="lock" class="size-3.5" aria-hidden="true"></i>
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
                                            <i data-lucide="circle-alert" class="size-3.5 shrink-0" aria-hidden="true"></i>
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
                                                            <i data-lucide="lock" class="size-3" aria-hidden="true"></i>
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
                                        <x-button type="button"
                                                  variant="secondary"
                                                  size="sm"
                                                  icon="pencil"
                                                  @click="openEditAssignment({{ \Illuminate\Support\Js::from($offeringPayload) }}, {{ \Illuminate\Support\Js::from($leadPayload) }})"
                                                  title="Kelola tim pengajar kelas ini">Atur Tim</x-button>
                                    @else
                                        <x-button type="button"
                                                  variant="danger-outline"
                                                  size="sm"
                                                  icon="user-plus"
                                                  @click="openQuickAssign({{ $offering->id }}, {{ \Illuminate\Support\Js::from($offering->course->name) }}, {{ \Illuminate\Support\Js::from($offering->classGroup->name) }}, 'lead', {{ $offering->course->sks }}, {{ \Illuminate\Support\Js::from($teamPayload) }})"
                                                  title="Tugaskan pengampu kelas ini">Tugaskan</x-button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="py-12 text-center text-muted">
                                    <i data-lucide="search-x" class="mx-auto size-8 text-muted/60 mb-2" aria-hidden="true"></i>
                                    <p class="font-medium text-ink">Tidak ditemukan penawaran mata kuliah yang sesuai filter.</p>
                                    <p class="text-xs text-muted mt-1">Coba sesuaikan kata kunci atau bersihkan filter pencarian.</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </x-table-scroll>

            {{-- Pagination & Data Summary Footer --}}
            @if ($offerings->total() > 0)
                <div class="flex flex-col gap-3 border-t border-border pt-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="min-w-0 flex-1">
                        @if ($offerings->hasPages())
                            {{ $offerings->links() }}
                        @else
                            <div class="flex flex-wrap items-center gap-3 text-xs text-muted py-1">
                                <span>
                                    Menampilkan <span class="font-semibold text-ink">1</span> sampai <span class="font-semibold text-ink">{{ $offerings->total() }}</span> dari <span class="font-semibold text-ink">{{ number_format($offerings->total()) }}</span> kelas penawaran
                                </span>
                                <span class="inline-flex items-center justify-center min-w-[32px] h-7 px-2.5 rounded-control border border-border bg-surface text-ink text-xs font-semibold">
                                    Halaman 1 dari 1
                                </span>
                            </div>
                        @endif
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <span class="text-xs text-muted">Baris/hal:</span>
                        <select id="teaching-per-page"
                                name="per_page"
                                form="teaching-filter-form"
                                aria-label="Jumlah baris per halaman"
                                @change="$el.form.requestSubmit()"
                                class="rounded-control border border-border bg-surface px-2.5 py-1.5 text-xs text-ink focus:border-primary focus:outline-none">
                            @foreach ([25, 50, 100] as $n)
                                <option value="{{ $n }}" @selected($perPage == $n)>{{ $n }} baris</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            @endif
        </x-card>

        {{-- FLOATING BATCH ACTION BAR --}}
        <div x-show="selectedIds.length > 0"
             x-cloak
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 translate-y-4"
             x-transition:enter-end="opacity-100 translate-y-0"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="opacity-100 translate-y-0"
             x-transition:leave-end="opacity-0 translate-y-4"
             class="fixed bottom-6 left-1/2 z-40 flex w-max max-w-[calc(100vw-2rem)] -translate-x-1/2 flex-wrap items-center gap-3 rounded-xl border border-primary/30 bg-ink px-4 py-3 text-surface shadow-2xl sm:px-6 sm:py-3.5">
            <div class="flex items-center gap-2">
                <i data-lucide="circle-check" class="size-5 text-success shrink-0" aria-hidden="true"></i>
                <span class="text-sm font-semibold"><span x-text="selectedIds.length"></span> kelas dipilih</span>
                <button type="button"
                        x-show="!isAllSelected()"
                        @click="toggleSelectAll(true)"
                        class="ml-1 rounded-md border border-surface/20 bg-surface/10 px-2 py-0.5 text-[11px] font-semibold text-surface hover:bg-surface/20 transition cursor-pointer">
                    Pilih Semua Tampil
                </button>
            </div>
            <div class="hidden h-4 w-px bg-surface/20 sm:block" aria-hidden="true"></div>
            <button type="button"
                    @click="batchModalOpen = true; $nextTick(() => window.renderIcons())"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3.5 py-1.5 text-xs font-semibold text-white shadow-xs hover:bg-primary/90 transition cursor-pointer">
                <i data-lucide="zap" class="size-3.5" aria-hidden="true"></i>
                <span>Tugaskan Dosen Sekaligus</span>
            </button>
            <button type="button"
                    @click="selectedIds = []"
                    class="text-xs text-surface/70 hover:text-white transition sm:ml-2 cursor-pointer">
                Batalkan Pilihan
            </button>
        </div>

        {{-- MODAL 1: ATUR TIM / TAMBAH & EDIT PENUGASAN DOSEN --}}
        <div x-show="quickModalOpen" x-cloak data-modal-name="quick-assign-modal"
             role="dialog" aria-modal="true" aria-labelledby="quick-assign-modal-title"
             class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-ink/80 backdrop-blur-sm" @click="quickModalOpen = false"></div>
            <div class="relative flex max-h-[90vh] w-full max-w-xl flex-col overflow-y-auto rounded-2xl bg-surface p-6 shadow-2xl">
                <div class="flex items-start justify-between border-b border-border pb-3">
                    <div>
                        <span class="text-xs font-semibold text-primary" x-text="editingAssignmentId ? 'Ubah Penugasan Dosen' : 'Tambah Pengajar Kelas'"></span>
                        <h3 id="quick-assign-modal-title" class="text-lg font-bold text-ink" x-text="activeOffering.course"></h3>
                        <p class="text-xs text-muted">
                            Kelas: <span class="font-semibold text-ink" x-text="activeOffering.classGroup"></span>
                            <template x-if="activeOffering.sks">
                                <span> • <span class="font-semibold text-ink" x-text="`${activeOffering.sks} SKS`"></span></span>
                            </template>
                        </p>
                    </div>
                    <button type="button" @click="quickModalOpen = false" aria-label="Tutup" class="rounded p-1 text-muted hover:text-ink cursor-pointer">
                        <i data-lucide="x" class="size-5" aria-hidden="true"></i>
                    </button>
                </div>

                {{-- Daftar Anggota Tim Saat Ini pada Kelas Ini --}}
                <template x-if="activeOffering.team && activeOffering.team.length > 0">
                    <div class="mt-4 rounded-xl border border-border bg-page p-3">
                        <div class="mb-2 flex items-center justify-between gap-2">
                            <span class="text-xs font-semibold text-ink">Tim Pengajar Saat Ini</span>
                            <button type="button"
                                    @click="switchToCreate('partner')"
                                    class="inline-flex items-center gap-1 rounded-md bg-primary-soft px-2 py-1 text-[11px] font-semibold text-primary hover:bg-primary hover:text-white transition cursor-pointer">
                                <span>+ Tambah Anggota Baru</span>
                            </button>
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <template x-for="member in activeOffering.team" :key="member.id">
                                <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg border px-3 py-2 text-xs transition"
                                     x-data="{ confirmingDelete: false }"
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
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        {{-- Toggle Lock --}}
                                        <form method="POST" :action="@js($lockUrlTemplate).replace('__ID__', member.id)" class="inline">
                                            @csrf
                                            <button type="submit"
                                                    class="inline-flex items-center gap-1 rounded-md border border-border bg-surface px-2 py-1 text-[11px] font-medium transition hover:border-primary hover:text-primary cursor-pointer"
                                                    :class="member.is_locked ? 'text-primary' : 'text-muted'"
                                                    :title="member.is_locked ? 'Klik untuk buka kunci' : 'Klik untuk kunci penugasan'">
                                                <i x-show="member.is_locked" data-lucide="lock" class="size-3" aria-hidden="true"></i>
                                                <i x-show="!member.is_locked" data-lucide="lock-open" class="size-3" aria-hidden="true"></i>
                                                <span x-text="member.is_locked ? 'Terkunci' : 'Kunci'"></span>
                                            </button>
                                        </form>

                                        {{-- Edit Button --}}
                                        <template x-if="!member.is_locked && !confirmingDelete">
                                            <button type="button"
                                                    @click="openEditAssignment(activeOffering, member)"
                                                    class="inline-flex items-center gap-1 rounded-control px-2.5 py-1 text-xs font-semibold transition cursor-pointer"
                                                    :class="editingAssignmentId === member.id ? 'bg-primary text-white' : 'border border-border bg-surface text-primary hover:border-primary/40 hover:bg-primary-soft'">
                                                <i data-lucide="pencil" class="size-3.5" aria-hidden="true"></i>
                                                <span x-text="editingAssignmentId === member.id ? 'Sedang Diedit' : 'Edit'"></span>
                                            </button>
                                        </template>

                                        {{-- Delete Button with inline confirmation --}}
                                        <template x-if="!member.is_locked && !confirmingDelete">
                                            <button type="button"
                                                    @click="confirmingDelete = true"
                                                    class="inline-flex items-center gap-1 rounded-control border border-danger/30 bg-danger-soft/30 px-2.5 py-1 text-xs font-semibold text-danger hover:bg-danger hover:text-white transition cursor-pointer"
                                                    title="Hapus pengajar dari tim">
                                                <i data-lucide="trash-2" class="size-3.5" aria-hidden="true"></i>
                                                <span>Hapus</span>
                                            </button>
                                        </template>
                                        <template x-if="!member.is_locked && confirmingDelete">
                                            <div class="inline-flex items-center gap-1">
                                                <button type="button" @click="confirmingDelete = false" class="rounded-control border border-border bg-surface px-2 py-1 text-[11px] font-medium text-muted hover:text-ink cursor-pointer">Batal</button>
                                                <form method="POST" :action="@js($destroyUrlTemplate).replace('__ID__', member.id)" class="inline">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="rounded-control bg-danger px-2 py-1 text-[11px] font-semibold text-white hover:bg-danger/90 cursor-pointer">Ya, Hapus</button>
                                                </form>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>

                <form method="POST"
                      :action="editingAssignmentId ? @js($updateUrlTemplate).replace('__ID__', editingAssignmentId) : @js(route('admin.teaching.store'))"
                      class="mt-4 flex flex-col gap-4">
                    @csrf
                    <template x-if="editingAssignmentId">
                        <input type="hidden" name="_method" value="PUT">
                    </template>
                    <input type="hidden" name="course_offering_id" :value="activeOffering.id">

                    <div>
                        <div class="mb-1.5 flex items-center justify-between">
                            <label for="quick-lecturer-id" class="text-xs font-semibold text-ink">
                                <span x-text="editingAssignmentId ? 'Ganti / Pilih Dosen Pengajar' : 'Pilih Dosen Pengajar Baru'"></span>
                                <span class="text-danger">*</span>
                            </label>
                            <span class="text-[11px] text-muted" x-show="quickLecturers.length > 0" x-text="`${quickLecturers.length} dosen rekomendasi MK ini`"></span>
                        </div>

                        <div class="relative mb-2">
                            <input id="quick-lecturer-search"
                                   type="text"
                                   x-model="quickSearch"
                                   aria-label="Filter daftar dosen pengajar"
                                   placeholder="Ketik nama dosen untuk memfilter daftar pilihan di bawah..."
                                   class="w-full rounded-control border border-border bg-page px-3 py-1.5 text-xs text-ink focus:border-primary focus:bg-surface focus:outline-none">
                        </div>

                        <select id="quick-lecturer-id" name="lecturer_id" x-model="quickLecturerId" required class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                            <option value="">-- Pilih Dosen Pengajar --</option>
                            <template x-if="quickLecturers.length > 0">
                                <optgroup label="Dosen Pengampu Mata Kuliah Ini (Rekomendasi)">
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
                            <label for="quick-role" class="mb-1 block text-xs font-medium text-muted">Peran Dosen</label>
                            <select id="quick-role" name="role" x-model="activeOffering.role" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                                <option value="lead">Pengampu (Utama)</option>
                                <option value="partner">Mitra (Pendamping)</option>
                            </select>
                        </div>
                        <div>
                            <label for="quick-phase" class="mb-1 block text-xs font-medium text-muted">Periode Mengajar</label>
                            <select id="quick-phase" name="phase" x-model="quickPhase" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
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
        <div x-show="batchModalOpen" x-cloak data-modal-name="batch-assign-modal"
             role="dialog" aria-modal="true" aria-labelledby="batch-assign-modal-title"
             class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-ink/80 backdrop-blur-sm" @click="batchModalOpen = false"></div>
            <div class="relative max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-2xl bg-surface p-6 shadow-2xl">
                <div class="flex items-start justify-between border-b border-border pb-3">
                    <div>
                        <span class="text-xs font-semibold text-primary">Penyesuaian Massal</span>
                        <h3 id="batch-assign-modal-title" class="text-lg font-bold text-ink">Tugaskan Dosen (<span class="text-primary" x-text="selectedIds.length"></span> Kelas Terpilih)</h3>
                    </div>
                    <button type="button" @click="batchModalOpen = false" aria-label="Tutup" class="rounded p-1 text-muted hover:text-ink cursor-pointer">
                        <i data-lucide="x" class="size-5" aria-hidden="true"></i>
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
                        <label for="batch-lecturer-id" class="mb-1 block text-xs font-medium text-muted">Pilih Dosen <span class="text-danger">*</span></label>
                        <select id="batch-lecturer-id" name="lecturer_id" required class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                            <option value="">-- Pilih Dosen Terverifikasi --</option>
                            @foreach ($lecturerOptionsEnriched as $lec)
                                <option value="{{ $lec['id'] }}">
                                    {{ $lec['name'] }} — {{ $lec['sks_info'] }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <label for="batch-role" class="mb-1 block text-xs font-medium text-muted">Peran Dosen</label>
                            <select id="batch-role" name="role" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                                <option value="lead">Pengampu (Utama)</option>
                                <option value="partner">Mitra (Pendamping)</option>
                            </select>
                        </div>
                        <div>
                            <label for="batch-phase" class="mb-1 block text-xs font-medium text-muted">Periode Mengajar</label>
                            <select id="batch-phase" name="phase" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                                <option value="full">Satu semester penuh</option>
                                <option value="before_uts">Pertemuan 1–7 (sampai UTS)</option>
                                <option value="after_uts">Pertemuan 8–14 (sampai UAS)</option>
                            </select>
                        </div>
                    </div>

                    <div class="rounded-lg bg-page p-3 text-xs text-muted">
                        <i data-lucide="info" class="size-4 inline text-primary mr-1" aria-hidden="true"></i>
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
        <div x-show="rekapModalOpen" x-cloak data-modal-name="rekap-load-modal"
             role="dialog" aria-modal="true" aria-labelledby="rekap-load-modal-title"
             class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-ink/80 backdrop-blur-sm" @click="rekapModalOpen = false"></div>
            <div class="relative flex max-h-[85vh] w-full max-w-4xl flex-col rounded-2xl bg-surface p-6 shadow-2xl">
                <div class="flex items-start justify-between border-b border-border pb-3">
                    <div>
                        <span class="text-xs font-semibold text-primary">Semester Aktif</span>
                        <h3 id="rekap-load-modal-title" class="text-xl font-bold text-ink">Rekap Beban SKS Dosen</h3>
                        <p class="text-xs text-muted">Target dan maksimum memakai SKS Semester, dihitung satu kali per mata kuliah di setiap kelas. SKS × Pertemuan mengikuti jumlah pertemuan setiap pengajar.</p>
                    </div>
                    <button type="button" @click="rekapModalOpen = false" aria-label="Tutup" class="rounded p-1 text-muted hover:text-ink cursor-pointer">
                        <i data-lucide="x" class="size-5" aria-hidden="true"></i>
                    </button>
                </div>

                <div class="my-3">
                    <input type="text"
                           x-model="lecturerSearch"
                           aria-label="Filter nama dosen pada tabel rekap"
                           placeholder="Filter nama dosen pada tabel rekap..."
                           class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                </div>

                <div class="min-h-0 flex-1 overflow-x-auto overflow-y-auto rounded-lg border border-border custom-scrollbar">
                    <table class="w-full text-left text-sm">
                        <thead class="sticky top-0 z-10 border-b border-border bg-page text-xs text-muted">
                            <tr>
                                <th scope="col" class="py-2.5 px-4 font-semibold">Dosen</th>
                                <th scope="col" class="py-2.5 px-3 text-center font-semibold">SKS Semester</th>
                                <th scope="col" class="py-2.5 px-3 text-center font-semibold">Target</th>
                                <th scope="col" class="py-2.5 px-3 text-center font-semibold">Maks.</th>
                                <th scope="col" class="py-2.5 px-3 text-center font-semibold">Sisa</th>
                                <th scope="col" class="py-2.5 px-4 font-semibold">Status Beban</th>
                                <th scope="col" class="py-2.5 px-3 text-center font-semibold">SKS × Pertemuan</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @forelse ($loads as $load)
                                <tr x-show="!lecturerSearch || @js(strtolower($load['lecturer']->name)).includes(lecturerSearch.toLowerCase())">
                                    <td class="py-2.5 px-4 font-medium text-ink">{{ $load['lecturer']->name }}</td>
                                    <td class="py-2.5 px-3 text-center font-semibold text-ink">
                                        {{ $load['assigned'] }}
                                        @if ($load['pending'] > 0)
                                            <span class="block text-[10px] font-normal text-muted">+{{ $load['pending'] }} pending</span>
                                        @endif
                                    </td>
                                    <td class="py-2.5 px-3 text-center">{{ $load['target'] ?? '—' }}</td>
                                    <td class="py-2.5 px-3 text-center">{{ $load['max'] ?? '—' }}</td>
                                    <td class="py-2.5 px-3 text-center">{{ $load['remaining'] ?? '—' }}</td>
                                    <td class="py-2.5 px-4">
                                        <x-status-badge :variant="$load['tone']">{{ $load['status'] }}</x-status-badge>
                                    </td>
                                    <td class="py-2.5 px-3 text-center font-semibold text-primary">
                                        {{ $load['meeting_sks'] }}
                                        @if ($load['pending_phases'] > 0)
                                            <span class="block text-[10px] font-normal text-muted">{{ $load['pending_phases'] }} penugasan perlu atur fase</span>
                                        @endif
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

                <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-border pt-3 text-xs text-muted">
                    <span>SKS Semester menjumlahkan bobot mata kuliah pada setiap kelas yang diajar masing-masing dosen.</span>
                    <x-button variant="secondary" type="button" @click="rekapModalOpen = false">Tutup</x-button>
                </div>
            </div>
        </div>
        @endif
    </div>
@endsection
