@use('App\Enums\ReservationStatus')
@use('App\Http\Controllers\Admin\ReservationController')
@use('App\Support\Wita')
@extends('layouts.admin')

@section('title', 'Riwayat Reservasi')

@section('breadcrumb-actions')
    <x-button variant="secondary" :href="route('admin.dashboard')">Dashboard</x-button>
@endsection

@section('content')
    <div class="flex flex-col gap-4 admin-page-stack">
        <x-page-header title="Riwayat reservasi">
            <x-slot:actions>
                <x-button size="sm" variant="secondary" icon="download" :href="route('admin.reservations.export', request()->only(['status', 'q', 'sort', 'dir']))" download>Unduh CSV</x-button>
            </x-slot:actions>
        </x-page-header>

        <x-card padding="p-4" class="admin-table-card flex flex-col gap-3">
            <div class="flex flex-wrap items-center gap-1.5">
                @foreach (ReservationController::FILTERS as $key => $label)
                    <a href="{{ route('admin.reservations.index', array_filter(['q' => $keyword, 'status' => $key === 'semua' ? null : $key, 'per_page' => $perPage !== 15 ? $perPage : null, 'sort' => $sort !== 'start_at' ? $sort : null, 'dir' => $dir !== 'desc' ? $dir : null])) }}"
                       @class([
                           'inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold transition',
                           'bg-primary text-white shadow-2xs' => $filter === $key,
                           'bg-page text-muted hover:text-ink hover:bg-border' => $filter !== $key,
                       ])>
                        {{ $label }}
                    </a>
                @endforeach
            </div>

            <form method="GET" action="{{ route('admin.reservations.index') }}" class="flex flex-wrap items-center gap-2">
                <div class="relative flex-1 min-w-[220px]">
                    <i data-lucide="search" class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted" aria-hidden="true"></i>
                    <input type="search" name="q" value="{{ $keyword }}" data-table-search
                           @input.debounce.400ms="$el.form.requestSubmit()"
                           placeholder="Cari ruang atau kelas pemesan (tekan /)..." aria-label="Cari ruang atau kelas pemesan"
                           class="w-full rounded-control border border-border bg-surface py-2 pr-3 pl-9 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary shadow-2xs">
                    @if ($filter !== 'semua')<input type="hidden" name="status" value="{{ $filter }}">@endif
                    @if ($sort !== 'start_at')<input type="hidden" name="sort" value="{{ $sort }}">@endif
                    @if ($dir !== 'desc')<input type="hidden" name="dir" value="{{ $dir }}">@endif
                </div>
                <select name="per_page" @change="$el.form.requestSubmit()" aria-label="Baris per halaman"
                        class="rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary shadow-2xs">
                    @foreach ([15, 30, 50, 100] as $n)
                        <option value="{{ $n }}" @selected($perPage == $n)>{{ $n }} baris</option>
                    @endforeach
                </select>
                @if ($keyword !== '')
                    <a href="{{ route('admin.reservations.index', array_filter(['status' => $filter === 'semua' ? null : $filter, 'per_page' => $perPage !== 15 ? $perPage : null])) }}"
                       class="inline-flex h-[38px] items-center gap-1.5 rounded-control border border-border bg-surface px-3 text-xs font-medium text-muted hover:text-ink hover:bg-page transition shadow-2xs"
                       aria-label="Hapus pencarian">
                        <i data-lucide="x" class="size-3.5" aria-hidden="true"></i>
                        <span>Hapus</span>
                    </a>
                @endif
            </form>

            @if ($reservations->isEmpty())
                <x-empty-state title="{{ $keyword !== '' || $filter !== 'semua' ? 'Tidak ada reservasi yang cocok' : 'Belum ada reservasi' }}"
                                message="{{ $keyword !== '' || $filter !== 'semua' ? 'Coba kata kunci lain atau hapus filter status.' : 'Reservasi yang dibuat ketua tingkat akan muncul di sini.' }}">
                    @if ($keyword !== '' || $filter !== 'semua')
                        <x-button variant="soft" :href="route('admin.reservations.index')">Hapus filter</x-button>
                    @endif
                </x-empty-state>
            @else
                <x-table-scroll>
                    <x-slot:title>Halaman {{ $reservations->currentPage() }} • {{ $reservations->total() }} reservasi</x-slot:title>
                    <x-slot:headerActions>
                        <x-table-density-toggle />
                    </x-slot:headerActions>
                    <table class="w-full text-left text-sm">
                        <thead class="sticky top-0 z-10 border-b border-border bg-page text-xs font-semibold text-muted">
                            <tr>
                                <th scope="col" class="px-4 py-3 font-medium">Ruang</th>
                                <th scope="col" class="px-4 py-3 font-medium">Atas nama kelas</th>
                                <x-sortable-th column="start_at" :sort="$sort" :dir="$dir" class="px-4 py-3 font-medium">Tanggal</x-sortable-th>
                                <th scope="col" class="px-4 py-3 font-medium">Jam (WITA)</th>
                                <th scope="col" class="px-4 py-3 font-medium">Keperluan</th>
                                <x-sortable-th column="status" :sort="$sort" :dir="$dir" class="px-4 py-3 font-medium">Status</x-sortable-th>
                                <th scope="col" class="px-4 py-3 font-medium">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @foreach ($reservations as $reservation)
                                <tr>
                                    <td class="px-4 py-3 font-semibold">{{ $reservation->room->name }}</td>
                                    <td class="px-4 py-3">
                                        <span class="block">{{ $reservation->classGroup->name }}</span>
                                        <span class="text-xs text-muted">{{ $reservation->creator->name }}</span>
                                    </td>
                                    <td class="px-4 py-3">{{ Wita::date($reservation->start_at) }}</td>
                                    <td class="px-4 py-3">{{ Wita::clock($reservation->start_at, false) }}–{{ Wita::clock($reservation->end_at, false) }}</td>
                                    <td class="px-4 py-3 text-muted">
                                        {{ $reservation->purpose }}
                                        @if ($moved = $reservation->sessionReschedule)
                                            <span class="ml-1 rounded-full bg-primary-soft px-2 py-0.5 text-xs font-semibold text-primary">Pindahan</span>
                                            <span class="block text-xs">{{ $moved->session_title }} • dari {{ Wita::longDate($moved->original_start_at) }} {{ Wita::clock($moved->original_start_at, false) }}</span>
                                            <span class="block text-xs">Dosen: {{ $moved->lecturers->pluck('name')->implode(', ') }}</span>
                                        @endif
                                        @if ($reservation->status === ReservationStatus::Cancelled && $reservation->cancel_reason)
                                            <span class="block text-xs">Alasan batal: {{ $reservation->cancel_reason }}</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        <x-status-badge :variant="match ($reservation->status) {
                                            ReservationStatus::Booked => 'primary',
                                            ReservationStatus::InUse => 'danger',
                                            ReservationStatus::Finished => 'success',
                                            ReservationStatus::Cancelled => 'neutral',
                                        }">{{ $reservation->status === ReservationStatus::Booked ? 'Akan datang' : $reservation->status->label() }}</x-status-badge>
                                    </td>
                                    <td class="px-4 py-3">
                                        @if ($reservation->status === ReservationStatus::Booked)
                                            <x-button variant="danger-outline" size="sm" icon="calendar-x" x-data x-on:click="$dispatch('open-modal', 'cancel-reservation-{{ $reservation->id }}')">Batalkan</x-button>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-table-scroll>
                @if ($reservations->hasPages())
                    <div class="border-t border-border pt-3">
                        {{ $reservations->links() }}
                    </div>
                @endif
            @endif
        </x-card>
    </div>
@endsection

@push('dialogs')
    @foreach ($reservations as $reservation)
        @if ($reservation->status === ReservationStatus::Booked)
            <x-confirm-dialog name="cancel-reservation-{{ $reservation->id }}"
                              :title="'Batalkan reservasi '.$reservation->room->name.'?'"
                              :message="$reservation->classGroup->name.' • '.Wita::date($reservation->start_at).', '.Wita::range($reservation->start_at, $reservation->end_at).'. Slot terbuka lagi untuk kelas lain.'"
                              :action="route('admin.reservations.cancel', $reservation)"
                              confirm="Batalkan reservasi"
                              cancel="Kembali"
                              icon="calendar-x"
                              reason="Alasan pembatalan"
                              :reason-required="true"
                              reason-placeholder="Contoh: ruang dipakai kegiatan jurusan" />
        @endif
    @endforeach
@endpush
