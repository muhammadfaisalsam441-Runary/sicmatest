@use('App\Enums\ReservationStatus')
@use('App\Support\Wita')
@use('Illuminate\Support\Str')
@extends('layouts.keti')

@php
    $status = $reservation->status;
    [$title, $subtitle] = match ($status) {
        ReservationStatus::Booked => ['Ruangan berhasil dipesan', 'Pesananmu siap digunakan.'],
        ReservationStatus::InUse => ['Sedang menggunakan ruangan', 'Waktu pemakaianmu mulai tercatat.'],
        ReservationStatus::Finished => ['Penggunaan selesai', 'Terima kasih. Pemakaianmu sudah tercatat.'],
        ReservationStatus::Cancelled => ['Pesanan dibatalkan', 'Informasi ruangan dan pemakaian kelasmu.'],
    };
    $remaining = $status === ReservationStatus::InUse ? max(0, (int) now()->diffInMinutes($reservation->end_at, false)) : null;
    $room = $reservation->room;
    $academicBookingBlocked = ! in_array($reservation->start_at->dayOfWeekIso, config('sicma.scheduler.days'), true)
        && in_array(Str::lower(Str::squish($reservation->purpose)), array_map(fn ($purpose) => Str::lower(Str::squish($purpose)), config('sicma.reservation.academic_purposes')), true);
    if ($academicBookingBlocked && $status === ReservationStatus::Booked) {
        $subtitle = 'Pilih kembali hari kuliah yang sesuai.';
    }
    // Pengganti pertemuan kuliah yang dipindah Keti.
    $reschedule = $reservation->sessionReschedule;
    if ($reschedule && $status === ReservationStatus::Booked) {
        [$title, $subtitle] = ['Pertemuan berhasil dipindahkan', 'Kuliah pengganti siap digunakan.'];
    }
@endphp

@section('title', $title)

@section('content')
    <div class="flex flex-col gap-6"
         @if ($status->isActive()) x-data="statusPolling({ url: @js(route('keti.reservations.status', $reservation)), interval: {{ (int) config('sicma.polling_seconds') * 1000 }}, version: {{ $reservation->lock_version }}, status: @js($status->value) })" @endif>
        <x-page-header :title="$title" :subtitle="$subtitle" />
        @if ($reschedule)
            <x-info-box :title="'Kuliah Pengganti: '.$reschedule->session_title">
                <p>Dipindah dari {{ Wita::dayName($reschedule->original_start_at) }}, {{ Wita::longDate($reschedule->original_start_at) }} • {{ Wita::range($reschedule->original_start_at, $reschedule->original_end_at) }} • {{ $reschedule->originalRoom->name }}</p>
                <p>Dosen: {{ $reschedule->lecturers->map(fn ($lecturer) => $lecturer->name.' ('.Str::lower(\App\Enums\TeachingRole::from($lecturer->pivot->role)->label()).')')->implode(', ') }}</p>
            </x-info-box>
        @endif
        @if ($academicBookingBlocked && $status->isActive())
            <x-info-box title="Reservasi perkuliahan di luar hari kuliah">
                <p>Kuliah Pengganti dan Praktikum Tambahan hanya Senin–Jumat. Reservasi ini tidak bisa dimulai atau diperpanjang. Batalkan pesanan yang belum digunakan, lalu pesan ulang pada hari kuliah.</p>
            </x-info-box>
        @endif

        @if ($status === ReservationStatus::Cancelled)
            <x-empty-state title="Pesanan dibatalkan" message="Pembatalan tercatat. Kamu dapat mencari ruangan lain." icon="check">
                <x-button :href="route('keti.search')">Cari ruangan lagi</x-button>
            </x-empty-state>
            <x-card class="flex items-center justify-between">
                <span class="text-sm text-muted">{{ $room->name }} • {{ Wita::date($reservation->start_at) }} {{ Wita::range($reservation->start_at, $reservation->end_at) }} • {{ $reservation->purpose }}</span>
                <span class="text-sm text-muted">Dibatalkan {{ Wita::stamp($reservation->cancelled_at) }}{{ $reservation->cancel_reason ? ' • '.$reservation->cancel_reason : '' }}</span>
            </x-card>
        @else
            <div class="grid grid-cols-[1fr_390px] items-start gap-6">
                <x-card class="flex flex-col gap-5">
                    <div class="flex items-start justify-between">
                        <div class="flex flex-col gap-3">
                            <h2 class="text-3xl font-medium">{{ $room->name }}</h2>
                            <p class="text-sm text-muted">{{ ($room->building?->name ?? 'Gedung belum didata').' • Lantai '.($room->floor ?? '—') }}</p>
                        </div>
                        <x-status-badge :variant="$status->badgeVariant()">{{ $status->label() }}</x-status-badge>
                    </div>
                    <dl class="flex items-end justify-between">
                        <div class="flex flex-col gap-1">
                            <dt class="text-xs text-muted">Mulai</dt>
                            <dd class="text-xl font-medium">{{ Wita::clock($reservation->actual_start_at ?? $reservation->start_at) }}</dd>
                        </div>
                        <div class="flex flex-col items-end gap-1">
                            <dt class="text-xs text-muted">{{ match ($status) { ReservationStatus::Booked => 'Selesai rencana', ReservationStatus::InUse => 'Batas penggunaan', default => 'Selesai' } }}</dt>
                            <dd class="text-xl font-medium">{{ Wita::clock($reservation->actual_end_at ?? $reservation->end_at) }}</dd>
                        </div>
                    </dl>
                    <p class="font-semibold text-primary">{{ $reservation->creator->name }} • Kelas {{ $reservation->classGroup->name }}</p>
                    <p class="text-sm text-muted">{{ Wita::longDate($reservation->start_at) }} • {{ $reservation->purpose }}</p>
                    <hr class="border-border">

                    <div class="grid grid-cols-2 gap-3">
                        @if ($status === ReservationStatus::Booked && $canManage)
                            @unless ($academicBookingBlocked)
                                <form method="POST" action="{{ route('keti.reservations.start', $reservation) }}">
                                    @csrf
                                    <x-button type="submit" class="w-full">Mulai gunakan</x-button>
                                </form>
                            @endunless
                            <x-button variant="danger-outline" x-data x-on:click="$dispatch('open-modal', 'batal-pesanan')">{{ $reschedule ? 'Batalkan pemindahan' : 'Batalkan' }}</x-button>
                            @if ($reschedule && $reschedule->schedule_session_id)
                                <x-button variant="secondary" class="col-span-2" :href="route('keti.meetings.reschedule', ['sesi' => $reschedule->schedule_session_id, 'tanggal' => $reschedule->original_start_at->toDateString(), 'pengganti' => $reservation->id])">Pindahkan lagi</x-button>
                            @endif
                        @elseif ($status === ReservationStatus::InUse && $canManage)
                            <form method="POST" action="{{ route('keti.reservations.finish', $reservation) }}">
                                @csrf
                                <x-button type="submit" class="w-full">Selesai menggunakan</x-button>
                            </form>
                            <x-button variant="secondary" :href="route('keti.rooms.show', $room)">Detail ruangan</x-button>
                        @else
                            <x-button :href="route('keti.search')">Cari ruangan lagi</x-button>
                            <x-button variant="secondary" :href="route('keti.activity')">Lihat aktivitas</x-button>
                        @endif
                    </div>
                </x-card>

                <x-card class="flex flex-col gap-4">
                    @if ($status === ReservationStatus::Booked && $academicBookingBlocked)
                        <h2 class="text-xl font-semibold">Pilih hari kuliah</h2>
                        <p class="text-sm text-ink">Pesan ulang untuk Senin–Jumat melalui menu Booking Ruangan.</p>
                        <x-button :href="route('keti.rooms.calendar')">Buka Booking Ruangan</x-button>
                    @elseif ($status === ReservationStatus::Booked)
                        <h2 class="text-xl font-semibold">Sudah berada di ruangan?</h2>
                        <p class="text-sm text-ink">Tekan Mulai gunakan untuk mencatat awal pemakaian. Batalkan jika ruangan tidak jadi dipakai.</p>
                        <p class="text-xs text-muted">Mulai gunakan dapat ditekan mulai {{ Wita::clock($reservation->start_at->copy()->subMinutes((int) config('sicma.reservation.checkin_open_minutes_before'))) }}.</p>
                    @elseif ($status === ReservationStatus::InUse)
                        <h2 class="text-xl font-semibold">Sisa waktu penggunaan</h2>
                        <p class="text-3xl font-semibold">{{ Wita::duration($remaining) }}</p>
                        <p class="text-sm text-ink">Selesaikan penggunaan setelah meninggalkan ruangan.</p>
                        @if ($canManage)
                            <div class="grid grid-cols-2 gap-3">
                                @unless ($academicBookingBlocked)
                                    <x-button variant="secondary" x-data x-on:click="$dispatch('open-modal', 'perpanjang')">+{{ config('sicma.reservation.extend_minutes') }} menit</x-button>
                                @endunless
                                <x-button variant="secondary" x-data x-on:click="$dispatch('open-modal', 'lapor-kendala')">Lapor kendala</x-button>
                            </div>
                        @endif
                    @else
                        <h2 class="text-xl font-semibold">Pemakaian sudah tercatat</h2>
                        <p class="text-sm text-ink">Buka Aktivitas Saya untuk melihat kembali riwayat penggunaanmu.</p>
                    @endif
                    <x-button variant="soft" class="w-full" :href="route('keti.activity', ['cakupan' => 'saya'])">Aktivitas saya</x-button>
                </x-card>
            </div>

            @if ($status === ReservationStatus::Finished)
                <x-card class="flex flex-col gap-4">
                    <h2 class="text-lg font-semibold">Riwayat penggunaan terbaru</h2>
                    <x-data-table :headers="['Pengguna', 'Ruangan', 'Kegiatan / kelas', 'Waktu', 'Status']" class="border-0">
                        @foreach ($recent as $item)
                            <tr>
                                <td class="px-5 py-3">Kelas {{ $reservation->classGroup->name }}</td>
                                <td class="px-5 py-3">{{ $item->room->name }}</td>
                                <td class="px-5 py-3">{{ $item->purpose }}</td>
                                <td class="px-5 py-3">{{ Wita::date($item->start_at) }} • {{ Wita::range($item->actual_start_at ?? $item->start_at, $item->actual_end_at ?? $item->end_at) }}</td>
                                <td class="px-5 py-3"><x-status-badge variant="success">Selesai</x-status-badge></td>
                            </tr>
                        @endforeach
                    </x-data-table>
                </x-card>
            @endif
        @endif
    </div>
@endsection

@push('dialogs')
    @if ($status === ReservationStatus::Booked && $reschedule)
        <x-confirm-dialog name="batal-pesanan" title="Batalkan pemindahan {{ $reschedule->session_title }}?"
                          message="Pertemuan kembali ke {{ Wita::date($reschedule->original_start_at) }}, {{ Wita::range($reschedule->original_start_at, $reschedule->original_end_at) }} di {{ $reschedule->originalRoom->name }}."
                          :action="route('keti.reservations.cancel', $reservation)" icon="circle-alert" reason="Alasan (opsional)"
                          confirm="Batalkan pemindahan" cancel="Kembali" />
    @elseif ($status === ReservationStatus::Booked)
        <x-confirm-dialog name="batal-pesanan" title="Batalkan pesanan {{ $room->name }}?"
                          message="{{ Wita::date($reservation->start_at) }}, {{ Wita::range($reservation->start_at, $reservation->end_at) }}. Slot terbuka lagi untuk kelas lain."
                          :action="route('keti.reservations.cancel', $reservation)" icon="circle-alert" reason="Alasan (opsional)"
                          confirm="Batalkan pesanan" cancel="Kembali" />
    @endif
    @if ($status === ReservationStatus::InUse)
        <x-modal name="perpanjang" width="max-w-md">
            <form method="POST" action="{{ route('keti.reservations.extend', $reservation) }}" class="flex flex-col gap-4">
                @csrf
                <h2 class="text-xl font-semibold">Perpanjang pemakaian</h2>
                <p class="text-sm text-muted">Tambahan {{ config('sicma.reservation.extend_minutes') }} menit tersedia selama tidak berbenturan dengan jadwal berikutnya.</p>
                <x-info-box title="{{ $room->name }}"><p>Batas baru: {{ Wita::clock($reservation->end_at->copy()->addMinutes((int) config('sicma.reservation.extend_minutes'))) }}</p></x-info-box>
                <div class="grid grid-cols-2 gap-3">
                    <x-button variant="secondary" x-on:click="$dispatch('close-modal', 'perpanjang')">Kembali</x-button>
                    <x-button type="submit">Perpanjang {{ config('sicma.reservation.extend_minutes') }} menit</x-button>
                </div>
            </form>
        </x-modal>
        <x-modal name="lapor-kendala" width="max-w-md">
            <form method="POST" action="{{ route('keti.reservations.report', $reservation) }}" class="flex flex-col gap-4">
                @csrf
                <h2 class="text-xl font-semibold">Lapor kendala</h2>
                <p class="text-sm text-muted">Pilih kendala fasilitas yang kamu temukan. Laporan akan dicatat untuk pengelola.</p>
                <x-field label="{{ $room->name }}" name="issue">
                    <x-select name="issue" :options="collect(config('sicma.facility_issues'))->mapWithKeys(fn ($issue) => [$issue => $issue])" required />
                </x-field>
                <x-field label="Catatan (opsional)" name="note"><x-textarea name="note" rows="2" /></x-field>
                <div class="grid grid-cols-2 gap-3">
                    <x-button variant="secondary" x-on:click="$dispatch('close-modal', 'lapor-kendala')">Batal</x-button>
                    <x-button type="submit">Kirim laporan</x-button>
                </div>
            </form>
        </x-modal>
    @endif
@endpush
