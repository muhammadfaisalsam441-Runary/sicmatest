@use('App\Enums\RoomType')
@use('App\Support\Wita')
@extends('layouts.admin')

@section('title', 'Kelola Ruang')
@section('breadcrumb', 'Kelola Ruang')

@section('breadcrumb-actions')
    <x-button variant="secondary" :href="route('admin.dashboard')">Dashboard</x-button>
@endsection

@section('content')
    @php
        $roomManagerDefaults = [
            'formModalOpen' => ($errors->any() && ! in_array(old('form'), ['closure', 'batch_capacity'], true)) || request()->boolean('tambah'),
            'batchCapacityModalOpen' => $errors->any() && old('form') === 'batch_capacity',
            'batchCapacity' => old('form') === 'batch_capacity' ? old('capacity', '') : '',
            'selectedIds' => old('form') === 'batch_capacity' ? old('room_ids', []) : [],
            'closureModalOpen' => (bool) (session('closure_impact') || old('form') === 'closure'),
            'closureRoomId' => old('room_id', ''),
            'closureStart' => old('start_at', now()->format('Y-m-d\TH:i')),
            'closureEnd' => old('end_at', now()->addHours(4)->format('Y-m-d\TH:i')),
            'closureReason' => old('reason', ''),
            'name' => old('name', ''),
            'code' => old('code', ''),
            'type' => old('type', 'classroom'),
            'capacity' => old('capacity', ''),
            'floor' => old('floor', ''),
            'building' => old('building', ''),
            'facilities' => old('facilities', ''),
        ];
    @endphp
    <div class="flex flex-col gap-6"
         x-data="roomManager(@js($rooms->pluck('id')->all()), @js($roomManagerDefaults), @js($roomsPayload ?? []))"
         :data-editing="(editMode || formModalOpen || closureModalOpen || deleteModalOpen || batchFacilityModalOpen || batchCapacityModalOpen || selectedIds.length > 0) ? 'true' : null"
         @sicma:navigated.document="formModalOpen = false; closureModalOpen = false; deleteModalOpen = false; batchFacilityModalOpen = false; batchCapacityModalOpen = false; editMode = false">
        <x-page-header title="Data ruang"
                       :subtitle="$totalRooms.' ruang terdaftar'.($periodName ? ' dari jadwal '.$periodName : '').'. Kelola profil ruangan, kapasitas kursi, fasilitas, lokasi gedung, serta status penutupan ruang.'">
            <x-slot:actions>
                <x-button variant="secondary" icon="lock" @click="openClosureModal(null)">Tutup ruang</x-button>
                <x-button icon="plus" @click="openCreateModal()">Tambah ruang</x-button>
            </x-slot:actions>
        </x-page-header>




        {{-- Toolbar Filter & Pencarian Cepat --}}
        <x-card class="flex flex-col gap-4 p-5 shadow-xs">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border pb-3.5">
                {{-- Tabs Filter Status --}}
                <div class="flex flex-wrap items-center gap-1.5">
                    <a href="{{ route('admin.rooms.index', array_filter(['filter' => null, 'q' => $keyword, 'gedung' => $buildingFilter, 'per_page' => $perPage])) }}"
                       @class([
                           'inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition',
                           'bg-primary text-white shadow-2xs' => $filter === 'semua',
                           'bg-page text-muted hover:text-ink hover:bg-border' => $filter !== 'semua',
                       ])>
                        <span>Semua</span>
                        <span class="rounded-full px-1.5 py-0.5 text-[10px] {{ $filter === 'semua' ? 'bg-white/20 text-white' : 'bg-surface text-muted' }}">{{ $totalRooms }}</span>
                    </a>
                    <a href="{{ route('admin.rooms.index', array_filter(['filter' => 'verifikasi', 'q' => $keyword, 'gedung' => $buildingFilter, 'per_page' => $perPage])) }}"
                       @class([
                           'inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition',
                           'bg-warning text-white shadow-2xs' => $filter === 'verifikasi',
                           'bg-warning-soft/80 text-warning-ink border border-warning/30 hover:bg-warning-soft' => $filter !== 'verifikasi',
                       ])>
                        <i data-lucide="triangle-alert" class="size-3.5"></i>
                        <span>Perlu Verifikasi</span>
                        <span class="rounded-full px-1.5 py-0.5 text-[10px] {{ $filter === 'verifikasi' ? 'bg-white/20 text-white' : 'bg-warning-soft text-warning-ink border border-warning/20' }}">{{ $unverifiedCount }}</span>
                    </a>
                    <a href="{{ route('admin.rooms.index', array_filter(['filter' => 'kuliah', 'q' => $keyword, 'gedung' => $buildingFilter, 'per_page' => $perPage])) }}"
                       @class([
                           'inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition',
                           'bg-primary text-white shadow-2xs' => $filter === 'kuliah',
                           'bg-page text-muted hover:text-ink hover:bg-border' => $filter !== 'kuliah',
                       ])>
                        <i data-lucide="door-closed" class="size-3.5"></i>
                        <span>Ruang Kuliah</span>
                        <span class="rounded-full px-1.5 py-0.5 text-[10px] {{ $filter === 'kuliah' ? 'bg-white/20 text-white' : 'bg-surface text-muted' }}">{{ $classroomCount }}</span>
                    </a>
                    <a href="{{ route('admin.rooms.index', array_filter(['filter' => 'lab', 'q' => $keyword, 'gedung' => $buildingFilter, 'per_page' => $perPage])) }}"
                       @class([
                           'inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition',
                           'bg-indigo-600 text-white shadow-2xs' => $filter === 'lab',
                           'bg-indigo-50 text-indigo-700 hover:bg-indigo-100' => $filter !== 'lab',
                       ])>
                        <i data-lucide="wrench" class="size-3.5"></i>
                        <span>Laboratorium</span>
                        <span class="rounded-full px-1.5 py-0.5 text-[10px] {{ $filter === 'lab' ? 'bg-white/20 text-white' : 'bg-indigo-100 text-indigo-700' }}">{{ $labCount }}</span>
                    </a>
                    <a href="{{ route('admin.rooms.index', array_filter(['filter' => 'ditutup', 'q' => $keyword, 'gedung' => $buildingFilter, 'per_page' => $perPage])) }}"
                       @class([
                           'inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition',
                           'bg-danger text-white shadow-2xs' => $filter === 'ditutup',
                           'bg-danger-soft/60 text-danger hover:bg-danger-soft' => $filter !== 'ditutup',
                       ])>
                        <i data-lucide="lock" class="size-3.5"></i>
                        <span>Ditutup</span>
                        <span class="rounded-full px-1.5 py-0.5 text-[10px] {{ $filter === 'ditutup' ? 'bg-white/20 text-white' : 'bg-danger-soft text-danger' }}">{{ $closedRoomsCount }}</span>
                    </a>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <span class="text-xs text-muted">
                        Menampilkan <span class="font-semibold text-ink">{{ $rooms->total() }}</span> ruangan
                    </span>
                    <button type="button" @click="toggleEditMode()"
                            :class="editMode ? 'bg-amber-500 text-white ring-2 ring-amber-400/30' : 'bg-page text-ink border border-border hover:bg-primary-soft hover:text-primary'"
                            class="inline-flex items-center gap-1.5 rounded-control px-3.5 py-2 text-xs font-bold transition shadow-xs cursor-pointer">
                        <i data-lucide="pencil" class="size-3.5"></i>
                        <span x-text="editMode ? 'Tutup Edit Massal' : '⚡ Edit Massal di Tabel'"></span>
                    </button>
                </div>
            </div>

            {{-- Form Pencarian & Filter Gedung --}}
            <form method="GET" action="{{ route('admin.rooms.index') }}" class="grid grid-cols-1 items-end gap-3 sm:grid-cols-12">
                @if ($filter !== 'semua')
                    <input type="hidden" name="filter" value="{{ $filter }}">
                @endif

                <div class="sm:col-span-5">
                    <label class="mb-1.5 block text-xs font-medium text-muted">Cari Nama Ruang / Kode / Fasilitas</label>
                    <div class="relative">
                        <i data-lucide="search" class="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted"></i>
                        <input type="text" name="q" value="{{ $keyword }}" placeholder="Ketik nama ruang (contoh: AE 107, ICT)..."
                               class="w-full rounded-control border border-border bg-surface pl-9 pr-3 py-2 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary shadow-2xs">
                    </div>
                </div>

                <div class="sm:col-span-3">
                    <label class="mb-1.5 block text-xs font-medium text-muted">Filter Gedung</label>
                    <select name="gedung" @change="$el.form.requestSubmit()" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary shadow-2xs">
                        <option value="">Semua Gedung</option>
                        @foreach ($buildings as $b)
                            <option value="{{ $b->name }}" @selected($buildingFilter === $b->name)>Gedung {{ $b->name }}</option>
                        @endforeach
                        <option value="belum_didata" @selected($buildingFilter === 'belum_didata')>Gedung belum didata</option>
                    </select>
                </div>

                <div class="sm:col-span-2">
                    <label class="mb-1.5 block text-xs font-medium text-muted">Baris / Halaman</label>
                    <select name="per_page" @change="$el.form.requestSubmit()" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary shadow-2xs">
                        @foreach ([15, 30, 50, 100] as $n)
                            <option value="{{ $n }}" @selected($perPage == $n)>{{ $n }} baris</option>
                        @endforeach
                    </select>
                </div>

                <div class="flex gap-2 sm:col-span-2">
                    <button type="submit" class="flex h-[38px] flex-1 items-center justify-center gap-1.5 rounded-control bg-primary px-3 text-xs font-semibold text-white hover:bg-primary/90 transition shadow-2xs" title="Terapkan Filter">
                        <i data-lucide="filter" class="size-3.5"></i>
                        <span>Filter</span>
                    </button>
                    @if ($keyword !== '' || $filter !== 'semua' || $buildingFilter !== '')
                        <a href="{{ route('admin.rooms.index') }}" class="flex h-[38px] w-[38px] shrink-0 items-center justify-center rounded-control border border-border bg-surface text-muted hover:text-ink hover:bg-page transition shadow-2xs" title="Reset Filter">
                            <i data-lucide="rotate-ccw" class="size-4"></i>
                        </a>
                    @endif
                </div>
            </form>

            {{-- Bar Bantuan Cepat saat Mode Edit Massal Aktif --}}
            <div x-show="editMode" x-cloak class="rounded-xl border border-primary/30 bg-primary-soft/30 p-3.5 flex flex-wrap items-center justify-between gap-3 animate-in fade-in duration-200">
                <div class="flex flex-wrap items-center gap-2 text-xs">
                    <span class="font-bold text-primary flex items-center gap-1.5">
                        <i data-lucide="pencil" class="size-4"></i> Mode Edit Massal Aktif
                    </span>
                    <span class="text-muted">| Set kapasitas kursi cepat:</span>
                    <div class="flex flex-wrap items-center gap-1.5 ml-1">
                        <input type="number" min="1" max="1000" x-model="fillEmptyValue" class="w-16 rounded border border-border bg-surface px-2 py-1 text-center text-xs font-semibold text-ink focus:border-primary focus:outline-none">
                        <button type="button" @click="applyFillEmpty()" class="rounded bg-surface px-2.5 py-1 text-xs font-semibold text-primary border border-primary/40 hover:bg-primary hover:text-white transition shadow-xs cursor-pointer">
                            Isi yang Kosong
                        </button>
                        <button type="button" @click="applyFillAll()" class="rounded bg-primary/15 px-2.5 py-1 text-xs font-semibold text-primary border border-primary/40 hover:bg-primary hover:text-white transition shadow-xs cursor-pointer"
                                x-text="selectedIds.length > 0 ? 'Terapkan ke ' + selectedIds.length + ' Ruang Terpilih' : 'Terapkan ke Semua Tampil (' + visibleIds.length + ')'">
                        </button>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <button type="button" @click="cancelEditMode()" class="rounded-control border border-border bg-surface px-3 py-1.5 text-xs font-semibold text-muted hover:text-ink transition cursor-pointer">
                        Batal
                    </button>
                    <button type="submit" form="bulkRoomForm" class="inline-flex items-center gap-1.5 rounded-control bg-primary px-4 py-1.5 text-xs font-bold text-white hover:bg-primary/90 transition shadow-sm cursor-pointer">
                        <i data-lucide="check" class="size-4"></i>
                        Simpan Semua Perubahan
                    </button>
                </div>
            </div>
        </x-card>

        {{-- TABEL DATA RUANG --}}
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
                                       title="Pilih semua di halaman ini">
                            </th>
                            <th class="px-4 py-3">Kode & Nama Ruang</th>
                            <th class="px-4 py-3">Gedung / Lokasi</th>
                            <th class="px-4 py-3 text-center">Tipe Ruangan</th>
                            <th class="px-4 py-3 text-center">Kapasitas</th>
                            <th class="px-4 py-3 text-center">Status Ketersediaan</th>
                            <th class="px-4 py-3 text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($rooms as $index => $room)
                            @php($isClosed = $closures->firstWhere('room_id', $room->id))

                            <tr class="border-b border-border transition hover:bg-primary-soft/20" :class="{ 'bg-primary-soft/30': selectedIds.map(Number).includes({{ $room->id }}) }">
                                {{-- Checkbox --}}
                                <td class="px-4 py-3 text-center">
                                    <input type="checkbox"
                                           value="{{ $room->id }}"
                                           x-model.number="selectedIds"
                                           class="size-4 rounded border-border text-primary focus:ring-primary cursor-pointer">
                                </td>

                                {{-- Kode & Nama Ruang --}}
                                <td class="px-4 py-3">
                                    <div x-show="!editMode" class="flex flex-col">
                                        <span class="font-bold text-ink text-sm">{{ $room->name }}</span>
                                        <span class="font-mono text-xs text-muted">Kode: {{ $room->code }}</span>
                                    </div>
                                    <div x-show="editMode" x-cloak class="flex flex-col gap-1">
                                        <input type="hidden" form="bulkRoomForm" name="rooms[{{ $index }}][id]" value="{{ $room->id }}" :disabled="!editMode">
                                        <input type="text"
                                               form="bulkRoomForm"
                                               name="rooms[{{ $index }}][name]"
                                               value="{{ $room->name }}"
                                               :disabled="!editMode"
                                               x-model="roomsData[{{ $room->id }}].name"
                                               required
                                               class="w-40 rounded-control border border-border bg-surface px-2.5 py-1 text-sm font-semibold text-ink focus:border-primary focus:outline-none">
                                        <span class="font-mono text-[10px] text-muted">Kode: {{ $room->code }}</span>
                                    </div>
                                </td>

                                {{-- Lokasi Gedung & Lantai --}}
                                <td class="px-4 py-3">
                                    <div x-show="!editMode">
                                        @if ($room->building)
                                            <div class="flex items-center gap-2">
                                                <div class="flex size-7 shrink-0 items-center justify-center rounded-md bg-page border border-border text-primary">
                                                    <i data-lucide="building-2" class="size-3.5"></i>
                                                </div>
                                                <div class="flex flex-col">
                                                    <span class="font-semibold text-ink text-xs">Gedung {{ $room->building->name }}</span>
                                                    <span class="text-[11px] text-muted">Lantai {{ $room->floor ?? '—' }}</span>
                                                </div>
                                            </div>
                                        @else
                                            <span class="inline-flex items-center gap-1 rounded-md bg-warning-soft/80 px-2 py-0.5 text-xs font-medium text-warning-ink border border-warning/30">
                                                <i data-lucide="triangle-alert" class="size-3 text-warning"></i>
                                                Gedung belum didata
                                            </span>
                                        @endif
                                    </div>
                                    <div x-show="editMode" x-cloak class="flex items-center gap-1.5">
                                        <input type="text"
                                               form="bulkRoomForm"
                                               name="rooms[{{ $index }}][building]"
                                               value="{{ $room->building?->name }}"
                                               :disabled="!editMode"
                                               x-model="roomsData[{{ $room->id }}].building"
                                               list="building-suggestions"
                                               placeholder="Gedung..."
                                               class="w-28 rounded-control border border-border bg-surface px-2.5 py-1 text-xs text-ink focus:border-primary focus:outline-none">
                                        <input type="number" min="0" max="20"
                                               form="bulkRoomForm"
                                               name="rooms[{{ $index }}][floor]"
                                               value="{{ $room->floor }}"
                                               :disabled="!editMode"
                                               x-model="roomsData[{{ $room->id }}].floor"
                                               placeholder="Lt"
                                               title="Lantai"
                                               class="w-14 rounded-control border border-border bg-surface px-2 py-1 text-center text-xs text-ink focus:border-primary focus:outline-none">
                                    </div>
                                </td>

                                {{-- Tipe Ruangan --}}
                                <td class="px-4 py-3 text-center">
                                    <div x-show="!editMode">
                                        @if ($room->type === RoomType::Classroom)
                                            <span class="inline-flex items-center gap-1 rounded-full border border-border bg-page px-2.5 py-0.5 text-xs font-semibold text-muted">
                                                <i data-lucide="door-closed" class="size-3 text-primary"></i>
                                                Ruang Kuliah
                                            </span>
                                        @else
                                            <span class="inline-flex items-center gap-1 rounded-full border border-indigo-200 bg-indigo-50 px-2.5 py-0.5 text-xs font-semibold text-indigo-700">
                                                <i data-lucide="wrench" class="size-3 text-indigo-600"></i>
                                                Laboratorium
                                            </span>
                                        @endif
                                    </div>
                                    <div x-show="editMode" x-cloak class="flex justify-center">
                                        <select form="bulkRoomForm"
                                                name="rooms[{{ $index }}][type]"
                                                :disabled="!editMode"
                                                x-model="roomsData[{{ $room->id }}].type"
                                                class="w-36 rounded-control border border-border bg-surface px-2 py-1 text-xs font-medium text-ink focus:border-primary focus:outline-none">
                                            <option value="classroom" @selected($room->type === RoomType::Classroom)>Ruang Kuliah</option>
                                            <option value="lab" @selected($room->type === RoomType::Lab)>Laboratorium</option>
                                        </select>
                                    </div>
                                </td>

                                {{-- Kapasitas Kursi --}}
                                <td class="px-4 py-3 text-center">
                                    <div x-show="!editMode">
                                        @if ($room->capacity)
                                            <span class="inline-flex items-center gap-1 rounded-full bg-page px-2.5 py-0.5 text-xs font-bold text-ink border border-border">
                                                <i data-lucide="users" class="size-3 text-muted"></i>
                                                {{ $room->capacity }} Kursi
                                            </span>
                                        @else
                                            <button type="button" @click="editMode = true" class="inline-flex items-center gap-1 rounded-md bg-warning-soft/80 px-2 py-0.5 text-xs font-medium text-warning-ink border border-warning/30 hover:underline cursor-pointer" title="Klik untuk edit cepat">
                                                <i data-lucide="triangle-alert" class="size-3 text-warning"></i>
                                                Belum diisi
                                            </button>
                                        @endif
                                    </div>
                                    <div x-show="editMode" x-cloak class="flex justify-center">
                                        <input type="number" min="1" max="1000"
                                               form="bulkRoomForm"
                                               name="rooms[{{ $index }}][capacity]"
                                               value="{{ $room->capacity }}"
                                               :disabled="!editMode"
                                               x-model="roomsData[{{ $room->id }}].capacity"
                                               placeholder="0 kursi"
                                               class="w-24 rounded-control border border-primary/40 bg-surface px-2.5 py-1 text-center text-sm font-bold text-ink focus:border-primary focus:ring-1 focus:ring-primary focus:outline-none shadow-xs">
                                    </div>
                                </td>

                                {{-- Status Ketersediaan --}}
                                <td class="px-4 py-3 text-center">
                                    @if ($isClosed)
                                        <span class="inline-flex items-center gap-1.5 rounded-full border border-danger/30 bg-danger-soft/70 px-2.5 py-0.5 text-xs font-semibold text-danger" title="{{ $isClosed->reason }}">
                                            <span class="size-1.5 rounded-full bg-danger"></span>
                                            Ditutup
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1.5 rounded-full border border-success/30 bg-success-soft/70 px-2.5 py-0.5 text-xs font-semibold text-success">
                                            <span class="size-1.5 rounded-full bg-success"></span>
                                            Tersedia
                                        </span>
                                    @endif
                                </td>

                                {{-- Aksi Cepat --}}
                                <td class="px-4 py-3 text-right">
                                    <div class="flex items-center justify-end gap-1.5">
                                        {{-- Tombol Tutup / Buka Ruang Cepat --}}
                                        @if ($isClosed)
                                            <form method="POST" action="{{ route('admin.closures.destroy', $isClosed) }}" class="inline">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit"
                                                        class="inline-flex items-center gap-1 rounded-control border border-danger/40 bg-danger-soft/60 px-2 py-1 text-xs font-semibold text-danger hover:bg-danger hover:text-white transition cursor-pointer"
                                                        title="Buka kembali ruang ini sekarang">
                                                    <i data-lucide="lock-open" class="size-3.5"></i>
                                                    <span>Buka</span>
                                                </button>
                                            </form>
                                        @else
                                            <button type="button"
                                                    @click="openClosureModal({{ $room->id }})"
                                                    class="inline-flex items-center gap-1 rounded-control border border-border bg-surface px-2 py-1 text-xs font-medium text-muted hover:border-danger/50 hover:bg-danger-soft/20 hover:text-danger transition cursor-pointer"
                                                    title="Tutup ruang sementara">
                                                <i data-lucide="lock" class="size-3.5"></i>
                                                <span>Tutup</span>
                                            </button>
                                        @endif

                                        {{-- Tombol Edit Lengkap --}}
                                        <button type="button"
                                                @click="openEditModal({{ json_encode([
                                                    'id' => $room->id,
                                                    'name' => $room->name,
                                                    'code' => $room->code,
                                                    'type' => $room->type->value,
                                                    'capacity' => $room->capacity,
                                                    'floor' => $room->floor,
                                                    'building' => $room->building?->name,
                                                    'facilities' => $room->facilities->pluck('name')->implode(', '),
                                                    'is_bookable' => $room->is_bookable,
                                                ]) }})"
                                                class="inline-flex items-center gap-1 rounded-control border border-border bg-surface px-2.5 py-1 text-xs font-semibold text-primary hover:bg-primary-soft hover:border-primary/40 transition cursor-pointer"
                                                title="Ubah data ruang">
                                            <i data-lucide="pencil" class="size-3.5"></i>
                                            <span>Edit</span>
                                        </button>

                                        {{-- Tombol Hapus --}}
                                        <button type="button"
                                                @click="openDeleteModal({{ $room->id }}, '{{ addslashes($room->name) }}')"
                                                class="inline-flex items-center gap-1 rounded-control border border-danger/30 bg-danger-soft/30 px-2.5 py-1 text-xs font-semibold text-danger hover:bg-danger hover:text-white transition cursor-pointer"
                                                title="Hapus ruang">
                                            <i data-lucide="trash-2" class="size-3.5"></i>
                                            <span>Hapus</span>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="py-12 text-center text-muted">
                                    <div class="mx-auto flex size-12 items-center justify-center rounded-full bg-page border border-border text-muted mb-3">
                                        <i data-lucide="door-closed" class="size-6 text-muted/70"></i>
                                    </div>
                                    <p class="font-medium text-ink text-sm">Tidak ada ruang yang sesuai dengan filter.</p>
                                    <p class="text-xs text-muted mt-1">Coba gunakan kata kunci pencarian atau filter lain.</p>
                                    <div class="mt-4">
                                        <a href="{{ route('admin.rooms.index') }}" class="inline-flex items-center gap-1.5 rounded-control border border-border bg-surface px-3 py-1.5 text-xs font-semibold text-primary hover:bg-page transition shadow-2xs">
                                            <i data-lucide="rotate-ccw" class="size-3.5"></i>
                                            Reset Filter
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Pagination & Data Summary Footer --}}
            @if ($rooms->total() > 0)
                <div class="border-t border-border bg-page/50 px-4 py-3">
                    @if ($rooms->hasPages())
                        {{ $rooms->links() }}
                    @else
                        <div class="flex flex-col sm:flex-row items-center justify-between gap-3 text-xs text-muted py-1">
                            <div>
                                Menampilkan <span class="font-semibold text-ink">1</span> sampai <span class="font-semibold text-ink">{{ $rooms->total() }}</span> dari <span class="font-semibold text-ink">{{ number_format($rooms->total()) }}</span> data ruang
                            </div>
                            <span class="inline-flex items-center justify-center min-w-[32px] h-7 px-2.5 rounded-control border border-border bg-surface text-ink text-xs font-semibold">
                                Halaman 1 dari 1
                            </span>
                        </div>
                    @endif
                </div>
            @endif

            {{-- Footer jika dalam editMode --}}
            <div x-show="editMode" x-cloak class="flex items-center justify-between border-t border-border bg-surface px-4 py-3">
                <span class="text-xs text-muted">Simpan setelah selesai mengubah data ruangan di atas.</span>
                <div class="flex gap-2">
                    <button type="button" @click="cancelEditMode()" class="rounded-control border border-border bg-surface px-4 py-2 text-xs font-semibold text-muted hover:text-ink transition cursor-pointer">
                        Batal
                    </button>
                    <button type="submit" form="bulkRoomForm" class="inline-flex items-center gap-1.5 rounded-control bg-primary px-5 py-2 text-xs font-bold text-white hover:bg-primary/90 transition shadow-sm cursor-pointer">
                        <i data-lucide="check" class="size-4"></i>
                        Simpan Semua Perubahan
                    </button>
                </div>
            </div>
        </div>

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
                <i data-lucide="circle-check" class="size-5 text-success"></i>
                <span class="text-sm font-semibold"><span x-text="selectedIds.length"></span> ruang dipilih</span>
                <button type="button"
                        x-show="!isAllSelected()"
                        @click="toggleSelectAll(true)"
                        class="ml-1 rounded-md bg-surface/10 px-2 py-1 text-[11px] font-semibold text-surface/90 hover:bg-surface/20 hover:text-white transition cursor-pointer">
                    Pilih Semua Tampil
                </button>
            </div>
            <div class="h-4 w-px bg-surface/20"></div>

            {{-- 1. Atur Kapasitas Modal --}}
            <button type="button" @click="openBatchCapacityModal()" class="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3 py-1.5 text-xs font-semibold text-white shadow-xs hover:bg-primary/90 transition cursor-pointer">
                <i data-lucide="users" class="size-3.5"></i>
                Atur Kapasitas
            </button>

            {{-- 2. Batch Tambah Fasilitas Modal --}}
            <button type="button" @click="batchFacilityModalOpen = true; $nextTick(() => window.renderIcons())" class="inline-flex items-center gap-1.5 rounded-lg bg-surface/10 px-3 py-1.5 text-xs font-semibold text-surface hover:bg-surface/20 transition cursor-pointer">
                <i data-lucide="plus" class="size-3.5"></i>
                Tambah Fasilitas
            </button>

            {{-- 3. Batch Set Gedung AE --}}
            <button type="button" @click="submitBatch('set_building', { building: 'AE' })" class="inline-flex items-center gap-1.5 rounded-lg bg-surface/10 px-3 py-1.5 text-xs font-semibold text-surface hover:bg-surface/20 transition cursor-pointer">
                <i data-lucide="building-2" class="size-3.5"></i>
                Set Gedung AE
            </button>

            {{-- 4. Batch Set Gedung Pinisi --}}
            <button type="button" @click="submitBatch('set_building', { building: 'Pinisi' })" class="inline-flex items-center gap-1.5 rounded-lg bg-surface/10 px-3 py-1.5 text-xs font-semibold text-surface hover:bg-surface/20 transition cursor-pointer">
                <i data-lucide="building-2" class="size-3.5"></i>
                Set Gedung Pinisi
            </button>

            {{-- 5. Batch Set Ruang Kuliah --}}
            <button type="button" @click="submitBatch('set_type', { type: 'classroom' })" class="inline-flex items-center gap-1.5 rounded-lg bg-surface/10 px-3 py-1.5 text-xs font-semibold text-surface hover:bg-surface/20 transition cursor-pointer">
                <i data-lucide="door-closed" class="size-3.5"></i>
                Jadikan Ruang Kuliah
            </button>

            {{-- 6. Batch Set Laboratorium --}}
            <button type="button" @click="submitBatch('set_type', { type: 'lab' })" class="inline-flex items-center gap-1.5 rounded-lg bg-surface/10 px-3 py-1.5 text-xs font-semibold text-surface hover:bg-surface/20 transition cursor-pointer">
                <i data-lucide="wrench" class="size-3.5"></i>
                Jadikan Lab
            </button>

            <button type="button" @click="selectedIds = []" class="ml-2 text-xs font-medium text-surface/70 hover:text-white transition cursor-pointer">
                Batalkan Pilihan
            </button>
        </div>

        {{-- FORM TERSEMBUNYI UNTUK BATCH ACTION STANDARD --}}
        <form x-ref="batchForm" method="POST" action="{{ route('admin.rooms.batch') }}" class="hidden">
            @csrf
            <input type="hidden" name="action" x-model="batchAction">
            <input type="hidden" name="building" x-model="batchParams.building" :disabled="batchAction !== 'set_building'">
            <input type="hidden" name="type" x-model="batchParams.type" :disabled="batchAction !== 'set_type'">
            <template x-for="id in selectedIds" :key="id">
                <input type="hidden" name="room_ids[]" :value="id">
            </template>
        </form>

        {{-- FORM TERSEMBUNYI UNTUK BULK EDIT GRID --}}
        <form id="bulkRoomForm" method="POST" action="{{ route('admin.rooms.bulk-update') }}" class="hidden">
            @csrf
            @method('PUT')
        </form>

        {{-- MODAL 1: EDIT / TAMBAH RUANG DINAMIS TUNGGAL --}}
        <div x-show="formModalOpen" x-cloak data-modal-name="room-form" class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-ink/80 backdrop-blur-sm" @click="formModalOpen = false"></div>
            <div class="relative w-full max-w-lg rounded-2xl bg-surface p-6 shadow-2xl">
                <div class="flex items-start justify-between border-b border-border pb-3">
                    <div>
                        <span class="text-xs font-semibold text-primary" x-text="formRoom.id ? 'Perbarui Data Ruangan' : 'Ruang Baru'"></span>
                        <h3 class="text-lg font-bold text-ink" x-text="formRoom.id ? 'Ubah Ruang: ' + formRoom.name : 'Tambah Ruang Baru'"></h3>
                    </div>
                    <button type="button" @click="formModalOpen = false" class="text-muted hover:text-ink">
                        <i data-lucide="x" class="size-5"></i>
                    </button>
                </div>

                <form method="POST" :action="formRoom.id ? '/admin/ruang/' + formRoom.id : '{{ route('admin.rooms.store') }}'" class="mt-4 flex flex-col gap-4">
                    @csrf
                    <template x-if="formRoom.id">
                        <input type="hidden" name="_method" value="PUT">
                    </template>
                    <input type="hidden" name="form" :value="formRoom.id ? 'edit-' + formRoom.id : 'add'">

                    <div>
                        <label class="mb-1 block text-xs font-medium text-muted">Nama Ruang <span class="text-danger">*</span></label>
                        <input type="text" name="name" x-model="formRoom.name" required placeholder="Contoh: AE 107 atau Lab ICT 10" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                    </div>

                    <div>
                        <label class="mb-1 block text-xs font-medium text-muted">Jenis Ruang <span class="text-danger">*</span></label>
                        <select name="type" x-model="formRoom.type" required class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                            <option value="classroom">Ruang kuliah (Kelas teori / umum)</option>
                            <option value="lab">Laboratorium (Lab komputer / praktikum)</option>
                        </select>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-muted">Kapasitas (Kursi)</label>
                            <input type="number" min="1" max="1000" name="capacity" x-model="formRoom.capacity" placeholder="Contoh: 40" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-muted">Lantai</label>
                            <input type="number" min="0" max="20" name="floor" x-model="formRoom.floor" placeholder="Contoh: 1" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                        </div>
                    </div>

                    <div>
                        <label class="mb-1 block text-xs font-medium text-muted">Gedung</label>
                        <input type="text" name="building" x-model="formRoom.building" placeholder="Contoh: AE atau Pinisi" list="building-suggestions" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                        <datalist id="building-suggestions">
                            @foreach ($buildings as $b)
                                <option value="{{ $b->name }}"></option>
                            @endforeach
                        </datalist>
                        <p class="mt-1 text-[11px] text-muted">Kosongkan bila gedung belum didata (akan ditandai Perlu Verifikasi).</p>
                    </div>

                    <div>
                        <label class="mb-1 block text-xs font-medium text-muted">Fasilitas Ruang (Pisahkan dengan koma)</label>
                        <input type="text" name="facilities" x-model="formRoom.facilities" placeholder="Contoh: Proyektor, AC, Stopkontak, Papan tulis" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                        {{-- Saran Tag Cepat --}}
                        <div class="mt-2 flex flex-wrap gap-1.5">
                            <span class="text-[11px] text-muted self-center mr-1">Saran cepat:</span>
                            <template x-for="tag in ['Proyektor', 'AC', 'Papan tulis', 'Stopkontak', 'Smart TV', 'Sound System']" :key="tag">
                                <button type="button" @click="addFacilityTag(tag)" class="rounded-full border border-border bg-page px-2 py-0.5 text-[11px] text-muted hover:border-primary hover:text-primary transition" x-text="'+ ' + tag"></button>
                            </template>
                        </div>
                    </div>

                    <div class="mt-2 flex items-center justify-between gap-3 border-t border-border pt-4">
                        <template x-if="formRoom.id">
                            <x-button variant="danger-outline" type="button" icon="trash-2" @click="openDeleteFromEdit()">Hapus ruang</x-button>
                        </template>
                        <template x-if="!formRoom.id">
                            <div></div>
                        </template>
                        <div class="flex gap-2">
                            <x-button variant="secondary" type="button" @click="formModalOpen = false">Batal</x-button>
                            <x-button variant="primary" type="submit" icon="check">Simpan Data Ruang</x-button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        {{-- MODAL 2: PENUTUPAN RUANG SEMENTARA --}}
        <div x-show="closureModalOpen" x-cloak data-modal-name="room-closure" class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-ink/80 backdrop-blur-sm" @click="closureModalOpen = false"></div>
            <div class="relative w-full max-w-lg rounded-2xl bg-surface p-6 shadow-2xl">
                <div class="flex items-start justify-between border-b border-border pb-3">
                    <div>
                        <span class="text-xs font-semibold text-danger">Pemeliharaan & Penutupan</span>
                        <h3 class="text-lg font-bold text-ink">Tutup Ruang Sementara</h3>
                    </div>
                    <button type="button" @click="closureModalOpen = false" class="text-muted hover:text-ink">
                        <i data-lucide="x" class="size-5"></i>
                    </button>
                </div>

                <p class="mt-2 text-xs text-muted">Ruang yang ditutup akan tampil "Digunakan" dan tidak bisa dipesan selama masa penutupan.</p>

                <form method="POST" action="{{ route('admin.closures.store') }}" class="mt-4 flex flex-col gap-4">
                    @csrf
                    <input type="hidden" name="form" value="closure">

                    <div>
                        <label class="mb-1 block text-xs font-medium text-muted">Pilih Ruang <span class="text-danger">*</span></label>
                        <select name="room_id" x-model="closureRoomId" required class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                            <option value="">-- Pilih Ruangan --</option>
                            @foreach ($allRooms as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-muted">Mulai Tutup <span class="text-danger">*</span></label>
                            <input type="datetime-local" name="start_at" x-model="closureStart" required class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-muted">Selesai Tutup <span class="text-danger">*</span></label>
                            <input type="datetime-local" name="end_at" x-model="closureEnd" required class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                        </div>
                    </div>

                    <div>
                        <label class="mb-1 block text-xs font-medium text-muted">Alasan Penutupan <span class="text-danger">*</span></label>
                        <input type="text" name="reason" x-model="closureReason" required placeholder="Contoh: Perawatan AC / Perbaikan proyektor / Renovasi" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                    </div>

                    <div>
                        <label class="flex items-center gap-2 text-xs text-ink cursor-pointer font-medium select-none">
                            <input type="checkbox" name="cancel_impacted" value="1" class="size-4 accent-danger rounded cursor-pointer">
                            Batalkan juga reservasi terdampak secara otomatis jika ada
                        </label>
                    </div>

                    @if (session('closure_impact'))
                        <div class="flex flex-col gap-2 rounded-card bg-danger-soft p-4 text-xs">
                            <div class="flex items-center gap-1.5 font-semibold text-danger">
                                <i data-lucide="triangle-alert" class="size-4"></i>
                                <span>{{ count(session('closure_impact')) }} reservasi aktif terdampak penutupan ini:</span>
                            </div>
                            <div class="divide-y divide-danger/20 max-h-36 overflow-y-auto">
                                @foreach (session('closure_impact') as $impact)
                                    <div class="py-1 text-ink">
                                        <strong>{{ $impact['class'] }}</strong> • {{ $impact['time'] }} ({{ $impact['purpose'] }})
                                    </div>
                                @endforeach
                            </div>
                            <p class="text-[11px] text-muted mt-1">Kelas terdampak akan diberi notifikasi secara otomatis.</p>
                            <label class="mt-1 flex items-center gap-2 text-ink cursor-pointer font-medium">
                                <input type="checkbox" name="cancel_impacted" value="1" class="size-4 accent-danger rounded cursor-pointer">
                                Batalkan juga reservasi yang terdampak di atas secara otomatis
                            </label>
                            <label class="mt-1 flex items-center gap-2 text-ink cursor-pointer font-medium">
                                <input type="checkbox" name="confirm_impact" value="1" class="size-4 accent-danger rounded cursor-pointer" required>
                                Saya mengerti dan tetap menutup ruang
                            </label>
                        </div>
                    @endif

                    <div class="mt-2 flex items-center justify-end gap-2 border-t border-border pt-4">
                        <x-button variant="secondary" type="button" @click="closureModalOpen = false">Batal</x-button>
                        <x-button variant="danger" type="submit" icon="lock">Tutup Ruang</x-button>
                    </div>
                </form>
            </div>
        </div>

        {{-- MODAL 3: KONFIRMASI HAPUS DINAMIS TUNGGAL --}}
        <div x-show="deleteModalOpen" x-cloak data-modal-name="room-delete" class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-ink/80 backdrop-blur-sm" @click="deleteModalOpen = false"></div>
            <div class="relative w-full max-w-md rounded-2xl bg-surface p-6 shadow-2xl">
                <div class="flex items-start justify-between border-b border-border pb-3">
                    <h3 class="text-lg font-bold text-ink">Hapus ruang <span x-text="roomToDelete.name"></span>?</h3>
                    <button type="button" @click="deleteModalOpen = false" class="text-muted hover:text-ink">
                        <i data-lucide="x" class="size-5"></i>
                    </button>
                </div>
                <p class="mt-3 text-xs text-muted">Ruang ini akan hilang dari daftar. Ruang yang masih dipakai reservasi aktif atau jadwal resmi tidak dapat dihapus.</p>

                <form method="POST" :action="'/admin/ruang/' + roomToDelete.id" class="mt-4 flex flex-col gap-4">
                    @csrf
                    @method('DELETE')
                    <div>
                        <label class="mb-1 block text-xs font-medium text-muted">Alasan Penghapusan (Opsional)</label>
                        <input type="text" name="reason" placeholder="Contoh: Ruang dialihfungsikan" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                    </div>

                    <div class="mt-2 flex items-center justify-end gap-2 border-t border-border pt-4">
                        <x-button variant="secondary" type="button" @click="deleteModalOpen = false">Batal</x-button>
                        <x-button variant="danger" type="submit" icon="trash-2">Hapus Ruang</x-button>
                    </div>
                </form>
            </div>
        </div>

        {{-- MODAL 4: BATCH ATUR KAPASITAS --}}
        <div x-show="batchCapacityModalOpen" x-cloak data-modal-name="room-batch-capacity" role="dialog" aria-modal="true" aria-labelledby="batch-capacity-title"
             @keydown.escape.window="batchCapacityModalOpen = false" class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-ink/80 backdrop-blur-sm" @click="batchCapacityModalOpen = false"></div>
            <div class="relative w-full max-w-md rounded-2xl bg-surface p-6 shadow-2xl">
                <div class="flex items-start justify-between border-b border-border pb-3">
                    <div>
                        <span class="text-xs font-semibold text-primary">Penyesuaian Massal</span>
                        <h3 id="batch-capacity-title" class="text-lg font-bold text-ink">Atur Kapasitas (<span x-text="selectedIds.length"></span> Ruang Terpilih)</h3>
                    </div>
                    <button type="button" @click="batchCapacityModalOpen = false" aria-label="Tutup pengaturan kapasitas" class="text-muted hover:text-ink">
                        <i data-lucide="x" class="size-5"></i>
                    </button>
                </div>

                <form method="POST" action="{{ route('admin.rooms.batch') }}" class="mt-4 flex flex-col gap-4">
                    @csrf
                    <input type="hidden" name="form" value="batch_capacity">
                    <input type="hidden" name="action" value="set_capacity">
                    <template x-for="id in selectedIds" :key="id">
                        <input type="hidden" name="room_ids[]" :value="id">
                    </template>

                    <div>
                        <label for="batch-capacity" class="mb-1 block text-xs font-medium text-muted">Kapasitas Kursi per Ruang (Orang)</label>
                        <x-input name="capacity" id="batch-capacity" type="number" min="1" max="1000" step="1" required
                                 x-ref="batchCapacityInput" x-model="batchCapacity" placeholder="Misal: 40" aria-describedby="batch-capacity-help" />
                        @if (old('form') === 'batch_capacity')
                            @error('capacity')
                                <p id="capacity-error" class="mt-1 text-sm text-danger">{{ $message }}</p>
                            @enderror
                            @error('room_ids')
                                <p class="mt-1 text-sm text-danger">{{ $message }}</p>
                            @enderror
                        @endif
                    </div>

                    <div id="batch-capacity-help" class="rounded-lg border border-primary/20 bg-primary-soft/40 p-3 text-xs text-ink">
                        Kapasitas ini akan diterapkan serentak ke <strong x-text="selectedIds.length"></strong> ruangan yang sedang dicentang.
                    </div>

                    <div class="mt-1 flex flex-wrap items-center justify-end gap-2 border-t border-border pt-4">
                        <x-button variant="secondary" type="button" @click="batchCapacityModalOpen = false">Batal</x-button>
                        <x-button variant="primary" type="submit" icon="check" x-bind:disabled="selectedIds.length === 0">
                            Terapkan ke Ruang Terpilih
                        </x-button>
                    </div>
                </form>
            </div>
        </div>

        {{-- MODAL 5: BATCH TAMBAH FASILITAS --}}
        <div x-show="batchFacilityModalOpen" x-cloak data-modal-name="room-batch-facility" class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-ink/80 backdrop-blur-sm" @click="batchFacilityModalOpen = false"></div>
            <div class="relative w-full max-w-md rounded-2xl bg-surface p-6 shadow-2xl">
                <div class="flex items-start justify-between border-b border-border pb-3">
                    <div>
                        <span class="text-xs font-semibold text-primary">Penyesuaian Massal</span>
                        <h3 class="text-lg font-bold text-ink">Tambah Fasilitas (<span x-text="selectedIds.length"></span> Ruang Terpilih)</h3>
                    </div>
                    <button type="button" @click="batchFacilityModalOpen = false" class="text-muted hover:text-ink">
                        <i data-lucide="x" class="size-5"></i>
                    </button>
                </div>

                <form method="POST" action="{{ route('admin.rooms.batch') }}" class="mt-4 flex flex-col gap-4">
                    @csrf
                    <input type="hidden" name="action" value="add_facilities">
                    <template x-for="id in selectedIds" :key="id">
                        <input type="hidden" name="room_ids[]" :value="id">
                    </template>

                    <div>
                        <label class="mb-1 block text-xs font-medium text-muted">Fasilitas yang Ditambahkan (Pisahkan koma)</label>
                        <input type="text" name="facilities" required placeholder="Contoh: AC, Proyektor, Papan tulis" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                    </div>

                    <div class="rounded-lg border border-primary/20 bg-primary-soft/40 p-3 text-xs text-ink">
                        Fasilitas baru akan ditambahkan ke <strong x-text="selectedIds.length"></strong> ruangan terpilih tanpa menghapus fasilitas yang sudah ada.
                    </div>

                    <div class="mt-1 flex items-center justify-end gap-2 border-t border-border pt-4">
                        <x-button variant="secondary" type="button" @click="batchFacilityModalOpen = false">Batal</x-button>
                        <x-button variant="primary" type="submit" icon="check">Terapkan ke Ruang Terpilih</x-button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
