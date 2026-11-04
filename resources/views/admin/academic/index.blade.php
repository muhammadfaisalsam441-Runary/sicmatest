@use('App\Http\Controllers\Admin\AcademicController')
@use('App\Support\Wita')
@extends('layouts.admin')

@section('title', 'Data Akademik')
@section('breadcrumb', 'Data Akademik|'.AcademicController::TABS[$tab])

@section('breadcrumb-actions')
    <x-button variant="secondary" :href="route('admin.dashboard')">Dashboard</x-button>
@endsection

@section('content')
    @php
        $clock = fn (int $minutes) => sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    @endphp

    <div class="flex flex-col gap-6">
        <x-page-header :title="AcademicController::TABS[$tab]" :subtitle="$period ? 'Semester aktif: '.$period->name.'. Data yang belum diketahui dibiarkan kosong dan ditandai Perlu dilengkapi.' : 'Belum ada semester aktif.'">
            <x-slot:actions>
                @if ($tab === 'kelas')
                    <x-button icon="plus" x-data x-on:click="$dispatch('open-modal', 'class-new')">Tambah kelas</x-button>
                @elseif ($tab === 'mata-kuliah')
                    <x-button icon="plus" x-data x-on:click="$dispatch('open-modal', 'course-new')">Tambah mata kuliah</x-button>
                @elseif ($tab === 'penawaran')
                    <x-button icon="plus" x-data x-on:click="$dispatch('open-modal', 'offering-new')">Tambah penawaran</x-button>
                @endif
            </x-slot:actions>
        </x-page-header>

        <x-tabs :items="collect(AcademicController::TABS)->map(fn ($label, $key) => ['label' => $label, 'href' => route('admin.academic.index', ['tab' => $key]), 'active' => $tab === $key])->values()->all()" />

        @if ($tab === 'semester')
            @if ($period)
                <div class="grid grid-cols-[1fr_420px] gap-6">
                    <x-card class="flex flex-col gap-4">
                        <h2 class="text-lg font-semibold">Periode akademik</h2>
                        <form method="POST" action="{{ route('admin.academic.periods.update', $period) }}" class="flex flex-col gap-4">
                            @csrf
                            @method('PUT')
                            <x-field label="Semester & tahun akademik" name="name"><x-input name="name" :value="$period->name" required /></x-field>
                            <div class="grid grid-cols-2 gap-3">
                                <x-field label="Mulai kuliah" name="start_date"><x-input type="date" name="start_date" :value="$period->start_date?->toDateString()" required /></x-field>
                                <x-field label="Akhir kuliah" name="end_date"><x-input type="date" name="end_date" :value="$period->end_date?->toDateString()" required /></x-field>
                                <x-field label="Mulai UTS" name="uts_start"><x-input type="date" name="uts_start" :value="$period->uts_start?->toDateString()" /></x-field>
                                <x-field label="Selesai UTS" name="uts_end"><x-input type="date" name="uts_end" :value="$period->uts_end?->toDateString()" /></x-field>
                                <x-field label="Mulai UAS" name="uas_start"><x-input type="date" name="uas_start" :value="$period->uas_start?->toDateString()" /></x-field>
                                <x-field label="Selesai UAS" name="uas_end"><x-input type="date" name="uas_end" :value="$period->uas_end?->toDateString()" /></x-field>
                            </div>
                            <p class="text-xs text-muted">Zona waktu WITA. Tanggal UTS/UAS menentukan sesi "sebelum UTS" dan "setelah UTS". Perubahan berlaku pada draf berikutnya yang ditetapkan.</p>
                            <div class="flex justify-end"><x-button type="submit">Simpan kalender</x-button></div>
                        </form>
                    </x-card>

                    <x-card class="flex flex-col gap-4">
                        <h2 class="text-lg font-semibold">Hari libur & pengecualian tanggal</h2>
                        <form method="POST" action="{{ route('admin.academic.exceptions.store', $period) }}" class="flex flex-col gap-3">
                            @csrf
                            <div class="grid grid-cols-2 gap-3">
                                <x-field label="Tanggal" name="date"><x-input type="date" name="date" required /></x-field>
                                <x-field label="Sampai (opsional)" name="until"><x-input type="date" name="until" /></x-field>
                            </div>
                            <x-field label="Jenis" name="type"><x-select name="type" :options="['holiday' => 'Hari libur', 'no_class' => 'Tidak ada perkuliahan']" /></x-field>
                            <x-field label="Keterangan" name="note"><x-input name="note" placeholder="Contoh: Dies Natalis" /></x-field>
                            <x-button type="submit" variant="soft" icon="plus">Tambah hari libur</x-button>
                        </form>
                        <div class="flex flex-col divide-y divide-border">
                            @forelse ($period->calendarExceptions as $exception)
                                <div class="flex items-center justify-between py-2 text-sm">
                                    <span><span class="font-medium">{{ Wita::dayName($exception->date) }}, {{ Wita::date($exception->date) }}</span> <span class="text-muted">• {{ $exception->note ?? $exception->type->label() }}</span></span>
                                    <form method="POST" action="{{ route('admin.academic.exceptions.destroy', $exception) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-danger hover:underline" aria-label="Hapus {{ Wita::date($exception->date) }}">Hapus</button>
                                    </form>
                                </div>
                            @empty
                                <p class="py-3 text-sm text-muted">Belum ada hari libur.</p>
                            @endforelse
                        </div>
                    </x-card>
                </div>
            @else
                <x-empty-state title="Belum ada semester aktif" message="Tambahkan semester lewat seeder atau hubungi pengelola sistem." icon="calendar" />
            @endif
        @elseif ($tab === 'aturan' && $period)
            <x-card class="flex flex-col gap-4">
                <div class="flex flex-col gap-1">
                    <h2 class="text-lg font-semibold">Hari &amp; jam kuliah (slot)</h2>
                    <p class="text-sm text-muted">Slot adalah acuan pemeriksaan impor dan susun otomatis. Jam pada jadwal tidak dikoreksi otomatis bila berbeda (mis. 15.40 vs 15.45). Nilai slot adalah data contoh, BELUM DITETAPKAN jurusan.</p>
                </div>
                <form method="POST" action="{{ route('admin.academic.slots.update', $period) }}" class="flex flex-col gap-4">
                    @csrf
                    @method('PUT')
                    <div class="grid grid-cols-4 gap-3">
                        @foreach ($period->timeSlots as $slot)
                            <div class="flex items-center gap-2 rounded-control border border-border px-3 py-2">
                                <span class="w-10 text-sm font-semibold">{{ $slot->label }}</span>
                                <input type="time" name="slots[{{ $slot->id }}][start]" min="{{ config('sicma.operating_hours.start') }}" max="{{ config('sicma.operating_hours.end') }}" value="{{ $clock($slot->start_minute) }}" class="w-full rounded border border-border px-2 py-1 text-sm" aria-label="Mulai slot {{ $slot->label }}">
                                <span class="text-muted">–</span>
                                <input type="time" name="slots[{{ $slot->id }}][end]" min="{{ config('sicma.operating_hours.start') }}" max="{{ config('sicma.operating_hours.end') }}" value="{{ $clock($slot->end_minute) }}" class="w-full rounded border border-border px-2 py-1 text-sm" aria-label="Selesai slot {{ $slot->label }}">
                            </div>
                        @endforeach
                    </div>
                    <div class="flex justify-end"><x-button type="submit">Simpan slot</x-button></div>
                </form>
            </x-card>
        @elseif ($tab === 'kelas')
            <div class="flex flex-col gap-6" x-data="classManager(@js($classesPayload ?? []))" @sicma:navigated.document="batchModalOpen = false">
                {{-- Ringkasan Metrik & Filter Cepat Status --}}
                <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <button type="button" @click="statusFilter = 'semua'"
                            :class="statusFilter === 'semua' ? 'border-primary ring-2 ring-primary/20 bg-primary-soft/10' : 'border-border bg-surface hover:bg-page'"
                            class="rounded-card border p-4 text-left transition flex flex-col gap-1 cursor-pointer">
                        <span class="text-xs font-medium text-muted">Total Kelas</span>
                        <span class="text-2xl font-bold text-ink">{{ number_format($classStats['total'] ?? $classGroups->count()) }}</span>
                        <span class="text-xs text-muted">Semua rombel perkuliahan</span>
                    </button>

                    <button type="button" @click="statusFilter = 'perlu_dilengkapi'"
                            :class="statusFilter === 'perlu_dilengkapi' ? 'border-danger ring-2 ring-danger/20 bg-danger-soft/20' : 'border-border bg-surface hover:bg-page'"
                            class="rounded-card border p-4 text-left transition flex flex-col gap-1 cursor-pointer">
                        <span class="text-xs font-medium text-danger">⚠️ Perlu Dilengkapi</span>
                        <span class="text-2xl font-bold text-danger">{{ number_format($classStats['needs_attention'] ?? 0) }}</span>
                        <span class="text-xs text-danger/80">Jumlah mahasiswa belum diisi</span>
                    </button>

                    <button type="button" @click="statusFilter = 'lengkap'"
                            :class="statusFilter === 'lengkap' ? 'border-success ring-2 ring-success/20 bg-success-soft/20' : 'border-border bg-surface hover:bg-page'"
                            class="rounded-card border p-4 text-left transition flex flex-col gap-1 cursor-pointer">
                        <span class="text-xs font-medium text-success">✅ Data Lengkap</span>
                        <span class="text-2xl font-bold text-success">{{ number_format($classStats['complete'] ?? 0) }}</span>
                        <span class="text-xs text-success/80">Siap untuk penjadwalan</span>
                    </button>

                    <button type="button" @click="statusFilter = 'arsip'"
                            :class="statusFilter === 'arsip' ? 'border-muted ring-2 ring-muted/20 bg-page' : 'border-border bg-surface hover:bg-page'"
                            class="rounded-card border p-4 text-left transition flex flex-col gap-1 cursor-pointer">
                        <span class="text-xs font-medium text-muted">Nonaktif / Arsip</span>
                        <span class="text-2xl font-bold text-ink">{{ number_format($classStats['inactive'] ?? 0) }}</span>
                        <span class="text-xs text-muted">Tidak dijadwalkan</span>
                    </button>
                </div>

                {{-- Toolbar Pencarian, Filter Prodi, dan Tombol Mode Edit Massal --}}
                <x-card class="flex flex-col gap-4">
                    <div class="flex flex-wrap items-center justify-between gap-4 border-b border-border pb-4">
                        {{-- Filter Tabs Status --}}
                        <div class="flex flex-wrap items-center gap-2">
                            <button type="button" @click="statusFilter = 'semua'"
                                    :class="statusFilter === 'semua' ? 'bg-primary text-white' : 'bg-page text-muted hover:text-ink hover:bg-border'"
                                    class="rounded-full px-3.5 py-1.5 text-xs font-semibold transition cursor-pointer">
                                Semua ({{ $classGroups->count() }})
                            </button>
                            <button type="button" @click="statusFilter = 'perlu_dilengkapi'"
                                    :class="statusFilter === 'perlu_dilengkapi' ? 'bg-danger text-white' : 'bg-danger-soft/60 text-danger hover:bg-danger-soft'"
                                    class="rounded-full px-3.5 py-1.5 text-xs font-semibold transition cursor-pointer">
                                ⚠️ Perlu Dilengkapi ({{ $classStats['needs_attention'] ?? 0 }})
                            </button>
                            <button type="button" @click="statusFilter = 'lengkap'"
                                    :class="statusFilter === 'lengkap' ? 'bg-success text-white' : 'bg-success-soft/60 text-success hover:bg-success-soft'"
                                    class="rounded-full px-3.5 py-1.5 text-xs font-semibold transition cursor-pointer">
                                ✅ Lengkap ({{ $classStats['complete'] ?? 0 }})
                            </button>
                            <button type="button" @click="statusFilter = 'arsip'"
                                    :class="statusFilter === 'arsip' ? 'bg-primary text-white' : 'bg-page text-muted hover:text-ink hover:bg-border'"
                                    class="rounded-full px-3.5 py-1.5 text-xs font-semibold transition cursor-pointer">
                                Arsip ({{ $classStats['inactive'] ?? 0 }})
                            </button>
                        </div>

                        {{-- Tombol Toggle Edit Massal di Tabel --}}
                        <div class="flex items-center gap-2">
                            <button type="button" @click="editMode = !editMode"
                                    :class="editMode ? 'bg-amber-500 text-white ring-2 ring-amber-400/30' : 'bg-page text-ink border border-border hover:bg-primary-soft hover:text-primary'"
                                    class="inline-flex items-center gap-1.5 rounded-control px-3.5 py-2 text-xs font-bold transition shadow-xs cursor-pointer">
                                <i data-lucide="pencil" class="size-3.5"></i>
                                <span x-text="editMode ? 'Tutup Edit Massal' : '⚡ Edit Massal di Tabel'"></span>
                            </button>
                            <x-button icon="plus" x-data x-on:click="$dispatch('open-modal', 'class-new')" class="py-2! text-xs!">Tambah kelas</x-button>
                        </div>
                    </div>

                    {{-- Form Pencarian & Filter Prodi/Angkatan --}}
                    <div class="grid grid-cols-1 items-end gap-3 sm:grid-cols-12">
                        <div class="sm:col-span-6">
                            <label class="mb-1 block text-xs font-medium text-muted">Cari Nama Kelas / Kode / Prodi</label>
                            <div class="relative">
                                <i data-lucide="search" class="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted"></i>
                                <input type="text" x-model="search" placeholder="Ketik nama kelas (misal: PTIK A, Tekom C)..."
                                       class="w-full rounded-control border border-border bg-surface pl-9 pr-3 py-2 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                            </div>
                        </div>

                        <div class="sm:col-span-4">
                            <label class="mb-1 block text-xs font-medium text-muted">Filter Program Studi</label>
                            <select x-model="prodiFilter" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                                <option value="">Semua Program Studi</option>
                                @foreach ($distinctPrograms ?? [] as $dp)
                                    <option value="{{ $dp }}">{{ $dp }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="sm:col-span-2">
                            <button type="button" @click="search = ''; prodiFilter = ''; statusFilter = 'semua'"
                                    x-show="search !== '' || prodiFilter !== '' || statusFilter !== 'semua'"
                                    class="inline-flex h-[38px] w-full items-center justify-center gap-1.5 rounded-control border border-border px-3 text-xs font-semibold text-muted hover:text-ink hover:bg-page transition cursor-pointer">
                                <i data-lucide="rotate-ccw" class="size-3.5"></i>
                                <span>Reset Filter</span>
                            </button>
                        </div>
                    </div>

                    {{-- Bar Bantuan Cepat saat Mode Edit Massal Aktif --}}
                    <div x-show="editMode" x-cloak class="rounded-xl border border-primary/30 bg-primary-soft/30 p-3.5 flex flex-wrap items-center justify-between gap-3 animate-in fade-in duration-200">
                        <div class="flex flex-wrap items-center gap-2 text-xs">
                            <span class="font-bold text-primary flex items-center gap-1.5">
                                <i data-lucide="pencil" class="size-4"></i> Mode Edit Massal Aktif
                            </span>
                            <span class="text-muted">| Isi langsung angka peserta di tabel, atau isi serentak kelas yang masih kosong:</span>
                            <div class="flex items-center gap-1.5 ml-1">
                                <input type="number" min="1" max="500" x-model="fillEmptyValue" class="w-16 rounded border border-border bg-surface px-2 py-1 text-center text-xs font-semibold text-ink focus:border-primary focus:outline-none">
                                <button type="button" @click="applyFillEmpty()" class="rounded bg-surface px-2.5 py-1 text-xs font-semibold text-primary border border-primary/40 hover:bg-primary hover:text-white transition shadow-xs cursor-pointer">
                                    Terapkan ke Yang Kosong
                                </button>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <button type="button" @click="editMode = false" class="rounded-control border border-border bg-surface px-3 py-1.5 text-xs font-semibold text-muted hover:text-ink transition cursor-pointer">
                                Batal
                            </button>
                            <button type="submit" form="bulkClassForm" class="inline-flex items-center gap-1.5 rounded-control bg-primary px-4 py-1.5 text-xs font-bold text-white hover:bg-primary/90 transition shadow-sm cursor-pointer">
                                <i data-lucide="check" class="size-4"></i>
                                Simpan Semua Perubahan
                            </button>
                        </div>
                    </div>

                    {{-- TABEL KELAS --}}
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="border-b border-border bg-page text-xs font-medium text-muted">
                                <tr>
                                    <th class="w-10 px-4 py-3 text-center">
                                        <input type="checkbox"
                                               @change="toggleSelectAll($event.target.checked)"
                                               :checked="isAllSelected()"
                                               class="size-4 rounded border-border text-primary focus:ring-primary cursor-pointer"
                                               title="Pilih semua yang tampil">
                                    </th>
                                    <th class="px-4 py-3 font-semibold">Nama Kelas & Kode</th>
                                    <th class="px-4 py-3 font-semibold">Program Studi</th>
                                    <th class="px-4 py-3 font-semibold text-center">Angkatan</th>
                                    <th class="px-4 py-3 font-semibold text-center">Jadwal</th>
                                    <th class="px-4 py-3 font-semibold text-center">Jumlah Mahasiswa</th>
                                    <th class="px-4 py-3 font-semibold text-center">Status</th>
                                    <th class="px-4 py-3 text-right font-semibold">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                @forelse ($classGroups as $index => $classGroup)
                                    @php
                                        $searchStr = mb_strtolower($classGroup->name.' '.$classGroup->study_program.' '.$classGroup->cohort_year.' '.$classGroup->code);
                                        $needsAttention = $classGroup->student_count === null;
                                        $statusKey = ! $classGroup->is_active ? 'arsip' : ($needsAttention ? 'perlu_dilengkapi' : 'lengkap');
                                    @endphp
                                    <tr data-id="{{ $classGroup->id }}"
                                        data-search="{{ $searchStr }}"
                                        data-status="{{ $statusKey }}"
                                        data-prodi="{{ $classGroup->study_program }}"
                                        x-show="
                                            (!search.trim() || $el.dataset.search.includes(search.trim().toLowerCase())) &&
                                            (statusFilter === 'semua' || $el.dataset.status === statusFilter) &&
                                            (!prodiFilter || $el.dataset.prodi === prodiFilter)
                                        "
                                        class="transition hover:bg-primary-soft/20"
                                        :class="{ 'bg-primary-soft/30': selectedIds.map(Number).includes({{ $classGroup->id }}) }">

                                        {{-- Checkbox --}}
                                        <td class="px-4 py-3 text-center">
                                            <input type="checkbox"
                                                   value="{{ $classGroup->id }}"
                                                   x-model.number="selectedIds"
                                                   class="size-4 rounded border-border text-primary focus:ring-primary cursor-pointer">
                                        </td>

                                        {{-- Nama Kelas & Kode --}}
                                        <td class="px-4 py-3">
                                            <div x-show="!editMode" class="flex flex-col">
                                                <span class="font-semibold text-ink">{{ $classGroup->name }}</span>
                                                <span class="font-mono text-[11px] text-muted">{{ $classGroup->code }}</span>
                                            </div>
                                            <div x-show="editMode" x-cloak class="flex flex-col gap-1">
                                                <input type="text"
                                                       form="bulkClassForm"
                                                       name="classes[{{ $index }}][name]"
                                                       :disabled="!editMode"
                                                       x-model="classesData[{{ $classGroup->id }}].name"
                                                       required
                                                       class="w-36 rounded-control border border-border bg-surface px-2.5 py-1 text-sm font-semibold text-ink focus:border-primary focus:outline-none">
                                                <span class="font-mono text-[10px] text-muted">{{ $classGroup->code }}</span>
                                            </div>
                                        </td>

                                        {{-- Program Studi --}}
                                        <td class="px-4 py-3">
                                            <span x-show="!editMode" class="text-xs text-muted">{{ $classGroup->study_program }}</span>
                                            <div x-show="editMode" x-cloak>
                                                <input type="text"
                                                       form="bulkClassForm"
                                                       name="classes[{{ $index }}][study_program]"
                                                       :disabled="!editMode"
                                                       x-model="classesData[{{ $classGroup->id }}].study_program"
                                                       list="prodi-options-grid"
                                                       required
                                                       class="w-48 rounded-control border border-border bg-surface px-2.5 py-1 text-xs text-ink focus:border-primary focus:outline-none">
                                            </div>
                                        </td>

                                        {{-- Angkatan --}}
                                        <td class="px-4 py-3 text-center">
                                            <span x-show="!editMode" class="text-sm font-medium text-ink">{{ $classGroup->cohort_year }}</span>
                                            <div x-show="editMode" x-cloak class="flex justify-center">
                                                <input type="number" min="2000" max="2100"
                                                       form="bulkClassForm"
                                                       name="classes[{{ $index }}][cohort_year]"
                                                       :disabled="!editMode"
                                                       x-model="classesData[{{ $classGroup->id }}].cohort_year"
                                                       required
                                                       class="w-20 rounded-control border border-border bg-surface px-2 py-1 text-center text-sm text-ink focus:border-primary focus:outline-none">
                                            </div>
                                        </td>

                                        {{-- Sesi Jadwal --}}
                                        <td class="px-4 py-3 text-center">
                                            <span class="inline-flex items-center rounded-md bg-page px-2 py-0.5 text-xs font-medium text-muted ring-1 ring-border">
                                                {{ $classGroup->schedule_sessions_count }} sesi
                                            </span>
                                        </td>

                                        {{-- Jumlah Mahasiswa (Peserta) --}}
                                        <td class="px-4 py-3 text-center">
                                            <div x-show="!editMode">
                                                @if ($classGroup->student_count)
                                                    <span class="inline-flex items-center gap-1 rounded-full bg-page px-2.5 py-0.5 text-xs font-semibold text-ink border border-border">
                                                        <i data-lucide="users" class="size-3 text-muted"></i>
                                                        {{ $classGroup->student_count }} mhs
                                                    </span>
                                                @else
                                                    <button type="button" @click="editMode = true" class="inline-flex items-center gap-1 text-xs font-semibold text-danger hover:underline cursor-pointer" title="Klik untuk edit cepat">
                                                        ⚠️ Belum diisi
                                                    </button>
                                                @endif
                                            </div>
                                            <div x-show="editMode" x-cloak class="flex justify-center">
                                                <input type="number" min="1" max="500"
                                                       form="bulkClassForm"
                                                       name="classes[{{ $index }}][student_count]"
                                                       :disabled="!editMode"
                                                       x-model="classesData[{{ $classGroup->id }}].student_count"
                                                       placeholder="0 mhs"
                                                       class="w-24 rounded-control border border-primary/40 bg-surface px-2.5 py-1 text-center text-sm font-bold text-ink focus:border-primary focus:ring-1 focus:ring-primary focus:outline-none shadow-xs">
                                            </div>
                                        </td>

                                        {{-- Status Data / Aktif --}}
                                        <td class="px-4 py-3 text-center">
                                            <div x-show="!editMode">
                                                @if (! $classGroup->is_active)
                                                    <x-status-badge>Arsip</x-status-badge>
                                                @elseif ($classGroup->student_count === null)
                                                    <x-status-badge variant="warning">Perlu dilengkapi</x-status-badge>
                                                @else
                                                    <x-status-badge variant="success">Lengkap</x-status-badge>
                                                @endif
                                            </div>
                                            <div x-show="editMode" x-cloak class="flex items-center justify-center gap-1.5">
                                                <input type="hidden" form="bulkClassForm" name="classes[{{ $index }}][id]" value="{{ $classGroup->id }}" :disabled="!editMode">
                                                <input type="hidden" form="bulkClassForm" name="classes[{{ $index }}][is_active]" value="0" :disabled="!editMode">
                                                <label class="inline-flex items-center gap-1 text-xs cursor-pointer">
                                                    <input type="checkbox"
                                                           form="bulkClassForm"
                                                           name="classes[{{ $index }}][is_active]"
                                                           value="1"
                                                           :disabled="!editMode"
                                                           x-model="classesData[{{ $classGroup->id }}].is_active"
                                                           class="size-4 accent-primary rounded cursor-pointer">
                                                    <span class="text-[11px]" x-text="classesData[{{ $classGroup->id }}].is_active ? 'Aktif' : 'Nonaktif'"></span>
                                                </label>
                                            </div>
                                        </td>

                                        {{-- Aksi --}}
                                        <td class="px-4 py-3 text-right">
                                            <div class="flex items-center justify-end gap-2">
                                                <button type="button" class="font-semibold text-xs text-primary hover:underline cursor-pointer" x-data x-on:click="$dispatch('open-modal', 'class-{{ $classGroup->id }}')">
                                                    Edit
                                                </button>
                                                <form method="POST" action="{{ route('admin.academic.classes.destroy', $classGroup) }}" class="inline">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="text-xs text-danger hover:underline cursor-pointer" onclick="return confirm('Hapus kelas {{ $classGroup->name }}?')">
                                                        Hapus
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="8" class="py-12 text-center text-muted">
                                            <i data-lucide="users" class="mx-auto size-8 text-muted/60 mb-2"></i>
                                            <p class="font-medium text-ink">Belum ada data kelas.</p>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    {{-- Footer jika dalam editMode --}}
                    <div x-show="editMode" x-cloak class="flex items-center justify-between border-t border-border pt-4">
                        <span class="text-xs text-muted">Simpan setelah selesai mengubah data kelas di atas.</span>
                        <div class="flex gap-2">
                            <button type="button" @click="editMode = false" class="rounded-control border border-border bg-surface px-4 py-2 text-xs font-semibold text-muted hover:text-ink transition cursor-pointer">
                                Batal
                            </button>
                            <button type="submit" form="bulkClassForm" class="inline-flex items-center gap-1.5 rounded-control bg-primary px-5 py-2 text-xs font-bold text-white hover:bg-primary/90 transition shadow-sm cursor-pointer">
                                <i data-lucide="check" class="size-4"></i>
                                Simpan Semua Perubahan
                            </button>
                        </div>
                    </div>
                </x-card>

                {{-- FLOATING BATCH ACTION BAR --}}
                <div x-show="selectedIds.length > 0 && !editMode"
                     x-cloak
                     x-transition:enter="transition ease-out duration-200"
                     x-transition:enter-start="opacity-0 translate-y-4"
                     x-transition:enter-end="opacity-100 translate-y-0"
                     x-transition:leave="transition ease-in duration-150"
                     x-transition:leave-start="opacity-100 translate-y-0"
                     x-transition:leave-end="opacity-0 translate-y-4"
                     class="fixed bottom-6 left-1/2 z-40 flex -translate-x-1/2 flex-wrap items-center gap-3 rounded-xl border border-primary/30 bg-ink px-6 py-3.5 text-surface shadow-2xl">
                    <div class="flex items-center gap-2">
                        <i data-lucide="check-circle" class="size-5 text-success"></i>
                        <span class="text-sm font-semibold"><span x-text="selectedIds.length"></span> kelas dipilih</span>
                    </div>
                    <div class="h-4 w-px bg-surface/20"></div>

                    {{-- 1. Atur Mahasiswa Modal --}}
                    <button type="button" @click="openBatchModal('set_student_count', 'Atur Jumlah Mahasiswa')" class="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3 py-1.5 text-xs font-semibold text-white shadow-xs hover:bg-primary/90 transition cursor-pointer">
                        <i data-lucide="users" class="size-3.5"></i>
                        Set Mahasiswa
                    </button>

                    {{-- 2. Set Angkatan Modal --}}
                    <button type="button" @click="openBatchModal('set_cohort_year', 'Set Tahun Angkatan')" class="inline-flex items-center gap-1.5 rounded-lg bg-surface/10 px-3 py-1.5 text-xs font-semibold text-surface hover:bg-surface/20 transition cursor-pointer">
                        <i data-lucide="calendar" class="size-3.5"></i>
                        Set Angkatan
                    </button>

                    {{-- 3. Set Prodi Modal --}}
                    <button type="button" @click="openBatchModal('set_study_program', 'Set Program Studi')" class="inline-flex items-center gap-1.5 rounded-lg bg-surface/10 px-3 py-1.5 text-xs font-semibold text-surface hover:bg-surface/20 transition cursor-pointer">
                        <i data-lucide="building-2" class="size-3.5"></i>
                        Set Prodi
                    </button>

                    {{-- 4. Aktifkan --}}
                    <button type="button" @click="submitBatch('activate')" class="inline-flex items-center gap-1.5 rounded-lg bg-surface/10 px-3 py-1.5 text-xs font-semibold text-surface hover:bg-surface/20 transition cursor-pointer">
                        <i data-lucide="check" class="size-3.5"></i>
                        Aktifkan
                    </button>

                    {{-- 5. Arsipkan --}}
                    <button type="button" @click="submitBatch('archive')" class="inline-flex items-center gap-1.5 rounded-lg bg-danger/80 px-3 py-1.5 text-xs font-semibold text-white hover:bg-danger transition cursor-pointer">
                        <i data-lucide="lock" class="size-3.5"></i>
                        Arsipkan
                    </button>

                    <button type="button" @click="selectedIds = []" class="text-xs text-surface/70 hover:text-white transition ml-2 cursor-pointer">
                        Batal
                    </button>
                </div>

                {{-- FORM TERSEMBUNYI UNTUK BATCH ACTION --}}
                <form x-ref="batchForm" method="POST" action="{{ route('admin.academic.classes.batch') }}" class="hidden">
                    @csrf
                    <input type="hidden" name="action" x-model="batchAction">
                    <input type="hidden" name="student_count" x-model="batchValue">
                    <input type="hidden" name="cohort_year" x-model="batchValue">
                    <input type="hidden" name="study_program" x-model="batchValue">
                    <template x-for="id in selectedIds" :key="id">
                        <input type="hidden" name="class_ids[]" :value="id">
                    </template>
                </form>

                {{-- FORM TERSEMBUNYI UNTUK BULK EDIT GRID --}}
                <form id="bulkClassForm" method="POST" action="{{ route('admin.academic.classes.bulk-update') }}" class="hidden">
                    @csrf
                    @method('PUT')
                </form>

                {{-- MODAL BATCH ATUR NILAI --}}
                <div x-show="batchModalOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
                    <div class="absolute inset-0 bg-ink/80 backdrop-blur-sm" @click="batchModalOpen = false"></div>
                    <div class="relative w-full max-w-md rounded-2xl bg-surface p-6 shadow-2xl">
                        <div class="flex items-start justify-between border-b border-border pb-3">
                            <div>
                                <span class="text-xs font-semibold text-primary">Penyesuaian Massal</span>
                                <h3 class="text-lg font-bold text-ink" x-text="batchLabel + ' (' + selectedIds.length + ' Kelas)'"></h3>
                            </div>
                            <button type="button" @click="batchModalOpen = false" class="text-muted hover:text-ink">
                                <i data-lucide="x" class="size-5"></i>
                            </button>
                        </div>

                        <div class="mt-4 flex flex-col gap-4">
                            <template x-if="batchAction === 'set_student_count'">
                                <div>
                                    <label class="mb-1 block text-xs font-medium text-muted">Jumlah Mahasiswa per Kelas</label>
                                    <input type="number" min="1" max="500" x-model="batchValue" placeholder="Misal: 40" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                                    <p class="mt-1 text-[11px] text-muted">Akan diterapkan ke seluruh kelas yang Anda centang.</p>
                                </div>
                            </template>

                            <template x-if="batchAction === 'set_cohort_year'">
                                <div>
                                    <label class="mb-1 block text-xs font-medium text-muted">Tahun Angkatan</label>
                                    <input type="number" min="2000" max="2100" x-model="batchValue" placeholder="Misal: 2026" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                                </div>
                            </template>

                            <template x-if="batchAction === 'set_study_program'">
                                <div>
                                    <label class="mb-1 block text-xs font-medium text-muted">Nama Program Studi</label>
                                    <input type="text" x-model="batchValue" list="prodi-options-batch" placeholder="Misal: Pendidikan Teknik Informatika dan Komputer" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                                </div>
                            </template>

                            <div class="mt-2 flex items-center justify-end gap-2 border-t border-border pt-4">
                                <x-button variant="secondary" type="button" @click="batchModalOpen = false">Batal</x-button>
                                <x-button variant="primary" type="button" icon="check" @click="submitBatch(); batchModalOpen = false">Terapkan Perubahan</x-button>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Datalist options untuk prodi --}}
                <datalist id="prodi-options-grid">
                    @foreach ($distinctPrograms ?? [] as $dp)
                        <option value="{{ $dp }}"></option>
                    @endforeach
                </datalist>
                <datalist id="prodi-options-batch">
                    @foreach ($distinctPrograms ?? [] as $dp)
                        <option value="{{ $dp }}"></option>
                    @endforeach
                </datalist>
            </div>
        @elseif ($tab === 'mata-kuliah')
            <x-card class="flex flex-col gap-4" x-data="{ search: '' }">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="text-sm text-muted">Kode kurikulum dipertahankan. Nama mirip dengan kode berbeda tidak digabung otomatis.</p>
                    <div class="relative w-full sm:w-72">
                        <i data-lucide="search" class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted"></i>
                        <input type="search" x-model="search" placeholder="Cari kode atau nama mata kuliah..." aria-label="Cari mata kuliah"
                               class="w-full rounded-control border border-border bg-surface py-2 pl-9 pr-3 text-sm text-ink focus:border-primary focus:outline-none">
                    </div>
                </div>
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-border text-xs text-muted">
                        <tr><th class="py-3 pr-4 font-medium">Kode MK</th><th class="py-3 pr-4 font-medium">Mata kuliah</th><th class="py-3 pr-4 font-medium">SKS</th><th class="py-3 pr-4 font-medium">Kurikulum</th><th class="py-3 pr-4 font-medium">Penawaran</th><th class="py-3 font-medium">Aksi</th></tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @foreach ($courses as $course)
                            <tr data-search="{{ mb_strtolower($course->code.' '.$course->name.' '.($course->curriculum ?? '')) }}"
                                x-show="!search.trim() || $el.dataset.search.includes(search.trim().toLowerCase())">
                                <td class="py-3 pr-4 font-mono text-xs">{{ $course->code }}</td>
                                <td class="py-3 pr-4 font-semibold">{{ $course->name }}</td>
                                <td class="py-3 pr-4">{{ $course->sks }}</td>
                                <td class="py-3 pr-4 text-muted">{{ $course->curriculum ?? '—' }}</td>
                                <td class="py-3 pr-4">{{ $course->offerings_count }} kelas</td>
                                <td class="py-3">
                                    <button type="button" class="font-semibold text-primary hover:underline" x-data x-on:click="$dispatch('open-modal', 'course-{{ $course->id }}')">Edit</button>
                                    <form method="POST" action="{{ route('admin.academic.courses.destroy', $course) }}" class="ml-3 inline">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-danger hover:underline">Hapus</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-card>
        @elseif ($tab === 'penawaran')
            <x-card class="flex flex-col gap-4" x-data="{ search: '' }">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="text-sm text-muted">Daftar kelas dan mata kuliah yang ditawarkan pada semester aktif ({{ number_format($offerings->count()) }} penawaran).</p>
                    <div class="relative w-full sm:w-80">
                        <i data-lucide="search" class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted"></i>
                        <input type="search" x-model="search" placeholder="Cari kelas atau mata kuliah..." aria-label="Cari penawaran"
                               class="w-full rounded-control border border-border bg-surface py-2 pl-9 pr-3 text-sm text-ink focus:border-primary focus:outline-none">
                    </div>
                </div>
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-border text-xs text-muted">
                        <tr><th class="py-3 pr-4 font-medium">Kelas</th><th class="py-3 pr-4 font-medium">Mata kuliah</th><th class="py-3 pr-4 font-medium">SKS</th><th class="py-3 font-medium">Aksi</th></tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($offerings as $offering)
                            @php
                                $offeringSearchText = mb_strtolower($offering->classGroup->name.' '.$offering->course->code.' '.$offering->course->name);
                            @endphp
                            <tr data-search="{{ $offeringSearchText }}"
                                x-show="!search.trim() || $el.dataset.search.includes(search.trim().toLowerCase())">
                                <td class="py-3 pr-4 font-semibold">{{ $offering->classGroup->name }}</td>
                                <td class="py-3 pr-4">{{ $offering->course->name }}</td>
                                <td class="py-3 pr-4">{{ $offering->course->sks }} SKS</td>
                                <td class="py-3">
                                    <a href="{{ route('admin.teaching.index', ['penawaran' => $offering->id]) }}" class="font-semibold text-primary hover:underline">Bagi SKS Dosen ↗</a>
                                    <form method="POST" action="{{ route('admin.academic.offerings.destroy', $offering) }}" class="ml-3 inline">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-danger hover:underline">Hapus</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-10 text-center text-muted">Belum ada penawaran perkuliahan.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </x-card>
        @endif
    </div>
@endsection

@push('dialogs')
    @if ($tab === 'kelas')
        @foreach ([null, ...$classGroups] as $classGroup)
            <x-modal :name="$classGroup ? 'class-'.$classGroup->id : 'class-new'" :title="$classGroup ? 'Edit kelas' : 'Tambah kelas'" width="max-w-[480px]">
                <form method="POST" action="{{ $classGroup ? route('admin.academic.classes.update', $classGroup) : route('admin.academic.classes.store') }}" class="flex flex-col gap-4">
                    @csrf
                    @if ($classGroup) @method('PUT') @endif
                    <x-field label="Nama kelas" name="name"><x-input name="name" :id="'name-'.($classGroup?->id ?? 'new')" :value="$classGroup?->name" placeholder="Contoh: Tekom C" required /></x-field>
                    <x-field label="Prodi" name="study_program"><x-input name="study_program" :id="'program-'.($classGroup?->id ?? 'new')" :value="$classGroup?->study_program" placeholder="Teknik Komputer" required /></x-field>
                    <div class="grid grid-cols-2 gap-3">
                        <x-field label="Angkatan" name="cohort_year"><x-input type="number" name="cohort_year" :id="'cohort-'.($classGroup?->id ?? 'new')" :value="$classGroup?->cohort_year ?? now()->year" required /></x-field>
                        <x-field label="Jumlah mahasiswa" name="student_count"><x-input type="number" name="student_count" :id="'students-'.($classGroup?->id ?? 'new')" :value="$classGroup?->student_count" placeholder="Belum diisi" /></x-field>
                    </div>
                    @if ($classGroup)
                        <input type="hidden" name="code" value="{{ $classGroup->code }}">
                        <label class="flex items-center gap-2 text-sm"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" class="size-4 accent-primary" @checked($classGroup->is_active)> Kelas aktif</label>
                    @endif
                    <div class="flex justify-end gap-3">
                        <x-button variant="secondary" x-on:click="$dispatch('close-modal', '{{ $classGroup ? 'class-'.$classGroup->id : 'class-new' }}')">Batal</x-button>
                        <x-button type="submit">Simpan</x-button>
                    </div>
                </form>
            </x-modal>
        @endforeach
    @elseif ($tab === 'mata-kuliah')
        @foreach ([null, ...$courses] as $course)
            <x-modal :name="$course ? 'course-'.$course->id : 'course-new'" :title="$course ? 'Edit mata kuliah' : 'Tambah mata kuliah'" width="max-w-[480px]">
                <form method="POST" action="{{ $course ? route('admin.academic.courses.update', $course) : route('admin.academic.courses.store') }}" class="flex flex-col gap-4">
                    @csrf
                    @if ($course) @method('PUT') @endif
                    <div class="grid grid-cols-[1fr_100px] gap-3">
                        <x-field label="Kode MK" name="code"><x-input name="code" :id="'code-'.($course?->id ?? 'new')" :value="$course?->code" required /></x-field>
                        <x-field label="SKS" name="sks"><x-input type="number" name="sks" :id="'sks-'.($course?->id ?? 'new')" :value="$course?->sks" min="1" required /></x-field>
                    </div>
                    <x-field label="Nama mata kuliah" name="name"><x-input name="name" :id="'course-name-'.($course?->id ?? 'new')" :value="$course?->name" required /></x-field>
                    <x-field label="Kurikulum" name="curriculum"><x-input name="curriculum" :id="'curriculum-'.($course?->id ?? 'new')" :value="$course?->curriculum" placeholder="Contoh: Kurikulum 2025" /></x-field>
                    <div class="flex justify-end gap-3">
                        <x-button variant="secondary" x-on:click="$dispatch('close-modal', '{{ $course ? 'course-'.$course->id : 'course-new' }}')">Batal</x-button>
                        <x-button type="submit">Simpan</x-button>
                    </div>
                </form>
            </x-modal>
        @endforeach
    @elseif ($tab === 'penawaran' && $period)
        <x-modal name="offering-new" title="Tambah penawaran perkuliahan" width="max-w-[480px]">
            <form method="POST" action="{{ route('admin.academic.offerings.store') }}" class="flex flex-col gap-4">
                @csrf
                <input type="hidden" name="period_id" value="{{ $period->id }}">
                <x-field label="Mata kuliah" name="course_id"><x-select name="course_id" :options="$courseOptions" placeholder="Pilih mata kuliah" required /></x-field>
                <x-field label="Kelas" name="class_group_id"><x-select name="class_group_id" :options="$classOptions" placeholder="Pilih kelas" required /></x-field>
                <div class="flex justify-end gap-3">
                    <x-button variant="secondary" x-on:click="$dispatch('close-modal', 'offering-new')">Batal</x-button>
                    <x-button type="submit">Simpan</x-button>
                </div>
            </form>
        </x-modal>
    @endif
@endpush
