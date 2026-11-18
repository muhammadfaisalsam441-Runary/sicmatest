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
                           autocorrect="off"
                           autocapitalize="none"
                           spellcheck="false"
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
            <x-table-pagination :paginator="$offerings"
                                form="teaching-filter-form"
                                :per-page="$perPage"
                                :options="[25, 50, 100]"
                                unit="kelas penawaran"
                                select-id="teaching-per-page"
                                aria-label="Jumlah baris per halaman"
                                unit-suffix=" baris" />
        </x-card>

        {{-- FLOATING BATCH ACTION BAR --}}
        <x-batch-action-bar unit="kelas">
            <button type="button"
                    @click="batchModalOpen = true; $nextTick(() => window.renderIcons())"
                    class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg bg-primary px-3.5 py-1.5 text-xs font-semibold text-white shadow-xs transition hover:bg-primary/90">
                <i data-lucide="zap" class="size-3.5" aria-hidden="true"></i>
                <span>Tugaskan Dosen Sekaligus</span>
            </button>
        </x-batch-action-bar>

        @include('admin.teaching.modals.quick-assign-modal')
        @include('admin.teaching.modals.batch-assign-modal')
        @include('admin.teaching.modals.rekap-load-modal')
        @endif
    </div>
@endsection
