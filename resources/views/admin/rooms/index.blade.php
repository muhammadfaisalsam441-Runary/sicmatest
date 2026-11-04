@use('App\Enums\RoomType')
@use('App\Support\Wita')
@extends('layouts.admin')

@section('title', 'Kelola Ruang')
@section('breadcrumb', 'Kelola Ruang')

@section('breadcrumb-actions')
    <x-button variant="secondary" :href="route('admin.dashboard')">Dashboard</x-button>
@endsection

@section('content')
    <div class="flex flex-col gap-6" x-data="roomManager()">
        <x-page-header title="Data ruang"
                       :subtitle="$totalRooms.' ruang terdaftar'.($periodName ? ' dari jadwal '.$periodName : '').'. Kelola profil ruangan, kapasitas kursi, fasilitas, lokasi gedung, serta status penutupan ruang.'">
            <x-slot:actions>
                <x-button variant="secondary" icon="lock" @click="openClosureModal(null)">Tutup ruang</x-button>
                <x-button icon="plus" @click="openCreateModal()">Tambah ruang</x-button>
            </x-slot:actions>
        </x-page-header>

        {{-- Ringkasan Metrik Cepat Ruangan --}}
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-5">
            <x-card class="flex flex-col gap-1 p-4">
                <span class="text-xs font-medium text-muted">Total Ruangan</span>
                <span class="text-2xl font-bold text-ink">{{ number_format($totalRooms) }}</span>
                <span class="text-xs text-muted">Semua unit terdata</span>
            </x-card>

            <a href="{{ route('admin.rooms.index', ['filter' => 'verifikasi']) }}"
               @class([
                   'rounded-card border p-4 transition flex flex-col gap-1',
                   'border-warning/50 bg-warning-soft/30 hover:bg-warning-soft/50' => $unverifiedCount > 0,
                   'border-border bg-surface hover:bg-page' => $unverifiedCount === 0,
               ])>
                <span class="text-xs font-medium text-warning-ink">⚠️ Perlu Verifikasi</span>
                <span class="text-2xl font-bold text-ink">{{ number_format($unverifiedCount) }}</span>
                <span class="text-xs text-muted">Kapasitas / lokasi belum lengkap</span>
            </a>

            <a href="{{ route('admin.rooms.index', ['filter' => 'kuliah']) }}"
               @class([
                   'rounded-card border p-4 transition flex flex-col gap-1',
                   'border-primary/40 bg-primary-soft/20' => $filter === 'kuliah',
                   'border-border bg-surface hover:bg-page' => $filter !== 'kuliah',
               ])>
                <span class="text-xs font-medium text-muted">Ruang Kuliah</span>
                <span class="text-2xl font-bold text-ink">{{ number_format($classroomCount) }}</span>
                <span class="text-xs text-muted">Kelas teori & umum</span>
            </a>

            <a href="{{ route('admin.rooms.index', ['filter' => 'lab']) }}"
               @class([
                   'rounded-card border p-4 transition flex flex-col gap-1',
                   'border-primary/40 bg-primary-soft/20' => $filter === 'lab',
                   'border-border bg-surface hover:bg-page' => $filter !== 'lab',
               ])>
                <span class="text-xs font-medium text-muted">Laboratorium</span>
                <span class="text-2xl font-bold text-ink">{{ number_format($labCount) }}</span>
                <span class="text-xs text-muted">Komputer & praktikum</span>
            </a>

            <a href="{{ route('admin.rooms.index', ['filter' => 'ditutup']) }}"
               @class([
                   'rounded-card border p-4 transition flex flex-col gap-1',
                   'border-danger/40 bg-danger-soft/30 hover:bg-danger-soft/40' => $closedRoomsCount > 0,
                   'border-border bg-surface hover:bg-page' => $closedRoomsCount === 0,
               ])>
                <span class="text-xs font-medium text-danger">Sedang Ditutup</span>
                <span class="text-2xl font-bold text-danger">{{ number_format($closedRoomsCount) }}</span>
                <span class="text-xs text-danger/80">Pemeliharaan / ditutup</span>
            </a>
        </div>

        {{-- Pemberitahuan Penutupan Ruang Aktif --}}
        @if ($closures->isNotEmpty())
            <div class="rounded-xl border border-danger/30 bg-danger-soft/20 p-4">
                <div class="flex items-center justify-between pb-3 border-b border-danger/20">
                    <div class="flex items-center gap-2">
                        <i data-lucide="lock" class="size-4 text-danger"></i>
                        <h2 class="text-sm font-bold text-danger">{{ $closures->count() }} Penutupan Ruang Aktif</h2>
                    </div>
                    <span class="text-xs text-muted">Ruang ditutup tidak dapat dipesan</span>
                </div>
                <div class="mt-3 grid grid-cols-1 gap-2.5 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($closures as $closure)
                        <div class="flex items-center justify-between rounded-lg border border-border bg-surface p-3 shadow-xs">
                            <div class="flex flex-col gap-0.5">
                                <div class="flex items-center gap-1.5">
                                    <span class="font-bold text-ink">{{ $closure->room->name }}</span>
                                    <x-status-badge variant="danger">Ditutup</x-status-badge>
                                </div>
                                <span class="text-[11px] text-muted">{{ Wita::stamp($closure->start_at) }} – {{ Wita::stamp($closure->end_at) }}</span>
                                <span class="text-xs text-ink/80 italic font-medium">"{{ $closure->reason }}"</span>
                            </div>
                            <form method="POST" action="{{ route('admin.closures.destroy', $closure) }}" class="ml-2">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="inline-flex items-center gap-1 rounded-md border border-danger/40 bg-surface px-2.5 py-1 text-xs font-semibold text-danger hover:bg-danger hover:text-white transition" title="Buka kembali ruang ini sekarang">
                                    <i data-lucide="lock-open" class="size-3.5"></i>
                                    <span>Buka</span>
                                </button>
                            </form>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Toolbar Filter & Pencarian Cepat --}}
        <x-card class="flex flex-col gap-4">
            <div class="flex flex-wrap items-center justify-between gap-4 border-b border-border pb-4">
                {{-- Tabs Filter Status --}}
                <div class="flex flex-wrap items-center gap-2">
                    <a href="{{ route('admin.rooms.index', array_filter(['filter' => null, 'q' => $keyword, 'gedung' => $buildingFilter, 'per_page' => $perPage])) }}"
                       @class([
                           'rounded-full px-3.5 py-1.5 text-xs font-semibold transition',
                           'bg-primary text-white' => $filter === 'semua',
                           'bg-page text-muted hover:text-ink hover:bg-border' => $filter !== 'semua',
                       ])>
                        Semua ({{ $totalRooms }})
                    </a>
                    <a href="{{ route('admin.rooms.index', array_filter(['filter' => 'verifikasi', 'q' => $keyword, 'gedung' => $buildingFilter, 'per_page' => $perPage])) }}"
                       @class([
                           'rounded-full px-3.5 py-1.5 text-xs font-semibold transition',
                           'bg-warning-ink text-white' => $filter === 'verifikasi',
                           'bg-warning-soft/60 text-warning-ink hover:bg-warning-soft' => $filter !== 'verifikasi',
                       ])>
                        ⚠️ Perlu Verifikasi ({{ $unverifiedCount }})
                    </a>
                    <a href="{{ route('admin.rooms.index', array_filter(['filter' => 'kuliah', 'q' => $keyword, 'gedung' => $buildingFilter, 'per_page' => $perPage])) }}"
                       @class([
                           'rounded-full px-3.5 py-1.5 text-xs font-semibold transition',
                           'bg-primary text-white' => $filter === 'kuliah',
                           'bg-page text-muted hover:text-ink hover:bg-border' => $filter !== 'kuliah',
                       ])>
                        Ruang Kuliah ({{ $classroomCount }})
                    </a>
                    <a href="{{ route('admin.rooms.index', array_filter(['filter' => 'lab', 'q' => $keyword, 'gedung' => $buildingFilter, 'per_page' => $perPage])) }}"
                       @class([
                           'rounded-full px-3.5 py-1.5 text-xs font-semibold transition',
                           'bg-primary text-white' => $filter === 'lab',
                           'bg-page text-muted hover:text-ink hover:bg-border' => $filter !== 'lab',
                       ])>
                        Laboratorium ({{ $labCount }})
                    </a>
                    <a href="{{ route('admin.rooms.index', array_filter(['filter' => 'ditutup', 'q' => $keyword, 'gedung' => $buildingFilter, 'per_page' => $perPage])) }}"
                       @class([
                           'rounded-full px-3.5 py-1.5 text-xs font-semibold transition',
                           'bg-danger text-white' => $filter === 'ditutup',
                           'bg-danger-soft/60 text-danger hover:bg-danger-soft' => $filter !== 'ditutup',
                       ])>
                        Ditutup ({{ $closedRoomsCount }})
                    </a>
                </div>
            </div>

            {{-- Form Pencarian & Filter Gedung --}}
            <form method="GET" action="{{ route('admin.rooms.index') }}" class="grid grid-cols-1 items-end gap-3 sm:grid-cols-12">
                @if ($filter !== 'semua')
                    <input type="hidden" name="filter" value="{{ $filter }}">
                @endif

                <div class="sm:col-span-5">
                    <label class="mb-1 block text-xs font-medium text-muted">Cari Nama Ruang / Kode / Fasilitas</label>
                    <div class="relative">
                        <i data-lucide="search" class="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted"></i>
                        <input type="text" name="q" value="{{ $keyword }}" placeholder="Ketik nama ruang (contoh: AE 107, ICT)..."
                               class="w-full rounded-control border border-border bg-surface pl-9 pr-3 py-2 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                    </div>
                </div>

                <div class="sm:col-span-3">
                    <label class="mb-1 block text-xs font-medium text-muted">Filter Gedung</label>
                    <select name="gedung" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                        <option value="">Semua Gedung</option>
                        @foreach ($buildings as $b)
                            <option value="{{ $b->name }}" @selected($buildingFilter === $b->name)>Gedung {{ $b->name }}</option>
                        @endforeach
                        <option value="belum_didata" @selected($buildingFilter === 'belum_didata')>Gedung belum didata</option>
                    </select>
                </div>

                <div class="sm:col-span-2">
                    <label class="mb-1 block text-xs font-medium text-muted">Baris / Halaman</label>
                    <select name="per_page" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                        @foreach ([15, 30, 50, 100] as $n)
                            <option value="{{ $n }}" @selected($perPage == $n)>{{ $n }} baris</option>
                        @endforeach
                    </select>
                </div>

                <div class="flex gap-2 sm:col-span-2">
                    <button type="submit" class="flex h-[38px] flex-1 items-center justify-center gap-1.5 rounded-control bg-primary px-3 text-xs font-semibold text-white hover:bg-primary/90 transition" title="Terapkan Filter">
                        <i data-lucide="filter" class="size-3.5"></i>
                        <span>Filter</span>
                    </button>
                    @if ($keyword !== '' || $filter !== 'semua' || $buildingFilter !== '')
                        <a href="{{ route('admin.rooms.index') }}" class="flex h-[38px] w-[38px] shrink-0 items-center justify-center rounded-control border border-border text-muted hover:text-ink hover:bg-page transition" title="Reset Filter">
                            <i data-lucide="rotate-ccw" class="size-4"></i>
                        </a>
                    @endif
                </div>
            </form>
        </x-card>

        {{-- TABEL DATA RUANG --}}
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
                            <th class="px-4 py-3 font-semibold">Nama Ruang & Jenis</th>
                            <th class="px-4 py-3 font-semibold">Lokasi Gedung</th>
                            <th class="px-4 py-3 font-semibold text-center">Kapasitas</th>
                            <th class="px-4 py-3 font-semibold">Fasilitas</th>
                            <th class="px-4 py-3 font-semibold">Status Data</th>
                            <th class="px-4 py-3 text-right font-semibold">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($rooms as $room)
                            @php($isClosed = $closures->firstWhere('room_id', $room->id))
                            @php($needsVerification = $room->capacity === null || $room->building_id === null)

                            <tr class="transition hover:bg-primary-soft/20" :class="{ 'bg-primary-soft/30': selectedIds.includes({{ $room->id }}) }">
                                {{-- Checkbox --}}
                                <td class="px-4 py-3 text-center">
                                    <input type="checkbox"
                                           value="{{ $room->id }}"
                                           x-model="selectedIds"
                                           class="size-4 rounded border-border text-primary focus:ring-primary cursor-pointer">
                                </td>

                                {{-- Nama Ruang & Jenis --}}
                                <td class="px-4 py-3">
                                    <div class="flex flex-col">
                                        <div class="flex items-center gap-2">
                                            <span class="font-bold text-ink text-base">{{ $room->name }}</span>
                                            @if ($room->type === RoomType::Classroom)
                                                <span class="inline-flex items-center gap-1 rounded bg-page px-1.5 py-0.5 text-[10px] font-medium text-muted border border-border">
                                                    <i data-lucide="door-closed" class="size-3 text-primary"></i>
                                                    Kelas
                                                </span>
                                            @else
                                                <span class="inline-flex items-center gap-1 rounded bg-indigo-50 px-1.5 py-0.5 text-[10px] font-medium text-indigo-700 border border-indigo-200">
                                                    <i data-lucide="wrench" class="size-3 text-indigo-600"></i>
                                                    Lab
                                                </span>
                                            @endif

                                            @if ($isClosed)
                                                <span class="inline-flex items-center gap-1 rounded bg-danger-soft px-1.5 py-0.5 text-[10px] font-semibold text-danger">
                                                    <i data-lucide="lock" class="size-3"></i>
                                                    Ditutup
                                                </span>
                                            @endif
                                        </div>
                                        <span class="font-mono text-[11px] text-muted mt-0.5">Kode: {{ $room->code }}</span>
                                    </div>
                                </td>

                                {{-- Lokasi Gedung & Lantai --}}
                                <td class="px-4 py-3">
                                    @if ($room->building)
                                        <div class="flex items-center gap-1.5 text-xs text-ink">
                                            <i data-lucide="building-2" class="size-3.5 text-primary shrink-0"></i>
                                            <span class="font-semibold">{{ $room->building->name }}</span>
                                            <span class="text-muted">• Lantai {{ $room->floor ?? '—' }}</span>
                                        </div>
                                    @else
                                        <span class="inline-flex items-center gap-1 text-xs font-medium text-warning-ink">
                                            <i data-lucide="circle-alert" class="size-3.5 text-warning-ink"></i>
                                            Gedung belum didata
                                        </span>
                                    @endif
                                </td>

                                {{-- Kapasitas Kursi --}}
                                <td class="px-4 py-3 text-center">
                                    @if ($room->capacity)
                                        <span class="inline-flex items-center gap-1.5 rounded-full bg-page px-2.5 py-1 text-xs font-semibold text-ink border border-border">
                                            <i data-lucide="users" class="size-3 text-muted"></i>
                                            {{ $room->capacity }} kursi
                                        </span>
                                    @else
                                        <span class="text-xs text-muted/70 italic">Belum diisi</span>
                                    @endif
                                </td>

                                {{-- Fasilitas (Tags) --}}
                                <td class="px-4 py-3">
                                    @if ($room->facilities->isNotEmpty())
                                        <div class="flex flex-wrap gap-1 max-w-xs">
                                            @foreach ($room->facilities->take(3) as $facility)
                                                <span class="rounded bg-page px-1.5 py-0.5 text-[11px] font-medium text-ink border border-border">
                                                    {{ $facility->name }}
                                                </span>
                                            @endforeach
                                            @if ($room->facilities->count() > 3)
                                                <span class="rounded bg-page px-1 py-0.5 text-[10px] text-muted border border-border" title="{{ $room->facilities->pluck('name')->implode(', ') }}">
                                                    +{{ $room->facilities->count() - 3 }}
                                                </span>
                                            @endif
                                        </div>
                                    @else
                                        <span class="text-xs text-muted/60 italic">Tidak ada fasilitas</span>
                                    @endif
                                </td>

                                {{-- Status Data --}}
                                <td class="px-4 py-3">
                                    @if ($needsVerification)
                                        <x-status-badge variant="warning">Perlu verifikasi</x-status-badge>
                                    @else
                                        <x-status-badge variant="primary">Data simulasi</x-status-badge>
                                    @endif
                                </td>

                                {{-- Aksi Cepat --}}
                                <td class="px-4 py-3 text-right">
                                    <div class="flex items-center justify-end gap-1.5">
                                        {{-- Tombol Tutup Ruang Cepat --}}
                                        @if ($isClosed)
                                            <form method="POST" action="{{ route('admin.closures.destroy', $isClosed) }}" class="inline">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="inline-flex items-center gap-1 rounded p-1.5 text-danger hover:bg-danger-soft transition" title="Buka kembali ruang ini sekarang">
                                                    <i data-lucide="lock-open" class="size-4"></i>
                                                </button>
                                            </form>
                                        @else
                                            <button type="button"
                                                    @click="openClosureModal({{ $room->id }})"
                                                    class="rounded p-1.5 text-muted hover:bg-page hover:text-danger transition"
                                                    title="Tutup ruang sementara">
                                                <i data-lucide="lock" class="size-4"></i>
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
                                                class="rounded p-1.5 text-muted hover:bg-page hover:text-primary transition"
                                                title="Ubah data ruang">
                                            <i data-lucide="pencil" class="size-4"></i>
                                        </button>

                                        {{-- Tombol Hapus --}}
                                        <button type="button"
                                                @click="openDeleteModal({{ $room->id }}, '{{ addslashes($room->name) }}')"
                                                class="rounded p-1.5 text-muted hover:bg-page hover:text-danger transition"
                                                title="Hapus ruang">
                                            <i data-lucide="trash-2" class="size-4"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="py-12 text-center text-muted">
                                    <i data-lucide="door-closed" class="mx-auto size-8 text-muted/60 mb-2"></i>
                                    <p class="font-medium text-ink">Tidak ada ruang yang sesuai dengan filter.</p>
                                    <p class="text-xs text-muted mt-1">Coba gunakan kata kunci pencarian atau filter lain.</p>
                                    <div class="mt-4">
                                        <a href="{{ route('admin.rooms.index') }}" class="inline-flex items-center gap-1 text-xs font-semibold text-primary hover:underline">
                                            <i data-lucide="rotate-ccw" class="size-3.5"></i>
                                            Hapus semua filter
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
                <span class="text-sm font-semibold"><span x-text="selectedIds.length"></span> ruang dipilih</span>
            </div>
            <div class="h-4 w-px bg-surface/20"></div>

            {{-- 1. Batch Set Gedung AE --}}
            <button type="button" @click="submitBatch('set_building', { building: 'AE' })" class="inline-flex items-center gap-1.5 rounded-lg bg-surface/10 px-3 py-1.5 text-xs font-semibold text-surface hover:bg-surface/20 transition">
                <i data-lucide="building-2" class="size-3.5"></i>
                Set Gedung AE
            </button>

            {{-- 2. Batch Set Gedung Pinisi --}}
            <button type="button" @click="submitBatch('set_building', { building: 'Pinisi' })" class="inline-flex items-center gap-1.5 rounded-lg bg-surface/10 px-3 py-1.5 text-xs font-semibold text-surface hover:bg-surface/20 transition">
                <i data-lucide="building-2" class="size-3.5"></i>
                Set Gedung Pinisi
            </button>

            {{-- 3. Batch Set Ruang Kuliah --}}
            <button type="button" @click="submitBatch('set_type', { type: 'classroom' })" class="inline-flex items-center gap-1.5 rounded-lg bg-surface/10 px-3 py-1.5 text-xs font-semibold text-surface hover:bg-surface/20 transition">
                <i data-lucide="door-closed" class="size-3.5"></i>
                Jadikan Ruang Kuliah
            </button>

            {{-- 4. Batch Set Laboratorium --}}
            <button type="button" @click="submitBatch('set_type', { type: 'lab' })" class="inline-flex items-center gap-1.5 rounded-lg bg-surface/10 px-3 py-1.5 text-xs font-semibold text-surface hover:bg-surface/20 transition">
                <i data-lucide="wrench" class="size-3.5"></i>
                Jadikan Lab
            </button>

            {{-- 5. Batch Tambah Fasilitas Modal --}}
            <button type="button" @click="batchFacilityModalOpen = true" class="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3 py-1.5 text-xs font-semibold text-white shadow-xs hover:bg-primary/90 transition">
                <i data-lucide="plus" class="size-3.5"></i>
                Tambah Fasilitas
            </button>

            <button type="button" @click="selectedIds = []" class="text-xs text-surface/70 hover:text-white transition ml-2">
                Batal
            </button>
        </div>

        {{-- FORM TERSEMBUNYI UNTUK BATCH ACTION STANDARD --}}
        <form x-ref="batchForm" method="POST" action="{{ route('admin.rooms.batch') }}" class="hidden">
            @csrf
            <input type="hidden" name="action" x-model="batchAction">
            <input type="hidden" name="building" x-model="batchParams.building">
            <input type="hidden" name="type" x-model="batchParams.type">
            <template x-for="id in selectedIds" :key="id">
                <input type="hidden" name="room_ids[]" :value="id">
            </template>
        </form>

        {{-- MODAL 1: EDIT / TAMBAH RUANG DINAMIS TUNGGAL --}}
        <div x-show="formModalOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
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
                            <x-button variant="danger-outline" type="button" @click="openDeleteFromEdit()">Hapus ruang</x-button>
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
        <div x-show="closureModalOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
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
                            <p class="text-[11px] text-muted mt-1">Reservasi tidak dibatalkan otomatis. Kelas terdampak akan diberi notifikasi.</p>
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
        <div x-show="deleteModalOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
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

        {{-- MODAL 4: BATCH TAMBAH FASILITAS --}}
        <div x-show="batchFacilityModalOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-ink/80 backdrop-blur-sm" @click="batchFacilityModalOpen = false"></div>
            <div class="relative w-full max-w-md rounded-2xl bg-surface p-6 shadow-2xl">
                <div class="flex items-start justify-between border-b border-border pb-3">
                    <div>
                        <span class="text-xs font-semibold text-primary">Penyesuaian Massal</span>
                        <h3 class="text-lg font-bold text-ink">Tambah Fasilitas (<span x-text="selectedIds.length"></span> Ruang)</h3>
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
                        <input type="text" name="facilities" required placeholder="Contoh: AC, Proyektor" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                        <p class="mt-1 text-[11px] text-muted">Fasilitas akan ditambahkan tanpa menghapus fasilitas yang sudah ada di ruang terpilih.</p>
                    </div>

                    <div class="mt-2 flex items-center justify-end gap-2 border-t border-border pt-4">
                        <x-button variant="secondary" type="button" @click="batchFacilityModalOpen = false">Batal</x-button>
                        <x-button variant="primary" type="submit" icon="check">Terapkan ke Ruang Terpilih</x-button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function roomManager() {
            const pageRoomIds = @js($rooms->pluck('id')->all());

            return {
                selectedIds: [],
                formModalOpen: {{ ($errors->any() && old('form') !== 'closure') ? 'true' : 'false' }},
                closureModalOpen: {{ (session('closure_impact') || old('form') === 'closure') ? 'true' : 'false' }},
                deleteModalOpen: false,
                batchFacilityModalOpen: false,
                batchAction: '',
                batchParams: {
                    building: '',
                    type: ''
                },
                closureRoomId: '{{ old('room_id', '') }}',
                closureStart: '{{ old('start_at', now()->format('Y-m-d\TH:i')) }}',
                closureEnd: '{{ old('end_at', now()->addHours(4)->format('Y-m-d\TH:i')) }}',
                closureReason: '{{ old('reason', '') }}',
                roomToDelete: {
                    id: null,
                    name: ''
                },
                formRoom: {
                    id: null,
                    name: '{{ old('name', '') }}',
                    code: '{{ old('code', '') }}',
                    type: '{{ old('type', 'classroom') }}',
                    capacity: '{{ old('capacity', '') }}',
                    floor: '{{ old('floor', '') }}',
                    building: '{{ old('building', '') }}',
                    facilities: '{{ old('facilities', '') }}',
                    is_bookable: true
                },

                isAllSelected() {
                    return pageRoomIds.length > 0 && pageRoomIds.every(id => this.selectedIds.includes(id));
                },

                toggleSelectAll(checked) {
                    if (checked) {
                        pageRoomIds.forEach(id => {
                            if (!this.selectedIds.includes(id)) {
                                this.selectedIds.push(id);
                            }
                        });
                    } else {
                        this.selectedIds = this.selectedIds.filter(id => !pageRoomIds.includes(id));
                    }
                },

                openCreateModal() {
                    this.formRoom = {
                        id: null,
                        name: '',
                        code: '',
                        type: 'classroom',
                        capacity: '',
                        floor: '',
                        building: '',
                        facilities: '',
                        is_bookable: true
                    };
                    this.formModalOpen = true;
                    this.$nextTick(() => {
                        if (window.renderIcons) window.renderIcons();
                    });
                },

                openEditModal(data) {
                    this.formRoom = {
                        id: data.id,
                        name: data.name || '',
                        code: data.code || '',
                        type: data.type || 'classroom',
                        capacity: data.capacity ?? '',
                        floor: data.floor ?? '',
                        building: data.building || '',
                        facilities: data.facilities || '',
                        is_bookable: data.is_bookable ?? true
                    };
                    this.formModalOpen = true;
                    this.$nextTick(() => {
                        if (window.renderIcons) window.renderIcons();
                    });
                },

                openClosureModal(roomId) {
                    if (roomId) {
                        this.closureRoomId = roomId;
                    }
                    this.closureModalOpen = true;
                    this.$nextTick(() => {
                        if (window.renderIcons) window.renderIcons();
                    });
                },

                openDeleteModal(id, name) {
                    this.roomToDelete = { id: id, name: name };
                    this.deleteModalOpen = true;
                    this.$nextTick(() => {
                        if (window.renderIcons) window.renderIcons();
                    });
                },

                openDeleteFromEdit() {
                    if (!this.formRoom.id) return;
                    this.openDeleteModal(this.formRoom.id, this.formRoom.name);
                    this.formModalOpen = false;
                },

                addFacilityTag(tag) {
                    let current = (this.formRoom.facilities || '').split(',').map(s => s.trim()).filter(Boolean);
                    if (!current.includes(tag)) {
                        current.push(tag);
                        this.formRoom.facilities = current.join(', ');
                    }
                },

                submitBatch(action, params = {}) {
                    if (this.selectedIds.length === 0) return;
                    this.batchAction = action;
                    this.batchParams = params;
                    this.$nextTick(() => {
                        this.$refs.batchForm.submit();
                    });
                }
            };
        }
    </script>
@endsection
