{{--
    Modal konfirmasi "Pesan ruang" yang dipakai bersama oleh Cari Ruangan, Slot Kosong Ruangan, Detail Ruangan,
    dan Pemesanan gagal. Dibuka dengan $dispatch('open-booking', BookingPayload::make(...)).
    Variabel: $academicAllowed (bool, tanggal hari kuliah), $initial (payload awal, opsional), $idempotencyKey (opsional).
--}}
@php($initial ??= null)
<div x-data="bookingModal(@js(['purpose' => $bookingDefaultPurpose, 'key' => $idempotencyKey ?? null, 'initial' => $initial]))"
     x-on:open-booking.window="show($event.detail)"
     x-on:open-modal.window="if ($event.detail === 'pesan-ruang' && room) { open = true; }">
    <dialog x-ref="bookingDialog"
            closedby="any"
            data-modal-name="pesan-ruang"
            role="dialog"
            aria-labelledby="booking-modal-title"
            x-effect="if (open) { if (!$el.open) $el.showModal(); } else { if ($el.open) $el.close(); }"
            x-on:cancel="if (submitting) $event.preventDefault()"
            x-on:close="if (open) close()"
            x-on:click="if ($event.target === $el) close()"
            class="m-auto max-h-[90dvh] w-[calc(100%-1.5rem)] max-w-xl overflow-y-auto rounded-modal border border-border bg-surface p-0 text-ink shadow-2xl backdrop:bg-ink/80 sm:w-[calc(100%-2rem)]">
        <div class="p-5 sm:p-6">
            <div class="mb-5 flex items-start justify-between gap-4">
                <h2 id="booking-modal-title" class="text-xl font-semibold text-ink">Pesan <span x-text="room ? room.roomName : ''">{{ $initial['roomName'] ?? 'ruang' }}</span></h2>
                <button type="button" x-on:click="close()" class="inline-flex size-10 shrink-0 cursor-pointer items-center justify-center rounded-control text-muted transition hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary" aria-label="Tutup">
                    <i data-lucide="x" aria-hidden="true" class="size-5"></i>
                </button>
            </div>

            <form method="POST" action="{{ route('keti.reservations.store') }}" class="flex flex-col gap-4" x-on:submit="submitting = true" x-on:sicma:form-error.window="submitting = false" x-on:sicma:form-complete.window="submitting = false">
                @csrf
                @if ($errors->any())
                    <x-alert variant="danger">{{ $errors->first() }}</x-alert>
                @endif
                <input type="hidden" name="room_id" value="{{ $initial['roomId'] ?? '' }}" x-bind:value="room ? room.roomId : ''">
                <input type="hidden" name="start_at" value="{{ $initial['startAt'] ?? '' }}" x-bind:value="room ? room.startAt : ''">
                <input type="hidden" name="duration" value="{{ $initial['duration'] ?? '' }}" x-bind:value="room ? room.duration : ''">
                <input type="hidden" name="participants" value="{{ $initial['participants'] ?? '' }}" x-bind:value="room && room.participants ? room.participants : ''">
                <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey ?? '' }}" x-bind:value="key">

                <x-info-box :title="'Kelas '.($bookingClassGroup?->name ?? '—')">
                    <p class="font-semibold text-ink" x-text="room ? room.dateLabel + ' • ' + room.rangeLabel : ''">{{ isset($initial) ? $initial['dateLabel'].' • '.$initial['rangeLabel'] : '' }}</p>
                    <p class="text-xs text-muted" x-text="room ? room.durationLabel + ' • ' + (room.capacity ? 'Kapasitas ' + room.capacity + ' kursi' : 'Kapasitas belum didata') + (room.participants ? ' • ' + room.participants + ' orang' : '') : ''"></p>
                </x-info-box>

                <x-field label="Kegiatan" name="purpose">
                    <x-select name="purpose" id="booking-purpose" :options="$bookingPurposes" :value="$bookingDefaultPurpose" :disabled-options="$bookingDisabledPurposes" x-model="purpose" required />
                </x-field>
                @unless ($academicAllowed)
                    <p class="text-sm text-muted">Praktikum Tambahan hanya Senin–Jumat. Pada Sabtu/Minggu, ruangan dapat dipesan untuk kegiatan umum sesuai ketersediaan.</p>
                @endunless

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <x-button variant="secondary" class="min-h-11 w-full" x-on:click="close()" x-bind:disabled="submitting">Batal</x-button>
                    <x-button type="submit" class="min-h-11 w-full" x-bind:disabled="submitting">
                        <span x-show="! submitting">Pesan sekarang</span>
                        <span x-show="submitting" x-cloak>Memproses…</span>
                    </x-button>
                </div>
            </form>
        </div>
    </dialog>
</div>
