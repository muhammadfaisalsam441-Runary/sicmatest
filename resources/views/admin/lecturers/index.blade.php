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
                           autocorrect="off" autocapitalize="none" spellcheck="false"
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
            <x-bulk-edit-bar form="bulkLecturerForm" label="Target / Maks SKS cepat:">
                <input type="number" min="0" max="60" x-model="fillTargetSks" aria-label="Target SKS cepat" placeholder="Target" class="w-16 rounded border border-border bg-surface px-2 py-1 text-center text-xs font-semibold text-ink focus:border-primary focus:outline-none">
                <span class="text-muted">/</span>
                <input type="number" min="0" max="60" x-model="fillMaxSks" aria-label="Maks SKS cepat" placeholder="Maks" class="w-16 rounded border border-border bg-surface px-2 py-1 text-center text-xs font-semibold text-ink focus:border-primary focus:outline-none">
                <button type="button" @click="applyFillEmptySks()" class="cursor-pointer rounded border border-primary/40 bg-surface px-2.5 py-1 text-xs font-semibold text-primary shadow-xs transition hover:bg-primary hover:text-white">
                    Isi yang Kosong
                </button>
                <button type="button" @click="applyFillAllSks()" class="cursor-pointer rounded border border-primary/40 bg-primary/15 px-2.5 py-1 text-xs font-semibold text-primary shadow-xs transition hover:bg-primary hover:text-white"
                        x-text="selectedIds.length > 0 ? 'Terapkan ke ' + selectedIds.length + ' Dosen Terpilih' : 'Terapkan ke Semua Tampil (' + visibleIds.length + ')'">
                </button>
            </x-bulk-edit-bar>

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
            <x-table-pagination :paginator="$lecturers"
                                form="lecturer-filter-form"
                                :per-page="$perPage"
                                :options="[25, 50, 100]"
                                unit="data dosen"
                                select-id="lecturer-per-page"
                                aria-label="Tampilkan per halaman" />
        </x-card>

        {{-- FLOATING BATCH ACTION BAR --}}
        <x-batch-action-bar unit="dosen">
            {{-- 1. Set Target SKS Modal --}}
            <button type="button" @click="batchTargetModalOpen = true" class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg bg-primary px-3.5 py-1.5 text-xs font-semibold text-white shadow-xs transition hover:bg-primary/90">
                <i data-lucide="target" class="size-3.5" aria-hidden="true"></i>
                Atur Target SKS
            </button>

            {{-- 2. Verifikasi Massal --}}
            <button type="button" @click="submitBatch('verify')" class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg bg-success px-3.5 py-1.5 text-xs font-semibold text-white shadow-xs transition hover:bg-success/90">
                <i data-lucide="user-check" class="size-3.5" aria-hidden="true"></i>
                Verifikasi Sekaligus
            </button>

            {{-- 3. Set Homebase JTIK --}}
            <button type="button" @click="submitBatch('homebase_jtik')" class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg bg-surface/10 px-3 py-1.5 text-xs font-semibold text-surface transition hover:bg-surface/20">
                <i data-lucide="building-2" class="size-3.5" aria-hidden="true"></i>
                Set Homebase JTIK
            </button>

            {{-- 4. Set Homebase Luar JTIK --}}
            <button type="button" @click="submitBatch('homebase_external')" class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg bg-surface/10 px-3 py-1.5 text-xs font-semibold text-surface transition hover:bg-surface/20">
                <i data-lucide="globe" class="size-3.5" aria-hidden="true"></i>
                Set Luar JTIK
            </button>
        </x-batch-action-bar>

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

        @include('admin.lecturers.modals.form-modal')
        @include('admin.lecturers.modals.batch-target-modal')
        @include('admin.lecturers.modals.import-modal')
    </div>

@endsection
