{{--
    Modal konfirmasi "Pesan ruang" yang dipakai bersama oleh Cari Ruangan, Booking Ruangan, Detail Ruangan,
    dan Pemesanan gagal. Dibuka dengan $dispatch('open-booking', BookingPayload::make(...)).
    Variabel: $academicAllowed (bool, tanggal hari kuliah), $initial (payload awal, opsional), $idempotencyKey (opsional).
--}}
@php($initial ??= null)
<div x-data="bookingModal(@js(['purpose' => $bookingDefaultPurpose, 'key' => $idempotencyKey ?? null, 'initial' => $initial]))"
     x-on:open-booking.window="show($event.detail)"
     x-on:keydown.escape.window="close()">
    <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-8"
         data-modal-name="pesan-ruang" role="dialog" aria-modal="true" aria-labelledby="booking-modal-title">
        <div class="absolute inset-0 bg-ink/80" x-on:click="close()"></div>
        <div x-show="open" x-transition class="relative w-full max-w-lg rounded-2xl bg-surface p-8 shadow-xl">
            <div class="mb-5 flex items-start justify-between gap-4">
                <h2 id="booking-modal-title" class="text-xl font-semibold text-ink">Pesan <span x-text="room ? room.roomName : ''">{{ $initial['roomName'] ?? 'ruang' }}</span></h2>
                <button type="button" x-on:click="close()" class="text-muted hover:text-ink" aria-label="Tutup">
                    <i data-lucide="x" class="size-5"></i>
                </button>
            </div>

            <form method="POST" action="{{ route('keti.reservations.store') }}" class="flex flex-col gap-4" x-on:submit="submitting = true">
                @csrf
                <input type="hidden" name="room_id" value="{{ $initial['roomId'] ?? '' }}" x-bind:value="room ? room.roomId : ''">
                <input type="hidden" name="start_at" value="{{ $initial['startAt'] ?? '' }}" x-bind:value="room ? room.startAt : ''">
                <input type="hidden" name="duration" value="{{ $initial['duration'] ?? '' }}" x-bind:value="room ? room.duration : ''">
                <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey ?? '' }}" x-bind:value="key">

                <x-info-box :title="'Kelas '.($bookingClassGroup?->name ?? '—')">
                    <p class="font-semibold text-ink" x-text="room ? room.dateLabel + ' • ' + room.rangeLabel : ''">{{ isset($initial) ? $initial['dateLabel'].' • '.$initial['rangeLabel'] : '' }}</p>
                    <p class="text-xs text-muted" x-text="room ? room.durationLabel + ' • ' + (room.capacity ? 'Kapasitas ' + room.capacity + ' kursi' : 'Kapasitas belum didata') : ''"></p>
                </x-info-box>

                <x-field label="Kegiatan" name="purpose">
                    <x-select name="purpose" id="booking-purpose" :options="$bookingPurposes" :value="$bookingDefaultPurpose" :disabled-options="$bookingDisabledPurposes" x-model="purpose" required />
                </x-field>
                @unless ($academicAllowed)
                    <p class="text-sm text-muted">Praktikum Tambahan hanya Senin–Jumat. Pada Sabtu/Minggu, ruangan dapat dipesan untuk kegiatan umum sesuai ketersediaan.</p>
                @endunless

                <div class="grid grid-cols-2 gap-3">
                    <x-button variant="secondary" x-on:click="close()" x-bind:disabled="submitting">Batal</x-button>
                    <x-button type="submit" x-bind:disabled="submitting">
                        <span x-show="! submitting">Pesan sekarang</span>
                        <span x-show="submitting" x-cloak>Memproses…</span>
                    </x-button>
                </div>
            </form>
        </div>
    </div>
</div>
