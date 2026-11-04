@use('App\Enums\ReservationStatus')
@use('App\Http\Controllers\Admin\ReservationController')
@use('App\Support\Wita')
@extends('layouts.admin')

@section('title', 'Riwayat Reservasi')

@section('breadcrumb-actions')
    <x-button variant="secondary" :href="route('admin.dashboard')">Dashboard</x-button>
@endsection

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header title="Reservasi ruang" subtitle="Reservasi tidak perlu persetujuan. Siapa cepat dia dapat, dan admin hanya dapat membatalkan." />

        <x-card class="flex flex-col gap-4">
            <form method="GET" action="{{ route('admin.reservations.index') }}" class="relative">
                <i data-lucide="search" class="pointer-events-none absolute top-1/2 left-4 size-5 -translate-y-1/2 text-muted"></i>
                <input type="search" name="q" value="{{ $keyword }}" placeholder="Cari ruang atau kelas pemesan" aria-label="Cari ruang atau kelas pemesan"
                       class="w-full rounded-control border border-muted/60 bg-surface py-3 pr-4 pl-12 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/15">
                @if ($filter !== 'semua')<input type="hidden" name="status" value="{{ $filter }}">@endif
            </form>

            <x-tabs :items="collect(ReservationController::FILTERS)->map(fn ($label, $key) => [
                'label' => $label,
                'href' => route('admin.reservations.index', array_filter(['q' => $keyword, 'status' => $key === 'semua' ? null : $key])),
                'active' => $filter === $key,
            ])->values()->all()" />

            @if ($reservations->isEmpty())
                <x-empty-state title="{{ $keyword !== '' || $filter !== 'semua' ? 'Tidak ada reservasi yang cocok' : 'Belum ada reservasi' }}"
                               message="{{ $keyword !== '' || $filter !== 'semua' ? 'Coba kata kunci lain atau hapus filter status.' : 'Reservasi yang dibuat ketua tingkat akan muncul di sini.' }}">
                    @if ($keyword !== '' || $filter !== 'semua')
                        <x-button variant="soft" :href="route('admin.reservations.index')">Hapus filter</x-button>
                    @endif
                </x-empty-state>
            @else
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-border text-xs text-muted">
                        <tr>
                            <th scope="col" class="py-3 pr-4 font-medium">Ruang</th>
                            <th scope="col" class="py-3 pr-4 font-medium">Atas nama kelas</th>
                            <th scope="col" class="py-3 pr-4 font-medium">Tanggal</th>
                            <th scope="col" class="py-3 pr-4 font-medium">Jam (WITA)</th>
                            <th scope="col" class="py-3 pr-4 font-medium">Keperluan</th>
                            <th scope="col" class="py-3 pr-4 font-medium">Status</th>
                            <th scope="col" class="py-3 font-medium">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @foreach ($reservations as $reservation)
                            <tr>
                                <td class="py-3 pr-4 font-semibold">{{ $reservation->room->name }}</td>
                                <td class="py-3 pr-4">
                                    <span class="block">{{ $reservation->classGroup->name }}</span>
                                    <span class="text-xs text-muted">{{ $reservation->creator->name }}</span>
                                </td>
                                <td class="py-3 pr-4">{{ Wita::date($reservation->start_at) }}</td>
                                <td class="py-3 pr-4">{{ Wita::clock($reservation->start_at, false) }}–{{ Wita::clock($reservation->end_at, false) }}</td>
                                <td class="py-3 pr-4 text-muted">
                                    {{ $reservation->purpose }}
                                    @if ($reservation->status === ReservationStatus::Cancelled && $reservation->cancel_reason)
                                        <span class="block text-xs">Alasan batal: {{ $reservation->cancel_reason }}</span>
                                    @endif
                                </td>
                                <td class="py-3 pr-4">
                                    <x-status-badge :variant="match ($reservation->status) {
                                        ReservationStatus::Booked => 'primary',
                                        ReservationStatus::InUse => 'danger',
                                        ReservationStatus::Finished => 'success',
                                        ReservationStatus::Cancelled => 'neutral',
                                    }">{{ $reservation->status === ReservationStatus::Booked ? 'Akan datang' : $reservation->status->label() }}</x-status-badge>
                                </td>
                                <td class="py-3">
                                    @if ($reservation->status === ReservationStatus::Booked)
                                        <x-button variant="danger-outline" class="px-4! py-2!" x-data x-on:click="$dispatch('open-modal', 'cancel-reservation-{{ $reservation->id }}')">Batalkan</x-button>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="flex items-center justify-between text-sm text-muted">
                    <span>Halaman {{ $reservations->currentPage() }} • {{ $reservations->total() }} reservasi</span>
                    {{ $reservations->links() }}
                </div>
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
