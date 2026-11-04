@use('App\Enums\RoomType')
@use('App\Support\Wita')
@extends('layouts.admin')

@section('title', 'Kelola Ruang')

@section('breadcrumb-actions')
    <x-button variant="secondary" :href="route('admin.dashboard')">Dashboard</x-button>
@endsection

@section('content')
    @php
        $openForm = old('form');
        $typeOptions = collect(RoomType::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all();
    @endphp

    <div class="flex flex-col gap-6">
        <x-page-header title="Data ruang"
                        :subtitle="$totalRooms.' ruang terdaftar'.($periodName ? ' dari jadwal '.$periodName : '').'. Lengkapi kapasitas dan lokasi tiap ruang.'">
            <x-slot:actions>
                <x-button variant="secondary" icon="lock" x-data x-on:click="$dispatch('open-modal', 'close-room')">Tutup ruang</x-button>
                <x-button icon="plus" x-data x-on:click="$dispatch('open-modal', 'add-room')">Tambah ruang</x-button>
            </x-slot:actions>
        </x-page-header>

        <x-info-box title="Lengkapi kapasitas dan fasilitas ruang">
            <p>Kapasitas dan fasilitas tetap berupa data simulasi sampai diverifikasi. Nilai yang belum diketahui ditandai "Perlu verifikasi", bukan 0.</p>
        </x-info-box>

        @if ($closures->isNotEmpty())
            <x-card class="flex flex-col gap-3">
                <h2 class="text-lg font-semibold">Penutupan ruang</h2>
                @foreach ($closures as $closure)
                    <div class="flex items-center justify-between rounded-control border border-border px-4 py-3">
                        <div class="flex flex-col gap-1">
                            <span class="font-semibold">{{ $closure->room->name }} <x-status-badge variant="danger">Ditutup</x-status-badge></span>
                            <span class="text-sm text-muted">{{ Wita::stamp($closure->start_at) }} – {{ Wita::stamp($closure->end_at) }} • {{ $closure->reason }}</span>
                        </div>
                        <form method="POST" action="{{ route('admin.closures.destroy', $closure) }}">
                            @csrf
                            @method('DELETE')
                            <x-button type="submit" variant="danger-outline">Buka kembali</x-button>
                        </form>
                    </div>
                @endforeach
            </x-card>
        @endif

        <x-card class="flex flex-col gap-4">
            <form method="GET" action="{{ route('admin.rooms.index') }}" class="flex items-center gap-3">
                <div class="relative flex-1">
                    <i data-lucide="search" class="pointer-events-none absolute top-1/2 left-4 size-5 -translate-y-1/2 text-muted"></i>
                    <input type="search" name="q" value="{{ $keyword }}" placeholder="Cari nama ruang" aria-label="Cari nama ruang"
                           class="w-full rounded-control border border-muted/60 bg-surface py-3 pr-4 pl-12 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/15">
                </div>
                @if ($type)<input type="hidden" name="jenis" value="{{ $type->value }}">@endif
            </form>
            <x-tabs :items="[
                ['label' => 'Semua', 'href' => route('admin.rooms.index', array_filter(['q' => $keyword])), 'active' => $type === null],
                ['label' => 'Ruang kuliah', 'href' => route('admin.rooms.index', array_filter(['q' => $keyword, 'jenis' => 'classroom'])), 'active' => $type === RoomType::Classroom],
                ['label' => 'Laboratorium', 'href' => route('admin.rooms.index', array_filter(['q' => $keyword, 'jenis' => 'lab'])), 'active' => $type === RoomType::Lab],
            ]" />

            @if ($rooms->isEmpty())
                <x-empty-state title="Ruang tidak ditemukan" message="Coba kata kunci lain atau hapus filter jenis ruang.">
                    <x-button variant="soft" :href="route('admin.rooms.index')">Hapus filter</x-button>
                </x-empty-state>
            @else
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-border text-xs text-muted">
                        <tr>
                            <th scope="col" class="py-3 pr-4 font-medium">Nama ruang</th>
                            <th scope="col" class="py-3 pr-4 font-medium">Lokasi</th>
                            <th scope="col" class="py-3 pr-4 font-medium">Jenis</th>
                            <th scope="col" class="py-3 pr-4 font-medium">Kapasitas</th>
                            <th scope="col" class="py-3 pr-4 font-medium">Fasilitas</th>
                            <th scope="col" class="py-3 pr-4 font-medium">Status data</th>
                            <th scope="col" class="py-3 font-medium"><span class="sr-only">Aksi</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @foreach ($rooms as $room)
                            <tr>
                                <td class="py-3 pr-4 font-semibold">{{ $room->name }}</td>
                                <td class="py-3 pr-4 text-muted">{{ $room->building?->name ?? 'Gedung belum didata' }} • Lantai {{ $room->floor ?? '—' }}</td>
                                <td class="py-3 pr-4 text-muted">{{ $room->type->label() }}</td>
                                <td class="py-3 pr-4 text-muted">{{ $room->capacity ? $room->capacity.' kursi' : '—' }}</td>
                                <td class="py-3 pr-4 text-muted">{{ $room->facilities->pluck('name')->implode(', ') ?: '—' }}</td>
                                <td class="py-3 pr-4">
                                    @if ($room->capacity === null || $room->building_id === null)
                                        <x-status-badge variant="warning">Perlu verifikasi</x-status-badge>
                                    @else
                                        <x-status-badge variant="primary">Data simulasi</x-status-badge>
                                    @endif
                                </td>
                                <td class="py-3 text-right">
                                    <button type="button" x-data x-on:click="$dispatch('open-modal', 'edit-room-{{ $room->id }}')"
                                            class="inline-flex size-11 items-center justify-center rounded-control border border-border text-primary hover:bg-primary-soft/60"
                                            aria-label="Ubah {{ $room->name }}">
                                        <i data-lucide="pencil" class="size-5"></i>
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-card>
    </div>
@endsection

@push('dialogs')
    <x-modal name="add-room" title="Tambah ruang" :show="$openForm === 'add'" width="max-w-[480px]">
        <p class="-mt-3 mb-5 text-sm text-muted">Ruang baru langsung muncul di daftar.</p>
        @include('admin.rooms.partials.form', ['room' => null, 'action' => route('admin.rooms.store'), 'method' => 'POST', 'form' => 'add', 'typeOptions' => $typeOptions])
    </x-modal>

    @foreach ($rooms as $room)
        <x-modal name="edit-room-{{ $room->id }}" title="Ubah ruang" :show="$openForm === 'edit-'.$room->id" width="max-w-[480px]">
            <p class="-mt-3 mb-5 text-sm text-muted">Perubahan langsung dipakai di aplikasi ketua tingkat.</p>
            @include('admin.rooms.partials.form', ['room' => $room, 'action' => route('admin.rooms.update', $room), 'method' => 'PUT', 'form' => 'edit-'.$room->id, 'typeOptions' => $typeOptions])
        </x-modal>

        <x-confirm-dialog name="delete-room-{{ $room->id }}"
                          :title="'Hapus ruang '.$room->name.'?'"
                          message="Ruang ini akan hilang dari daftar. Ruang yang masih dipakai reservasi aktif atau jadwal resmi tidak dapat dihapus."
                          :action="route('admin.rooms.destroy', $room)"
                          method="DELETE"
                          confirm="Hapus ruang"
                          reason="Alasan (opsional)" />
    @endforeach

    <x-modal name="close-room" title="Tutup ruang" :show="$openForm === 'closure' || session()->has('closure_impact')" width="max-w-[480px]">
        <p class="-mt-3 mb-5 text-sm text-muted">Ruang yang ditutup tampil "Digunakan" dan tidak bisa dipesan selama penutupan.</p>
        <form method="POST" action="{{ route('admin.closures.store') }}" class="flex flex-col gap-4">
            @csrf
            <input type="hidden" name="form" value="closure">
            <x-field label="Ruang" name="room_id">
                <x-select name="room_id" :options="$allRooms" placeholder="Pilih ruang" required />
            </x-field>
            <div class="grid grid-cols-2 gap-3">
                <x-field label="Mulai" name="start_at">
                    <x-input type="datetime-local" name="start_at" required />
                </x-field>
                <x-field label="Selesai" name="end_at">
                    <x-input type="datetime-local" name="end_at" required />
                </x-field>
            </div>
            <x-field label="Alasan" name="reason">
                <x-input name="reason" placeholder="Contoh: perawatan AC" required />
            </x-field>

            @if (session('closure_impact'))
                <div class="flex flex-col gap-2 rounded-card bg-danger-soft px-4 py-4 text-sm">
                    <p class="font-semibold text-danger">{{ count(session('closure_impact')) }} reservasi terdampak</p>
                    @foreach (session('closure_impact') as $impact)
                        <p class="text-ink">{{ $impact['class'] }} • {{ $impact['time'] }} • {{ $impact['purpose'] }}</p>
                    @endforeach
                    <p class="text-muted">Reservasi tidak dibatalkan otomatis. Kelas terdampak akan diberi tahu; batalkan reservasinya di Riwayat Reservasi bila perlu.</p>
                    <label class="mt-1 flex items-center gap-2 text-ink">
                        <input type="checkbox" name="confirm_impact" value="1" class="size-4 accent-primary" required>
                        Saya mengerti dan tetap menutup ruang
                    </label>
                </div>
            @endif

            <div class="mt-2 flex justify-end gap-3">
                <x-button variant="secondary" x-on:click="$dispatch('close-modal', 'close-room')">Batal</x-button>
                <x-button type="submit">Tutup ruang</x-button>
            </div>
        </form>
    </x-modal>
@endpush
