@use('App\Enums\LecturerHomebase')
@use('App\Enums\LecturerProfileStatus')
@use('App\Http\Controllers\Admin\LecturerController')
@extends('layouts.admin')

@section('title', 'Data Dosen')
@section('breadcrumb', 'Data Dosen')

@section('breadcrumb-actions')
    <x-button variant="secondary" :href="route('admin.dashboard')">Dashboard</x-button>
@endsection

@section('content')
    @php
        $oldForm = old('form');
        $oldLecturerPayload = $oldForm === 'lecturer' ? [
            'id' => old('lecturer_id') ? (int) old('lecturer_id') : null,
            'updateUrl' => old('update_url') ?: null,
            'name' => old('name', ''),
            'nidn' => old('nidn', ''),
            'nip' => old('nip', ''),
            'rank' => old('rank', ''),
            'grade' => old('grade', ''),
            'functional_position' => old('functional_position', ''),
            'homebase' => old('homebase', ''),
            'profile_status' => old('profile_status', 'verified'),
            'leadership_position' => old('leadership_position', ''),
            'leadership_from' => old('leadership_from', ''),
            'leadership_to' => old('leadership_to', ''),
            'target_sks' => old('target_sks', ''),
            'max_sks' => old('max_sks', ''),
            'course_ids' => array_map('strval', (array) old('course_ids', [])),
            'source_course_ids' => [],
        ] : null;
    @endphp

    <div class="flex flex-col gap-4 admin-page-stack"
         x-data="lecturerManager(@js($lecturers->pluck('id')->all()), @js($lecturersPayload ?? []))"
         x-init="
             @if ($errors->any())
                 @if ($oldForm === 'batch_target')
                     selectedIds = @js(array_map('intval', (array) old('lecturer_ids', [])));
                     batchTargetModalOpen = true;
                 @elseif ($oldForm === 'import')
                     importModalOpen = true;
                 @elseif ($oldForm === 'lecturer')
                     openEditModal(@js($oldLecturerPayload));
                 @endif
             @endif
         "
         :data-editing="(editMode || formModalOpen || batchTargetModalOpen || importModalOpen || selectedIds.length > 0) ? 'true' : null"
         @sicma:navigated.document="formModalOpen = false; batchTargetModalOpen = false; importModalOpen = false; editMode = false">
        <x-page-header title="Data dosen dari jadwal" :subtitle="$period ? 'Periode '.$period->name.' • '.$total.' dosen terdaftar' : $total.' dosen terdaftar'">
            <x-slot:actions>
                <x-button variant="secondary" size="sm" icon="download" :href="route('admin.lecturers.export', request()->only(['filter', 'q', 'sort', 'dir']))" download>Unduh CSV</x-button>
                <x-button variant="secondary" size="sm" icon="upload" @click="importModalOpen = true">Impor spreadsheet</x-button>
                <x-button size="sm" icon="plus" @click="openCreateModal()">Tambah dosen</x-button>
            </x-slot:actions>
        </x-page-header>

        {{-- Toolbar Filter & Pencarian Cepat --}}
        <x-card padding="p-4" class="admin-table-card flex flex-col gap-3">
            <div class="flex flex-wrap items-center gap-1.5 border-b border-border pb-2.5">
                <a href="{{ route('admin.lecturers.index', array_filter(['filter' => null, 'q' => $keyword, 'per_page' => $perPage, 'sort' => $sort !== 'name' ? $sort : null, 'dir' => $dir !== 'asc' ? $dir : null])) }}"
                   @class([
                       'rounded-full px-3 py-1 text-xs font-semibold transition',
                       'bg-primary text-white' => $filter === 'semua',
                       'bg-page text-muted hover:text-ink hover:bg-border' => $filter !== 'semua',
                   ])>
                    Semua ({{ $total }})
                </a>
                <a href="{{ route('admin.lecturers.index', array_filter(['filter' => 'verifikasi', 'q' => $keyword, 'per_page' => $perPage, 'sort' => $sort !== 'name' ? $sort : null, 'dir' => $dir !== 'asc' ? $dir : null])) }}"
                   @class([
                       'inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold transition',
                       'bg-danger text-white' => $filter === 'verifikasi',
                       'bg-danger-soft/60 text-danger hover:bg-danger-soft' => $filter !== 'verifikasi',
                   ])>
                    <i data-lucide="triangle-alert" class="size-3.5 shrink-0" aria-hidden="true"></i>
                    <span>Perlu Verifikasi ({{ $unverified }})</span>
                </a>
                <a href="{{ route('admin.lecturers.index', array_filter(['filter' => 'jtik', 'q' => $keyword, 'per_page' => $perPage, 'sort' => $sort !== 'name' ? $sort : null, 'dir' => $dir !== 'asc' ? $dir : null])) }}"
                   @class([
                       'rounded-full px-3 py-1 text-xs font-semibold transition',
                       'bg-primary text-white' => $filter === 'jtik',
                       'bg-page text-muted hover:text-ink hover:bg-border' => $filter !== 'jtik',
                   ])>
                    Homebase JTIK ({{ $jtikCount }})
                </a>
                <a href="{{ route('admin.lecturers.index', array_filter(['filter' => 'luar', 'q' => $keyword, 'per_page' => $perPage, 'sort' => $sort !== 'name' ? $sort : null, 'dir' => $dir !== 'asc' ? $dir : null])) }}"
                   @class([
                       'rounded-full px-3 py-1 text-xs font-semibold transition',
                       'bg-primary text-white' => $filter === 'luar',
                       'bg-page text-muted hover:text-ink hover:bg-border' => $filter !== 'luar',
                   ])>
                    Luar JTIK ({{ $externalCount }})
                </a>
                <a href="{{ route('admin.lecturers.index', array_filter(['filter' => 'pimpinan', 'q' => $keyword, 'per_page' => $perPage, 'sort' => $sort !== 'name' ? $sort : null, 'dir' => $dir !== 'asc' ? $dir : null])) }}"
                   @class([
                       'rounded-full px-3 py-1 text-xs font-semibold transition',
                       'bg-primary text-white' => $filter === 'pimpinan',
                       'bg-page text-muted hover:text-ink hover:bg-border' => $filter !== 'pimpinan',
                   ])>
                    Pimpinan ({{ $leaderCount }})
                </a>
            </div>

            {{-- Form Pencarian Cepat --}}
            <form id="lecturer-filter-form" method="GET" action="{{ route('admin.lecturers.index') }}" class="flex flex-col gap-2 sm:flex-row sm:items-center">
                @if ($filter !== 'semua')
                    <input type="hidden" name="filter" value="{{ $filter }}">
                @endif
                @if ($sort !== 'name')
                    <input type="hidden" name="sort" value="{{ $sort }}">
                @endif
                @if ($dir !== 'asc')
                    <input type="hidden" name="dir" value="{{ $dir }}">
                @endif
                @if ($lecturers->total() === 0)
                    <input type="hidden" name="per_page" value="{{ $perPage }}">
                @endif

                <div class="relative flex-1 min-w-[200px]">
                    <i data-lucide="search" class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted" aria-hidden="true"></i>
                    <input id="lecturer-search" type="search" name="q" value="{{ $keyword }}" data-table-search
                           @input.debounce.400ms="$el.form.requestSubmit()"
                           aria-label="Cari Nama Dosen, NIP, atau NIDN"
                           placeholder="Cari nama dosen, gelar, NIP, atau NIDN (/)..."
                           class="w-full rounded-control border border-border bg-surface pl-9 pr-8 py-2 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                    @if ($keyword !== '')
                        <a href="{{ route('admin.lecturers.index', array_filter(['filter' => $filter !== 'semua' ? $filter : null, 'per_page' => $perPage, 'sort' => $sort !== 'name' ? $sort : null, 'dir' => $dir !== 'asc' ? $dir : null])) }}"
                           class="absolute right-2.5 top-1/2 -translate-y-1/2 text-muted hover:text-ink"
                           title="Bersihkan pencarian" aria-label="Bersihkan pencarian">
                            <i data-lucide="x" class="size-3.5" aria-hidden="true"></i>
                        </a>
                    @endif
                </div>

                @if ($keyword !== '' || $filter !== 'semua')
                    <a href="{{ route('admin.lecturers.index', array_filter(['per_page' => $perPage !== 25 ? $perPage : null])) }}"
                       class="inline-flex h-[38px] shrink-0 items-center justify-center gap-1.5 rounded-control border border-border px-3 text-xs font-semibold text-muted hover:bg-page hover:text-ink transition"
                       title="Reset Pencarian" aria-label="Reset pencarian">
                        <i data-lucide="rotate-ccw" class="size-3.5" aria-hidden="true"></i>
                        <span>Reset</span>
                    </a>
                @endif
            </form>

            {{-- Bar Bantuan Cepat saat Mode Edit Massal Aktif --}}
            <div x-show="editMode" x-cloak class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-primary/30 bg-primary-soft/30 p-3">
                <div class="flex flex-wrap items-center gap-2 text-xs">
                    <span class="flex items-center gap-1.5 font-bold text-primary">
                        <i data-lucide="pencil" class="size-4" aria-hidden="true"></i> Mode Edit Massal Aktif
                    </span>
                    <span class="text-muted">| Target / Maks SKS cepat:</span>
                    <div class="ml-1 flex flex-wrap items-center gap-1.5">
                        <input type="number" min="0" max="60" x-model="fillTargetSks" aria-label="Target SKS cepat" placeholder="Target" class="w-16 rounded border border-border bg-surface px-2 py-1 text-center text-xs font-semibold text-ink focus:border-primary focus:outline-none">
                        <span class="text-muted">/</span>
                        <input type="number" min="0" max="60" x-model="fillMaxSks" aria-label="Maks SKS cepat" placeholder="Maks" class="w-16 rounded border border-border bg-surface px-2 py-1 text-center text-xs font-semibold text-ink focus:border-primary focus:outline-none">
                        <button type="button" @click="applyFillEmptySks()" class="cursor-pointer rounded border border-primary/40 bg-surface px-2.5 py-1 text-xs font-semibold text-primary shadow-xs transition hover:bg-primary hover:text-white">
                            Isi yang Kosong
                        </button>
                        <button type="button" @click="applyFillAllSks()" class="cursor-pointer rounded border border-primary/40 bg-primary/15 px-2.5 py-1 text-xs font-semibold text-primary shadow-xs transition hover:bg-primary hover:text-white"
                                x-text="selectedIds.length > 0 ? 'Terapkan ke ' + selectedIds.length + ' Dosen Terpilih' : 'Terapkan ke Semua Tampil (' + visibleIds.length + ')'">
                        </button>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <button type="button" @click="cancelEditMode()" class="cursor-pointer rounded-control border border-border bg-surface px-3 py-1.5 text-xs font-semibold text-muted transition hover:text-ink">
                        Batal
                    </button>
                    <button type="submit" form="bulkLecturerForm" class="inline-flex cursor-pointer items-center gap-1.5 rounded-control bg-primary px-4 py-1.5 text-xs font-bold text-white shadow-sm transition hover:bg-primary/90">
                        <i data-lucide="check" class="size-4" aria-hidden="true"></i>
                        Simpan Semua Perubahan
                    </button>
                </div>
            </div>

            {{-- TABEL DATA DOSEN --}}
            <x-table-scroll>
                <x-slot:title>
                    <span>Menampilkan <strong class="text-ink">{{ $lecturers->count() }}</strong> dari <strong class="text-ink">{{ number_format($lecturers->total()) }}</strong> dosen</span>
                    @if ($missingNidn > 0)
                        <span class="ml-2 inline-flex items-center gap-1 rounded-full border border-border bg-surface px-2 py-0.5 text-[11px] font-medium text-muted" title="Lengkapi lewat tombol Edit atau Edit Massal bila datanya sudah ada.">
                            <i data-lucide="info" class="size-3 shrink-0" aria-hidden="true"></i>
                            <span>{{ $missingNidn }} dari {{ $total }} dosen belum memiliki NIDN</span>
                        </span>
                    @endif
                </x-slot:title>
                <x-slot:headerActions>
                    <x-table-density-toggle />
                    <button type="button" @click="toggleEditMode()"
                            :class="editMode ? 'bg-amber-500 text-white border-amber-500' : 'bg-surface text-ink border-border hover:bg-primary-soft hover:text-primary'"
                            class="inline-flex items-center gap-1.5 rounded-control border px-2.5 py-1 text-xs font-semibold transition cursor-pointer">
                        <i data-lucide="pencil" class="size-3.5" aria-hidden="true"></i>
                        <span x-text="editMode ? 'Tutup Edit Massal' : 'Edit Massal'"></span>
                    </button>
                </x-slot:headerActions>
                <table class="w-full border-collapse text-left text-sm">
                    <thead class="sticky top-0 z-10 border-b border-border bg-page text-xs font-semibold text-muted">
                        <tr>
                            <th scope="col" class="w-10 px-4 py-3 text-center">
                                <input type="checkbox"
                                       @change="toggleSelectAll($event.target.checked)"
                                       :checked="isAllSelected()"
                                       class="size-4 rounded border-border text-primary focus:ring-primary cursor-pointer"
                                       aria-label="Pilih semua dosen di halaman ini"
                                       title="Pilih semua di halaman ini (mendukung Shift+Klik)">
                            </th>
                            <x-sortable-th column="name" :sort="$sort" :dir="$dir" class="px-4 py-3">Dosen, NIP & NIDN</x-sortable-th>
                            <x-sortable-th column="homebase" :sort="$sort" :dir="$dir" class="px-4 py-3">Homebase & Jabatan</x-sortable-th>
                            <x-sortable-th column="status" :sort="$sort" :dir="$dir" class="px-4 py-3">Status & Peran</x-sortable-th>
                            <x-sortable-th column="target_sks" :sort="$sort" :dir="$dir" class="px-4 py-3">Target SKS & Beban MK</x-sortable-th>
                            <th scope="col" class="px-4 py-3 text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($lecturers as $index => $lecturer)
                            @php
                                $term = $lecturer->leadershipTerms->first(fn ($term) => $term->isActiveOn(now()));
                                $target = $lecturer->loadTargets->first();
                                $isVerified = $lecturer->profile_status === LecturerProfileStatus::Verified;
                                $selection = $courseSelections[$lecturer->id];
                                $editData = [
                                    'id' => $lecturer->id,
                                    'updateUrl' => route('admin.lecturers.update', $lecturer),
                                    'name' => $lecturer->name,
                                    'nidn' => $lecturer->nidn,
                                    'nip' => $lecturer->nip,
                                    'rank' => $lecturer->rank,
                                    'grade' => $lecturer->grade,
                                    'functional_position' => $lecturer->functional_position,
                                    'homebase' => $lecturer->homebase?->value,
                                    'profile_status' => $lecturer->profile_status->value,
                                    'leadership_position' => $term?->position,
                                    'leadership_from' => $term?->valid_from?->toDateString(),
                                    'leadership_to' => $term?->valid_to?->toDateString(),
                                    'target_sks' => $target?->target_sks !== null ? (int) $target->target_sks : null,
                                    'max_sks' => $target?->max_sks !== null ? (int) $target->max_sks : null,
                                    'course_ids' => array_map('strval', $selection['manual']),
                                    'source_course_ids' => array_map('strval', $selection['source']),
                                ];
                            @endphp

                            <tr class="border-b border-border transition hover:bg-primary-soft/20" :class="{ 'bg-primary-soft/30': selectedIds.includes({{ $lecturer->id }}) }">
                                {{-- Checkbox --}}
                                <td class="px-4 py-3 text-center">
                                    <input type="checkbox"
                                           value="{{ $lecturer->id }}"
                                           x-model.number="selectedIds"
                                           aria-label="Pilih {{ $lecturer->name }}"
                                           class="size-4 rounded border-border text-primary focus:ring-primary cursor-pointer">
                                </td>

                                {{-- Dosen & NIDN --}}
                                <td class="px-4 py-3">
                                    <div x-show="!editMode" class="flex flex-col">
                                        <span class="font-bold text-ink text-sm">{{ $lecturer->name }}</span>
                                        <div class="mt-0.5 flex flex-wrap items-center gap-1.5 text-xs text-muted">
                                            @if ($lecturer->nip)
                                                <span class="font-mono text-[11px] text-muted">NIP: {{ $lecturer->nip }}</span>
                                            @endif
                                            @if ($lecturer->nidn)
                                                <span class="font-mono text-[11px] text-muted">NIDN: {{ $lecturer->nidn }}</span>
                                            @else
                                                <span class="text-[11px] text-muted/70">NIDN belum diisi</span>
                                            @endif

                                            @if ($lecturer->source_name && $lecturer->source_name !== $lecturer->name)
                                                <span class="text-muted/60">• Sumber: <em>{{ $lecturer->source_name }}</em></span>
                                            @endif
                                        </div>
                                    </div>
                                    <div x-show="editMode" x-cloak class="flex flex-col gap-1.5">
                                        <input type="hidden" form="bulkLecturerForm" name="lecturers[{{ $index }}][id]" value="{{ $lecturer->id }}" :disabled="!editMode">
                                        <input type="text" form="bulkLecturerForm" name="lecturers[{{ $index }}][name]"
                                               x-model="lecturersData[{{ $lecturer->id }}].name" :disabled="!editMode" required
                                               placeholder="Nama & gelar"
                                               class="w-full min-w-[200px] rounded-control border border-border bg-surface px-2.5 py-1 text-xs font-semibold text-ink focus:border-primary focus:outline-none">
                                        <div class="flex flex-wrap gap-1.5">
                                            <input type="text" form="bulkLecturerForm" name="lecturers[{{ $index }}][nip]"
                                                   x-model="lecturersData[{{ $lecturer->id }}].nip" :disabled="!editMode"
                                                   placeholder="NIP (18 digit)" inputmode="numeric"
                                                   class="w-36 rounded-control border border-border bg-surface px-2 py-1 font-mono text-[11px] text-ink focus:border-primary focus:outline-none">
                                            <input type="text" form="bulkLecturerForm" name="lecturers[{{ $index }}][nidn]"
                                                   x-model="lecturersData[{{ $lecturer->id }}].nidn" :disabled="!editMode"
                                                   placeholder="NIDN"
                                                   class="w-28 rounded-control border border-border bg-surface px-2 py-1 font-mono text-[11px] text-ink focus:border-primary focus:outline-none">
                                        </div>
                                    </div>
                                </td>

                                {{-- Homebase / Prodi --}}
                                <td class="px-4 py-3">
                                    <div x-show="!editMode">
                                        @if ($lecturer->homebase === LecturerHomebase::Jtik)
                                            <span class="inline-flex items-center gap-1.5 rounded-full border border-primary/20 bg-primary-soft/60 px-2.5 py-0.5 text-xs font-semibold text-primary">
                                                <i data-lucide="building-2" class="size-3.5"></i> JTIK
                                            </span>
                                        @elseif ($lecturer->homebase === LecturerHomebase::External)
                                            <span class="inline-flex items-center gap-1.5 rounded-full border border-border bg-page px-2.5 py-0.5 text-xs font-medium text-muted">
                                                <i data-lucide="globe" class="size-3.5"></i> Luar JTIK
                                            </span>
                                        @else
                                            <span class="text-xs text-muted/60 italic">Belum diatur</span>
                                        @endif
                                        @if ($lecturer->functional_position || $lecturer->rank || $lecturer->grade)
                                            <span class="mt-1 block text-[11px] text-muted">{{ collect([$lecturer->functional_position, $lecturer->rank, $lecturer->grade])->filter()->implode(' • ') }}</span>
                                        @endif
                                    </div>
                                    <div x-show="editMode" x-cloak>
                                        <select form="bulkLecturerForm" name="lecturers[{{ $index }}][homebase]"
                                                x-model="lecturersData[{{ $lecturer->id }}].homebase" :disabled="!editMode"
                                                class="w-32 rounded-control border border-border bg-surface px-2 py-1 text-xs font-medium text-ink focus:border-primary focus:outline-none">
                                            <option value="">Belum diatur</option>
                                            <option value="jtik">JTIK</option>
                                            <option value="external">Luar JTIK</option>
                                        </select>
                                    </div>
                                </td>

                                {{-- Status & Peran --}}
                                <td class="px-4 py-3">
                                    <div x-show="!editMode" class="flex flex-col gap-1 items-start">
                                        @if ($term)
                                            <span class="inline-flex items-center gap-1 rounded bg-danger-soft px-1.5 py-0.5 text-[10px] font-bold text-danger border border-danger/20" title="Bebas jadwal Kamis">
                                                <i data-lucide="shield-check" class="size-3"></i>
                                                {{ $term->position }}
                                            </span>
                                            <span class="text-[10px] text-muted">tidak dijadwalkan Kamis</span>
                                        @endif
                                        <x-status-badge :variant="$isVerified ? 'success' : 'warning'">
                                            {{ $lecturer->profile_status->label() }}
                                        </x-status-badge>
                                    </div>
                                    <div x-show="editMode" x-cloak>
                                        <select form="bulkLecturerForm" name="lecturers[{{ $index }}][profile_status]"
                                                x-model="lecturersData[{{ $lecturer->id }}].profile_status" :disabled="!editMode"
                                                class="w-36 rounded-control border border-border bg-surface px-2 py-1 text-xs font-medium text-ink focus:border-primary focus:outline-none">
                                            <option value="verified">Terverifikasi</option>
                                            <option value="unverified">Perlu verifikasi</option>
                                        </select>
                                    </div>
                                </td>

                                {{-- Target SKS & Beban MK --}}
                                <td class="px-4 py-3">
                                    <div x-show="!editMode" class="flex flex-col gap-0.5 text-xs">
                                        @if ($target && ($target->target_sks !== null || $target->max_sks !== null))
                                            <span class="font-bold text-ink">{{ (int) ($target->target_sks ?? 0) }} SKS <span class="font-normal text-muted">/ maks {{ (int) ($target->max_sks ?? 16) }} SKS</span></span>
                                        @else
                                            <span class="text-muted/70 italic">Belum ditetapkan</span>
                                        @endif
                                        <span class="inline-flex items-center gap-1 text-[11px] text-muted">
                                            <i data-lucide="book-open" class="size-3 text-muted/70"></i>
                                            {{ $selection['count'] }} MK diampu
                                        </span>
                                    </div>
                                    <div x-show="editMode" x-cloak class="flex items-center gap-1.5">
                                        <input type="number" min="0" max="60" form="bulkLecturerForm"
                                               name="lecturers[{{ $index }}][target_sks]"
                                               x-model="lecturersData[{{ $lecturer->id }}].target_sks" :disabled="!editMode"
                                               placeholder="Target" title="Target SKS"
                                               class="w-16 rounded-control border border-primary/40 bg-surface px-2 py-1 text-center text-xs font-bold text-ink focus:border-primary focus:outline-none">
                                        <span class="text-xs text-muted">/</span>
                                        <input type="number" min="0" max="60" form="bulkLecturerForm"
                                               name="lecturers[{{ $index }}][max_sks]"
                                               x-model="lecturersData[{{ $lecturer->id }}].max_sks" :disabled="!editMode"
                                               placeholder="Maks" title="Maksimum SKS"
                                               class="w-16 rounded-control border border-border bg-surface px-2 py-1 text-center text-xs font-semibold text-ink focus:border-primary focus:outline-none">
                                    </div>
                                </td>

                                {{-- Aksi --}}
                                <td class="px-4 py-3 text-right">
                                    <div class="flex items-center justify-end gap-1.5">
                                        {{-- Tombol Verifikasi Cepat (Jika belum verified) --}}
                                        @unless ($isVerified)
                                            <form method="POST" action="{{ route('admin.lecturers.batch') }}" class="inline">
                                                @csrf
                                                <input type="hidden" name="action" value="verify">
                                                <input type="hidden" name="lecturer_ids[]" value="{{ $lecturer->id }}">
                                                <button type="submit" class="inline-flex items-center gap-1 rounded-control border border-success/30 bg-success-soft/70 px-2 py-1 text-xs font-semibold text-success hover:bg-success hover:text-white transition cursor-pointer" title="Verifikasi dosen ini sekarang">
                                                    <i data-lucide="check" class="size-3.5" aria-hidden="true"></i>
                                                    <span>Verifikasi</span>
                                                </button>
                                            </form>
                                        @endunless

                                        <button type="button" @click="openEditModal(@js($editData))"
                                                class="inline-flex items-center gap-1 rounded-control border border-border bg-surface px-2.5 py-1 text-xs font-semibold text-primary hover:bg-primary-soft hover:border-primary/40 transition cursor-pointer"
                                                title="Ubah data dosen">
                                            <i data-lucide="pencil" class="size-3.5" aria-hidden="true"></i>
                                            <span>Edit</span>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="py-12 text-center text-muted">
                                    <i data-lucide="user-x" class="mx-auto size-8 text-muted/60 mb-2"></i>
                                    <p class="font-medium text-ink">Tidak ada data dosen yang sesuai dengan filter.</p>
                                    <p class="text-xs text-muted mt-1">Coba gunakan kata kunci pencarian lain.</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </x-table-scroll>

            {{-- Pagination & Data Summary Footer --}}
            @if ($lecturers->total() > 0)
                <div class="border-t border-border pt-3 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-center gap-2 text-xs text-muted">
                        @if ($lecturers->hasPages())
                            <span>
                                Menampilkan <span class="font-semibold text-ink">{{ $lecturers->firstItem() }}–{{ $lecturers->lastItem() }}</span> dari <span class="font-semibold text-ink">{{ number_format($lecturers->total()) }}</span> dosen
                            </span>
                        @else
                            <span>
                                Menampilkan <span class="font-semibold text-ink">1</span> sampai <span class="font-semibold text-ink">{{ $lecturers->total() }}</span> dari <span class="font-semibold text-ink">{{ number_format($lecturers->total()) }}</span> data dosen
                            </span>
                        @endif
                    </div>

                    <div class="flex flex-wrap items-center justify-between sm:justify-end gap-3">
                        <div class="flex items-center gap-1.5 text-xs text-muted">
                            <label for="lecturer-per-page" class="whitespace-nowrap">Baris:</label>
                            <select id="lecturer-per-page" name="per_page" form="lecturer-filter-form" @change="$el.form.requestSubmit()" aria-label="Tampilkan per halaman"
                                    class="rounded-control border border-border bg-surface px-2 py-1 text-xs text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                                @foreach ([25, 50, 100] as $n)
                                    <option value="{{ $n }}" @selected($perPage == $n)>{{ $n }}</option>
                                @endforeach
                            </select>
                        </div>

                        @if ($lecturers->hasPages())
                            {{ $lecturers->links() }}
                        @else
                            <span class="inline-flex items-center justify-center min-w-[32px] h-7 px-2.5 rounded-control border border-border bg-surface text-ink text-xs font-semibold">
                                Halaman 1 dari 1
                            </span>
                        @endif
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
             class="fixed bottom-6 left-1/2 z-40 flex w-max max-w-[calc(100vw-2rem)] -translate-x-1/2 flex-wrap items-center gap-3 rounded-xl border border-primary/30 bg-ink px-6 py-3.5 text-surface shadow-2xl">
            <div class="flex items-center gap-2">
                <i data-lucide="circle-check" class="size-5 text-success" aria-hidden="true"></i>
                <span class="text-sm font-semibold"><span x-text="selectedIds.length"></span> dosen dipilih</span>
                <button type="button"
                        x-show="!isAllSelected()"
                        @click="toggleSelectAll(true)"
                        class="ml-1 rounded-md border border-surface/20 bg-surface/10 px-2 py-0.5 text-[11px] font-semibold text-surface hover:bg-surface/20 transition cursor-pointer">
                    Pilih Semua Tampil
                </button>
            </div>
            <div class="h-4 w-px bg-surface/20"></div>

            {{-- 1. Set Target SKS Modal --}}
            <button type="button" @click="batchTargetModalOpen = true" class="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3.5 py-1.5 text-xs font-semibold text-white shadow-xs hover:bg-primary/90 transition cursor-pointer">
                <i data-lucide="target" class="size-3.5" aria-hidden="true"></i>
                Atur Target SKS
            </button>

            {{-- 2. Verifikasi Massal --}}
            <button type="button" @click="submitBatch('verify')" class="inline-flex items-center gap-1.5 rounded-lg bg-success px-3.5 py-1.5 text-xs font-semibold text-white shadow-xs hover:bg-success/90 transition cursor-pointer">
                <i data-lucide="user-check" class="size-3.5" aria-hidden="true"></i>
                Verifikasi Sekaligus
            </button>

            {{-- 3. Set Homebase JTIK --}}
            <button type="button" @click="submitBatch('homebase_jtik')" class="inline-flex items-center gap-1.5 rounded-lg bg-surface/10 px-3 py-1.5 text-xs font-semibold text-surface hover:bg-surface/20 transition cursor-pointer">
                <i data-lucide="building-2" class="size-3.5" aria-hidden="true"></i>
                Set Homebase JTIK
            </button>

            {{-- 4. Set Homebase Luar JTIK --}}
            <button type="button" @click="submitBatch('homebase_external')" class="inline-flex items-center gap-1.5 rounded-lg bg-surface/10 px-3 py-1.5 text-xs font-semibold text-surface hover:bg-surface/20 transition cursor-pointer">
                <i data-lucide="globe" class="size-3.5" aria-hidden="true"></i>
                Set Luar JTIK
            </button>

            <button type="button" @click="selectedIds = []" class="text-xs text-surface/70 hover:text-white transition ml-2 cursor-pointer">
                Batalkan Pilihan
            </button>
        </div>

        {{-- FORM TERSEMBUNYI UNTUK BULK INLINE EDIT --}}
        <form id="bulkLecturerForm" method="POST" action="{{ route('admin.lecturers.bulk-update') }}" class="hidden">
            @csrf
            @method('PUT')
        </form>

        {{-- FORM TERSEMBUNYI UNTUK BATCH ACTION STANDARD --}}
        <form x-ref="batchForm" method="POST" action="{{ route('admin.lecturers.batch') }}" class="hidden">
            @csrf
            <input type="hidden" name="action" x-model="batchAction">
            <template x-for="id in selectedIds" :key="id">
                <input type="hidden" name="lecturer_ids[]" :value="id">
            </template>
        </form>

        {{-- MODAL 1: EDIT / CREATE DINAMIS TUNGGAL --}}
        <div x-show="formModalOpen" x-cloak data-modal-name="lecturer-profile"
             role="dialog" aria-modal="true" aria-labelledby="lecturer-modal-title"
             @keydown.escape.window="if (formModalOpen) formModalOpen = false"
             class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-ink/80 backdrop-blur-sm" @click="formModalOpen = false"></div>
            <div class="relative max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-2xl bg-surface p-6 shadow-2xl">
                <div class="flex items-start justify-between border-b border-border pb-3">
                    <div>
                        <span class="text-xs font-semibold text-primary" x-text="formLecturer.id ? 'Perbarui Profil Dosen' : 'Dosen Baru'"></span>
                        <h3 id="lecturer-modal-title" class="text-lg font-bold text-ink" x-text="formLecturer.id ? formLecturer.name : 'Tambah Data Dosen'"></h3>
                    </div>
                    <button type="button" @click="formModalOpen = false" aria-label="Tutup modal profil dosen" class="text-muted hover:text-ink cursor-pointer">
                        <i data-lucide="x" class="size-5" aria-hidden="true"></i>
                    </button>
                </div>

                <form method="POST" action="{{ route('admin.lecturers.store') }}" :action="formLecturer.updateUrl || @js(route('admin.lecturers.store'))" class="mt-4 flex flex-col gap-4">
                    @csrf
                    <template x-if="formLecturer.id">
                        <input type="hidden" name="_method" value="PUT">
                    </template>
                    <input type="hidden" name="form" value="lecturer">
                    <input type="hidden" name="lecturer_id" :value="formLecturer.id || ''">
                    <input type="hidden" name="update_url" :value="formLecturer.updateUrl || ''">

                    @if ($errors->any() && $oldForm === 'lecturer')
                        <x-alert variant="danger">{{ $errors->first() }}</x-alert>
                    @endif

                    <div>
                        <label for="lecturer-name" class="mb-1 block text-xs font-medium text-muted">Nama Lengkap & Gelar <span class="text-danger">*</span></label>
                        <input id="lecturer-name" type="text" name="name" x-model="formLecturer.name" required class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                        @if ($oldForm === 'lecturer') @error('name') <p class="mt-1 text-xs font-medium text-danger">{{ $message }}</p> @enderror @endif
                    </div>

                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <label for="lecturer-nidn" class="mb-1 block text-xs font-medium text-muted">NIDN</label>
                            <input id="lecturer-nidn" type="text" name="nidn" x-model="formLecturer.nidn" placeholder="Nomor Induk Dosen Nasional" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                            @if ($oldForm === 'lecturer') @error('nidn') <p class="mt-1 text-xs font-medium text-danger">{{ $message }}</p> @enderror @endif
                        </div>
                        <div>
                            <label for="lecturer-homebase" class="mb-1 block text-xs font-medium text-muted">Asal / Homebase</label>
                            <select id="lecturer-homebase" name="homebase" x-model="formLecturer.homebase" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                                <option value="">Belum diverifikasi</option>
                                <option value="jtik">Homebase JTIK</option>
                                <option value="external">Luar JTIK</option>
                            </select>
                            @if ($oldForm === 'lecturer') @error('homebase') <p class="mt-1 text-xs font-medium text-danger">{{ $message }}</p> @enderror @endif
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <label for="lecturer-nip" class="mb-1 block text-xs font-medium text-muted">NIP</label>
                            <input id="lecturer-nip" type="text" name="nip" x-model="formLecturer.nip" inputmode="numeric" placeholder="18 angka" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                            @if ($oldForm === 'lecturer') @error('nip') <p class="mt-1 text-xs font-medium text-danger">{{ $message }}</p> @enderror @endif
                        </div>
                        <div>
                            <label for="lecturer-functional-position" class="mb-1 block text-xs font-medium text-muted">Jabatan fungsional</label>
                            <input id="lecturer-functional-position" type="text" name="functional_position" x-model="formLecturer.functional_position" placeholder="Contoh: Lektor" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                        </div>
                        <div>
                            <label for="lecturer-rank" class="mb-1 block text-xs font-medium text-muted">Pangkat</label>
                            <input id="lecturer-rank" type="text" name="rank" x-model="formLecturer.rank" placeholder="Contoh: Penata Tingkat I" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                        </div>
                        <div>
                            <label for="lecturer-grade" class="mb-1 block text-xs font-medium text-muted">Golongan</label>
                            <input id="lecturer-grade" type="text" name="grade" x-model="formLecturer.grade" placeholder="Contoh: III/d" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                        </div>
                    </div>

                    <div>
                        <label for="lecturer-profile-status" class="mb-1 block text-xs font-medium text-muted">Status Profil <span class="text-danger">*</span></label>
                        <select id="lecturer-profile-status" name="profile_status" x-model="formLecturer.profile_status" required class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                            <option value="verified">Terverifikasi (Dapat ditugaskan mengajar)</option>
                            <option value="unverified">Perlu verifikasi (Belum dapat mengajar)</option>
                        </select>
                        @if ($oldForm === 'lecturer') @error('profile_status') <p class="mt-1 text-xs font-medium text-danger">{{ $message }}</p> @enderror @endif
                    </div>

                    {{-- Jabatan Pimpinan --}}
                    <div class="rounded-lg border border-border p-3 bg-page/50 flex flex-col gap-3">
                        <div>
                            <label for="lecturer-leadership-position" class="mb-1 block text-xs font-semibold text-ink">Jabatan Pimpinan (Opsional)</label>
                            <input id="lecturer-leadership-position" type="text" name="leadership_position" x-model="formLecturer.leadership_position" placeholder="Contoh: Ketua Jurusan / Sekretaris Jurusan" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                            <p class="mt-1 text-[11px] text-muted">Dosen pimpinan otomatis tidak dijadwalkan pada hari Kamis.</p>
                        </div>
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2" x-show="formLecturer.leadership_position">
                            <div>
                                <label for="lecturer-leadership-from" class="mb-1 block text-xs font-medium text-muted">Mulai Menjabat</label>
                                <input id="lecturer-leadership-from" type="date" name="leadership_from" x-model="formLecturer.leadership_from" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                                @if ($oldForm === 'lecturer') @error('leadership_from') <p class="mt-1 text-xs font-medium text-danger">{{ $message }}</p> @enderror @endif
                            </div>
                            <div>
                                <label for="lecturer-leadership-to" class="mb-1 block text-xs font-medium text-muted">Akhir Menjabat</label>
                                <input id="lecturer-leadership-to" type="date" name="leadership_to" x-model="formLecturer.leadership_to" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                                @if ($oldForm === 'lecturer') @error('leadership_to') <p class="mt-1 text-xs font-medium text-danger">{{ $message }}</p> @enderror @endif
                            </div>
                        </div>
                    </div>

                    {{-- Target & Maks SKS --}}
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <label for="lecturer-target-sks" class="mb-1 block text-xs font-medium text-muted">Target SKS (Beban)</label>
                            <input id="lecturer-target-sks" type="number" step="1" min="0" name="target_sks" x-model="formLecturer.target_sks" placeholder="Contoh: 12" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                            @if ($oldForm === 'lecturer') @error('target_sks') <p class="mt-1 text-xs font-medium text-danger">{{ $message }}</p> @enderror @endif
                        </div>
                        <div>
                            <label for="lecturer-max-sks" class="mb-1 block text-xs font-medium text-muted">Maksimum SKS</label>
                            <input id="lecturer-max-sks" type="number" step="1" min="0" name="max_sks" x-model="formLecturer.max_sks" placeholder="Contoh: 16" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                            @if ($oldForm === 'lecturer') @error('max_sks') <p class="mt-1 text-xs font-medium text-danger">{{ $message }}</p> @enderror @endif
                        </div>
                    </div>

                    <fieldset x-show="formLecturer.id" :disabled="!formLecturer.id" class="rounded-control border border-border p-3">
                        <legend class="px-1 text-sm font-semibold text-ink">Mata kuliah yang bisa diampu</legend>
                        <input type="hidden" name="course_selection_present" value="1">
                        <p class="mb-3 text-xs text-muted">Mata kuliah dari spreadsheet dan jadwal semester aktif sudah tercentang. Centang pilihan tambahan agar dosen tersedia di Pembagian Mengajar.</p>
                        <p class="mb-2 text-sm font-medium text-ink"><span x-text="selectedCourseIds.length"></span> MK dipilih</p>
                        <template x-for="id in formLecturer.course_ids" :key="id">
                            <input type="hidden" name="course_ids[]" :value="id">
                        </template>
                        <label for="lecturer-course-search" class="sr-only">Cari mata kuliah</label>
                        <input id="lecturer-course-search" type="search" x-model="courseQuery" placeholder="Cari nama atau kode mata kuliah"
                               class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                        <div class="mt-2 max-h-48 overflow-y-auto rounded-control border border-border">
                            @forelse ($courses as $course)
                                <label x-show="@js(mb_strtolower($course->code.' '.$course->name)).includes(courseQuery.trim().toLowerCase())" class="flex items-start gap-2 border-b border-border px-3 py-2 text-sm last:border-b-0">
                                    <input type="checkbox" value="{{ $course->id }}" :checked="selectedCourseIds.includes($el.value)"
                                           :disabled="formLecturer.source_course_ids?.includes($el.value)"
                                           @change="toggleCourse($el.value, $event.target.checked)" class="mt-0.5">
                                    <span>{{ $course->name }} <span class="text-xs text-muted">{{ $course->code }}</span>
                                        <span x-show="formLecturer.source_course_ids?.includes('{{ $course->id }}')" class="text-xs text-muted">· Sudah tercatat</span>
                                    </span>
                                </label>
                            @empty
                                <p class="p-3 text-sm text-muted">Belum ada mata kuliah di katalog.</p>
                            @endforelse
                            @if ($courses->isNotEmpty())
                                @php($courseSearchStrings = $courses->map(fn ($c) => mb_strtolower($c->code.' '.$c->name))->values()->all())
                                <p x-show="courseQuery.trim() !== '' && !@js($courseSearchStrings).some(s => s.includes(courseQuery.trim().toLowerCase()))"
                                   x-cloak class="p-3 text-center text-xs text-muted">
                                    Tidak ada mata kuliah yang cocok dengan pencarian.
                                </p>
                            @endif
                        </div>
                    </fieldset>

                    <div class="mt-2 flex items-center justify-end gap-2 border-t border-border pt-4">
                        <x-button variant="secondary" type="button" @click="formModalOpen = false">Batal</x-button>
                        <x-button variant="primary" type="submit" icon="check">Simpan Data Dosen</x-button>
                    </div>
                </form>
            </div>
        </div>

        {{-- MODAL 2: BATCH ATUR TARGET SKS --}}
        <div x-show="batchTargetModalOpen" x-cloak data-modal-name="lecturer-batch-target"
             role="dialog" aria-modal="true" aria-labelledby="lecturer-batch-title"
             @keydown.escape.window="if (batchTargetModalOpen) batchTargetModalOpen = false"
             class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-ink/80 backdrop-blur-sm" @click="batchTargetModalOpen = false"></div>
            <div class="relative w-full max-w-md rounded-2xl bg-surface p-6 shadow-2xl">
                <div class="flex items-start justify-between border-b border-border pb-3">
                    <div>
                        <span class="text-xs font-semibold text-primary">Penyesuaian Massal</span>
                        <h3 id="lecturer-batch-title" class="text-lg font-bold text-ink">Atur Target SKS (<span x-text="selectedIds.length"></span> Dosen Terpilih)</h3>
                    </div>
                    <button type="button" @click="batchTargetModalOpen = false" aria-label="Tutup modal target SKS" class="text-muted hover:text-ink cursor-pointer">
                        <i data-lucide="x" class="size-5" aria-hidden="true"></i>
                    </button>
                </div>

                <form method="POST" action="{{ route('admin.lecturers.batch') }}" class="mt-4 flex flex-col gap-4">
                    @csrf
                    <input type="hidden" name="form" value="batch_target">
                    <input type="hidden" name="action" value="set_target_sks">
                    <template x-for="id in selectedIds" :key="id">
                        <input type="hidden" name="lecturer_ids[]" :value="id">
                    </template>

                    @if ($errors->any() && $oldForm === 'batch_target')
                        <x-alert variant="danger">{{ $errors->first() }}</x-alert>
                    @endif

                    <div class="rounded-lg border border-primary/20 bg-primary-soft/40 p-3 text-xs text-ink">
                        Target dan batas maksimum SKS ini akan diterapkan serentak ke <strong x-text="selectedIds.length"></strong> dosen yang sedang dicentang.
                    </div>

                    <div>
                        <label for="batch-target-sks" class="mb-1 block text-xs font-medium text-muted">Target SKS untuk Seluruh Dosen Terpilih</label>
                        <input id="batch-target-sks" type="number" step="1" min="0" name="target_sks" value="{{ $oldForm === 'batch_target' ? old('target_sks') : '' }}" placeholder="Misal: 12" required class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                        @if ($oldForm === 'batch_target') @error('target_sks') <p class="mt-1 text-xs font-medium text-danger">{{ $message }}</p> @enderror @endif
                    </div>

                    <div>
                        <label for="batch-max-sks" class="mb-1 block text-xs font-medium text-muted">Batas Maksimum SKS</label>
                        <input id="batch-max-sks" type="number" step="1" min="0" name="max_sks" value="{{ $oldForm === 'batch_target' ? old('max_sks') : '' }}" placeholder="Misal: 16" required class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                        @if ($oldForm === 'batch_target') @error('max_sks') <p class="mt-1 text-xs font-medium text-danger">{{ $message }}</p> @enderror @endif
                    </div>

                    <div class="mt-2 flex items-center justify-end gap-2 border-t border-border pt-4">
                        <x-button variant="secondary" type="button" @click="batchTargetModalOpen = false">Batal</x-button>
                        <x-button variant="primary" type="submit" icon="check">Terapkan ke Dosen Terpilih</x-button>
                    </div>
                </form>
            </div>
        </div>

        {{-- MODAL 3: IMPOR EXCEL / CSV --}}
        <div x-show="importModalOpen" x-cloak data-modal-name="lecturer-import"
             role="dialog" aria-modal="true" aria-labelledby="lecturer-import-title"
             @keydown.escape.window="if (importModalOpen) importModalOpen = false"
             class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-ink/80 backdrop-blur-sm" @click="importModalOpen = false"></div>
            <div class="relative w-full max-w-md rounded-2xl bg-surface p-6 shadow-2xl">
                <div class="flex items-start justify-between border-b border-border pb-3">
                    <div>
                        <span class="text-xs font-semibold text-primary">Impor Data</span>
                        <h3 id="lecturer-import-title" class="text-lg font-bold text-ink">Impor Dosen dari Spreadsheet</h3>
                    </div>
                    <button type="button" @click="importModalOpen = false" aria-label="Tutup modal impor dosen" class="text-muted hover:text-ink cursor-pointer">
                        <i data-lucide="x" class="size-5" aria-hidden="true"></i>
                    </button>
                </div>

                <form method="POST" action="{{ route('admin.lecturers.import') }}" enctype="multipart/form-data" class="mt-4 flex flex-col gap-4">
                    @csrf
                    <input type="hidden" name="form" value="import">
                    @if ($errors->any() && $oldForm === 'import')
                        <x-alert variant="danger">{{ $errors->first() }}</x-alert>
                    @endif
                    <p class="text-xs text-muted">
                        Format kolom file (Excel / CSV): <strong class="text-ink">Nama, NIP, NIDN, Homebase</strong> (JTIK / Luar JTIK), <strong class="text-ink">Pangkat, Gol., Jabatan</strong>. Baris dicocokkan lewat NIP, NIDN, lalu nama; dosen dengan NIP atau NIDN otomatis berstatus terverifikasi.
                    </p>
                    <div>
                        <label for="lecturer-import-file" class="mb-1 block text-xs font-medium text-muted">Pilih Berkas Excel / CSV</label>
                        <input id="lecturer-import-file" type="file" name="file" accept=".xlsx,.xls,.csv" required class="w-full text-sm">
                        @if ($oldForm === 'import') @error('file') <p class="mt-1 text-xs font-medium text-danger">{{ $message }}</p> @enderror @endif
                    </div>

                    <div class="mt-2 flex items-center justify-end gap-2 border-t border-border pt-4">
                        <x-button variant="secondary" type="button" @click="importModalOpen = false">Batal</x-button>
                        <x-button variant="primary" type="submit" icon="upload">Mulai Impor</x-button>
                    </div>
                </form>
            </div>
        </div>
    </div>

@endsection
