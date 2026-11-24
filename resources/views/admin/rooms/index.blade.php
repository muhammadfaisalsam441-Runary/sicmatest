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
        $oldForm = old('form');
        $isRoomFormError = $errors->any() && ($oldForm === 'add' || str_starts_with((string) $oldForm, 'edit-'));
        $oldRoomId = str_starts_with((string) $oldForm, 'edit-')
            ? (int) substr((string) $oldForm, 5)
            : (old('room_id') ? (int) old('room_id') : null);
        $roomManagerDefaults = [
            'formModalOpen' => $isRoomFormError || request()->boolean('tambah'),
            'roomId' => $oldRoomId,
            'batchCapacityModalOpen' => $errors->any() && $oldForm === 'batch_capacity',
            'batchCapacity' => $oldForm === 'batch_capacity' ? old('capacity', '') : '',
            'batchFacilityModalOpen' => $errors->any() && $oldForm === 'batch_facility',
            'batchFacilities' => $oldForm === 'batch_facility' ? old('facilities', '') : '',
            'selectedIds' => in_array($oldForm, ['batch_capacity', 'batch_facility', 'batch_rooms'], true) ? old('room_ids', []) : [],
            'closureModalOpen' => (bool) (session('closure_impact') || $oldForm === 'closure'),
            'closureMode' => old('mode', 'once'),
            'closureRoomId' => old('room_id', ''),
            'closureStart' => old('start_at', now()->format('Y-m-d\TH:i')),
            'closureEnd' => old('end_at', now()->addHours(4)->format('Y-m-d\TH:i')),
            'closureStartDate' => old('start_date', now()->toDateString()),
            'closureEndDate' => old('end_date', $activePeriodEnd ?? now()->addMonths(4)->toDateString()),
            'closureStartTime' => old('start_time', '07:30'),
            'closureEndTime' => old('end_time', '12:00'),
            'closureDays' => old('days_of_week', [1, 2, 3, 4, 5]),
            'closureReason' => old('reason', ''),
            'name' => old('name', ''),
            'code' => old('code', ''),
            'type' => old('type', 'classroom'),
            'capacity' => old('capacity', ''),
            'floor' => old('floor', ''),
            'building' => old('building', ''),
            'facilities' => old('facilities', ''),
            'is_bookable' => (bool) old('is_bookable', true),
        ];
    @endphp
    <div class="flex flex-col gap-4 admin-page-stack"
         x-data="{
             ...roomManager(@js($rooms->pluck('id')->all()), @js($roomManagerDefaults), @js($roomsPayload ?? [])),
             reopenModalOpen: false,
             closureToReopen: { name: '', url: '' },
             openReopenClosureModal(name, url) {
                 this.closureToReopen = { name, url };
                 this.reopenModalOpen = true;
                 this.$nextTick(() => window.renderIcons());
             }
         }"
         x-init="
             @if ($roomManagerDefaults['roomId'])
                 formRoom.id = {{ $roomManagerDefaults['roomId'] }};
             @endif
             @if ($roomManagerDefaults['batchFacilityModalOpen'])
                 batchFacilityModalOpen = true;
             @endif
         "
         :data-editing="(editMode || formModalOpen || closureModalOpen || deleteModalOpen || reopenModalOpen || batchFacilityModalOpen || batchCapacityModalOpen || selectedIds.length > 0) ? 'true' : null"
         @sicma:navigated.document="formModalOpen = false; closureModalOpen = false; deleteModalOpen = false; reopenModalOpen = false; batchFacilityModalOpen = false; batchCapacityModalOpen = false; editMode = false">
        <x-page-header title="Data ruang"
                       :subtitle="($periodName ? $periodName.' • ' : '').$totalRooms.' ruang terdaftar'">
            <x-slot:actions>
                <x-button size="sm" variant="secondary" icon="download" :href="route('admin.rooms.export', request()->only(['filter', 'gedung', 'q', 'sort', 'dir']))" download>Unduh CSV</x-button>
                <x-button size="sm" variant="secondary" icon="lock" @click="openClosureModal(null)">Tutup ruang</x-button>
                <x-button size="sm" icon="plus" @click="openCreateModal()">Tambah ruang</x-button>
            </x-slot:actions>
        </x-page-header>

        @if ($errors->any() && ! $isRoomFormError && ! in_array($oldForm, ['closure', 'batch_capacity', 'batch_facility'], true))
            <x-alert variant="danger">{{ $errors->first() }}</x-alert>
        @endif

        {{-- Toolbar Filter & Pencarian Cepat --}}
        <x-card padding="p-4" class="admin-table-card flex flex-col gap-3 shadow-xs">
            {{-- Tabs Filter Status --}}
            <div class="flex flex-wrap items-center gap-1.5">
                <a href="{{ route('admin.rooms.index', array_filter(['filter' => null, 'q' => $keyword, 'gedung' => $buildingFilter, 'per_page' => $perPage])) }}"
                   @class([
                       'inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold transition',
                       'bg-primary text-white shadow-2xs' => $filter === 'semua',
                       'bg-page text-muted hover:text-ink hover:bg-border' => $filter !== 'semua',
                   ])>
                    <span>Semua</span>
                    <span class="rounded-full px-1.5 py-0.5 text-[10px] {{ $filter === 'semua' ? 'bg-white/20 text-white' : 'bg-surface text-muted' }}">{{ $totalRooms }}</span>
                </a>
                <a href="{{ route('admin.rooms.index', array_filter(['filter' => 'verifikasi', 'q' => $keyword, 'gedung' => $buildingFilter, 'per_page' => $perPage])) }}"
                   @class([
                       'inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold transition',
                       'bg-warning text-white shadow-2xs' => $filter === 'verifikasi',
                       'bg-warning-soft/80 text-warning-ink border border-warning/30 hover:bg-warning-soft' => $filter !== 'verifikasi',
                   ])>
                    <i data-lucide="triangle-alert" class="size-3.5" aria-hidden="true"></i>
                    <span>Perlu Verifikasi</span>
                    <span class="rounded-full px-1.5 py-0.5 text-[10px] {{ $filter === 'verifikasi' ? 'bg-white/20 text-white' : 'bg-warning-soft text-warning-ink border border-warning/20' }}">{{ $unverifiedCount }}</span>
                </a>
                <a href="{{ route('admin.rooms.index', array_filter(['filter' => 'kuliah', 'q' => $keyword, 'gedung' => $buildingFilter, 'per_page' => $perPage])) }}"
                   @class([
                       'inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold transition',
                       'bg-primary text-white shadow-2xs' => $filter === 'kuliah',
                       'bg-page text-muted hover:text-ink hover:bg-border' => $filter !== 'kuliah',
                   ])>
                    <i data-lucide="door-closed" class="size-3.5" aria-hidden="true"></i>
                    <span>Ruang Kuliah</span>
                    <span class="rounded-full px-1.5 py-0.5 text-[10px] {{ $filter === 'kuliah' ? 'bg-white/20 text-white' : 'bg-surface text-muted' }}">{{ $classroomCount }}</span>
                </a>
                <a href="{{ route('admin.rooms.index', array_filter(['filter' => 'lab', 'q' => $keyword, 'gedung' => $buildingFilter, 'per_page' => $perPage])) }}"
                   @class([
                       'inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold transition',
                       'bg-indigo-600 text-white shadow-2xs' => $filter === 'lab',
                       'bg-indigo-50 text-indigo-700 hover:bg-indigo-100' => $filter !== 'lab',
                   ])>
                    <i data-lucide="wrench" class="size-3.5" aria-hidden="true"></i>
                    <span>Laboratorium</span>
                    <span class="rounded-full px-1.5 py-0.5 text-[10px] {{ $filter === 'lab' ? 'bg-white/20 text-white' : 'bg-indigo-100 text-indigo-700' }}">{{ $labCount }}</span>
                </a>
                <a href="{{ route('admin.rooms.index', array_filter(['filter' => 'ditutup', 'q' => $keyword, 'gedung' => $buildingFilter, 'per_page' => $perPage])) }}"
                   @class([
                       'inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold transition',
                       'bg-danger text-white shadow-2xs' => $filter === 'ditutup',
                       'bg-danger-soft/60 text-danger hover:bg-danger-soft' => $filter !== 'ditutup',
                   ])>
                    <i data-lucide="lock" class="size-3.5" aria-hidden="true"></i>
                    <span>Ditutup</span>
                    <span class="rounded-full px-1.5 py-0.5 text-[10px] {{ $filter === 'ditutup' ? 'bg-white/20 text-white' : 'bg-danger-soft text-danger' }}">{{ $closedRoomsCount }}</span>
                </a>
            </div>

            {{-- Form Pencarian & Filter Gedung --}}
            <form id="room-filter-form" method="GET" action="{{ route('admin.rooms.index') }}" class="flex flex-wrap items-center gap-2">
                @if ($filter !== 'semua')
                    <input type="hidden" name="filter" value="{{ $filter }}">
                @endif
                @if ($sort !== 'name')
                    <input type="hidden" name="sort" value="{{ $sort }}">
                @endif
                @if ($dir !== 'asc')
                    <input type="hidden" name="dir" value="{{ $dir }}">
                @endif
                @if ($rooms->total() === 0)
                    <input type="hidden" name="per_page" value="{{ $perPage }}">
                @endif

                <div class="relative flex-1 min-w-[220px]">
                    <i data-lucide="search" class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted" aria-hidden="true"></i>
                    <input id="room-search" type="search" name="q" value="{{ $keyword }}" data-table-search
                           autocorrect="off" autocapitalize="none" spellcheck="false"
                           aria-label="Cari nama ruang, kode, atau fasilitas"
                           @input.debounce.400ms="$el.form.requestSubmit()"
                           placeholder="Cari nama ruang, kode, atau fasilitas (tekan /)..."
                           class="w-full rounded-control border border-border bg-surface pl-9 pr-3 py-2 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary shadow-2xs">
                </div>

                <select id="room-building-filter" name="gedung" aria-label="Filter gedung" @change="$el.form.requestSubmit()"
                        class="w-full sm:w-52 rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary shadow-2xs">
                    <option value="">Semua Gedung</option>
                    @foreach ($buildings as $b)
                        <option value="{{ $b->name }}" @selected($buildingFilter === $b->name)>Gedung {{ $b->name }}</option>
                    @endforeach
                    <option value="belum_didata" @selected($buildingFilter === 'belum_didata')>Gedung belum didata</option>
                </select>

                @if ($keyword !== '' || $filter !== 'semua' || $buildingFilter !== '')
                    <a href="{{ route('admin.rooms.index') }}"
                       class="inline-flex h-[38px] shrink-0 items-center gap-1.5 rounded-control border border-border bg-surface px-3 text-xs font-medium text-muted hover:text-ink hover:bg-page transition shadow-2xs"
                       title="Reset Filter" aria-label="Reset filter ruang">
                        <i data-lucide="rotate-ccw" class="size-3.5" aria-hidden="true"></i>
                        <span>Reset</span>
                    </a>
                @endif
            </form>

            {{-- Bar Bantuan Cepat saat Mode Edit Massal Aktif --}}
            <x-bulk-edit-bar form="bulkRoomForm" label="Set kapasitas kursi cepat:">
                <input type="number" min="1" max="1000" x-model="fillEmptyValue" aria-label="Kapasitas kursi cepat" class="w-16 rounded border border-border bg-surface px-2 py-1 text-center text-xs font-semibold text-ink focus:border-primary focus:outline-none">
                <button type="button" @click="applyFillEmpty()" class="cursor-pointer rounded border border-primary/40 bg-surface px-2.5 py-1 text-xs font-semibold text-primary shadow-xs transition hover:bg-primary hover:text-white">
                    Isi yang Kosong
                </button>
                <button type="button" @click="applyFillAll()" class="cursor-pointer rounded border border-primary/40 bg-primary/15 px-2.5 py-1 text-xs font-semibold text-primary shadow-xs transition hover:bg-primary hover:text-white"
                        x-text="selectedIds.length > 0 ? 'Terapkan ke ' + selectedIds.length + ' Ruang Terpilih' : 'Terapkan ke Semua Tampil (' + visibleIds.length + ')'">
                </button>
            </x-bulk-edit-bar>
            {{-- TABEL DATA RUANG (MENYATU DENGAN FILTER) --}}
            <x-table-scroll>
                <x-slot:title>Menampilkan {{ $rooms->total() }} ruangan</x-slot:title>
                <x-slot:headerActions>
                    <x-table-density-toggle />
                    <button type="button" @click="toggleEditMode()"
                            :class="editMode ? 'bg-amber-500 text-white ring-2 ring-amber-400/30' : 'bg-page text-ink border border-border hover:bg-primary-soft hover:text-primary'"
                            class="inline-flex items-center gap-1.5 rounded-control px-2.5 py-1 text-xs font-bold transition shadow-xs cursor-pointer">
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
                                       aria-label="Pilih semua ruang di halaman ini"
                                       title="Pilih semua di halaman ini (mendukung Shift+Klik)">
                            </th>
                            <x-sortable-th column="name" :sort="$sort" :dir="$dir" class="px-4 py-3">Kode & Nama Ruang</x-sortable-th>
                            <th scope="col" class="px-4 py-3">Gedung / Lokasi</th>
                            <x-sortable-th column="type" :sort="$sort" :dir="$dir" align="center" class="px-4 py-3">Tipe Ruangan</x-sortable-th>
                            <x-sortable-th column="capacity" :sort="$sort" :dir="$dir" align="center" class="px-4 py-3">Kapasitas</x-sortable-th>
                            <th scope="col" class="px-4 py-3 text-center">Status Ketersediaan</th>
                            <th scope="col" class="px-4 py-3 text-right">Aksi</th>
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
                                           aria-label="Pilih ruang {{ $room->name }}"
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
                                            <button type="button" @click="toggleEditMode()" class="inline-flex items-center gap-1 rounded-md bg-warning-soft/80 px-2 py-0.5 text-xs font-medium text-warning-ink border border-warning/30 hover:underline cursor-pointer" title="Klik untuk edit cepat">
                                                <i data-lucide="triangle-alert" class="size-3 text-warning" aria-hidden="true"></i>
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
                                            <button type="button"
                                                    @click="openReopenClosureModal(@js($room->name), @js(route('admin.closures.destroy', $isClosed)))"
                                                    class="inline-flex items-center gap-1 rounded-control border border-danger/40 bg-danger-soft/60 px-2 py-1 text-xs font-semibold text-danger hover:bg-danger hover:text-white transition cursor-pointer"
                                                    title="Buka kembali ruang ini sekarang">
                                                <i data-lucide="lock-open" class="size-3.5" aria-hidden="true"></i>
                                                <span>Buka</span>
                                            </button>
                                        @else
                                            <button type="button"
                                                    @click="openClosureModal({{ $room->id }})"
                                                    class="inline-flex items-center gap-1 rounded-control border border-border bg-surface px-2 py-1 text-xs font-medium text-muted hover:border-danger/50 hover:bg-danger-soft/20 hover:text-danger transition cursor-pointer"
                                                    title="Tutup ruang sementara">
                                                <i data-lucide="lock" class="size-3.5" aria-hidden="true"></i>
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
                                            <i data-lucide="pencil" class="size-3.5" aria-hidden="true"></i>
                                            <span>Edit</span>
                                        </button>

                                        {{-- Tombol Hapus --}}
                                        <button type="button"
                                                @click="openDeleteModal({{ $room->id }}, @js($room->name))"
                                                class="inline-flex items-center gap-1 rounded-control border border-danger/30 bg-danger-soft/30 px-2.5 py-1 text-xs font-semibold text-danger hover:bg-danger hover:text-white transition cursor-pointer"
                                                title="Hapus ruang">
                                            <i data-lucide="trash-2" class="size-3.5" aria-hidden="true"></i>
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
            </x-table-scroll>

            {{-- Pagination & Data Summary Footer --}}
            <x-table-pagination :paginator="$rooms"
                                form="room-filter-form"
                                :per-page="$perPage"
                                :options="[15, 30, 50, 100]"
                                unit="data ruang"
                                select-id="room-per-page"
                                aria-label="Baris per halaman" />

            {{-- Footer jika dalam editMode --}}
            <div x-show="editMode" x-cloak class="flex flex-wrap items-center justify-between gap-3 border-t border-border pt-4">
                <span class="text-xs text-muted">Simpan setelah selesai mengubah data ruangan di atas.</span>
                <div class="flex flex-wrap gap-2">
                    <button type="button" @click="cancelEditMode()" class="rounded-control border border-border bg-surface px-4 py-2 text-xs font-semibold text-muted hover:text-ink transition cursor-pointer">
                        Batal
                    </button>
                    <button type="submit" form="bulkRoomForm" class="inline-flex items-center gap-1.5 rounded-control bg-primary px-5 py-2 text-xs font-bold text-white hover:bg-primary/90 transition shadow-sm cursor-pointer">
                        <i data-lucide="check" class="size-4"></i>
                        Simpan Semua Perubahan
                    </button>
                </div>
            </div>
        </x-card>

        @include('admin.rooms.partials.recurring-closures')

        {{-- FLOATING BATCH ACTION BAR --}}
        <x-batch-action-bar unit="ruang" show="selectedIds.length > 0 && !editMode">
            {{-- 1. Atur Kapasitas Modal --}}
            <button type="button" @click="openBatchCapacityModal()" class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg bg-primary px-3 py-1.5 text-xs font-semibold text-white shadow-xs transition hover:bg-primary/90">
                <i data-lucide="users" class="size-3.5" aria-hidden="true"></i>
                Atur Kapasitas
            </button>

            {{-- 2. Batch Tambah Fasilitas Modal --}}
            <button type="button" @click="batchFacilityModalOpen = true; $nextTick(() => window.renderIcons())" class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg bg-surface/10 px-3 py-1.5 text-xs font-semibold text-surface transition hover:bg-surface/20">
                <i data-lucide="plus" class="size-3.5" aria-hidden="true"></i>
                Tambah Fasilitas
            </button>

            {{-- 3. Batch Set Gedung Dinamis --}}
            @foreach ($buildings as $b)
                <button type="button" @click="submitBatch('set_building', { building: @js($b->name) })" class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg bg-surface/10 px-3 py-1.5 text-xs font-semibold text-surface transition hover:bg-surface/20">
                    <i data-lucide="building-2" class="size-3.5" aria-hidden="true"></i>
                    Set Gedung {{ $b->name }}
                </button>
            @endforeach

            {{-- 4. Batch Set Ruang Kuliah --}}
            <button type="button" @click="submitBatch('set_type', { type: 'classroom' })" class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg bg-surface/10 px-3 py-1.5 text-xs font-semibold text-surface transition hover:bg-surface/20">
                <i data-lucide="door-closed" class="size-3.5" aria-hidden="true"></i>
                Jadikan Ruang Kuliah
            </button>

            {{-- 5. Batch Set Laboratorium --}}
            <button type="button" @click="submitBatch('set_type', { type: 'lab' })" class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg bg-surface/10 px-3 py-1.5 text-xs font-semibold text-surface transition hover:bg-surface/20">
                <i data-lucide="wrench" class="size-3.5" aria-hidden="true"></i>
                Jadikan Lab
            </button>
        </x-batch-action-bar>

        {{-- FORM TERSEMBUNYI UNTUK BATCH ACTION STANDARD --}}
        <form x-ref="batchForm" method="POST" action="{{ route('admin.rooms.batch') }}" class="hidden">
            @csrf
            <input type="hidden" name="form" value="batch_rooms">
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
            <input type="hidden" name="form" value="bulk_rooms">
        </form>

        @include('admin.rooms.modals.form-modal')
        @include('admin.rooms.modals.closure-modal')
        @include('admin.rooms.modals.batch-modals')
    </div>
@endsection
