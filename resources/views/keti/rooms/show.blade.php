@use('App\Support\BookingPayload')
@use('App\Support\Wita')
@extends('layouts.keti')

@section('title', 'Detail Ruangan')

@php
    $canBook = $requested->isAvailable();
    $sksOptions = config('sicma.reservation.duration_options');
    $durationOptions = collect($sksOptions)
        ->when(! array_key_exists($duration, $sksOptions) && $duration >= config('sicma.reservation.min_minutes') && $duration <= config('sicma.reservation.max_minutes'), fn ($collection) => $collection->put($duration, Wita::duration($duration))->sortKeys())
        ->all();
    $booking = BookingPayload::make($room->id, $room->name, $current->locationLabel(), $room->capacity, $start, $duration, $participants);
@endphp

@section('content')
    <div class="flex flex-col gap-6">
        <div class="flex flex-col gap-2">
            <h1 class="text-3xl font-semibold">Detail Ruangan</h1>
            <x-breadcrumb :items="[['Cari Ruangan', route('keti.search')], [$room->name]]" />
        </div>

        <div class="flex flex-wrap items-center gap-3 sm:gap-5">
            <x-button variant="secondary" :href="url()->previous() !== url()->current() ? url()->previous() : route('keti.search')" class="w-36">Kembali</x-button>
            <span class="text-sm text-muted">{{ Wita::longDate($current->start) }} • {{ Wita::clock($current->start) }}</span>
        </div>

        <div class="grid grid-cols-1 items-start gap-6 lg:grid-cols-[1fr_340px]">
            <div class="flex min-w-0 flex-col gap-6">
                <x-card class="sicma-card-enter flex flex-col gap-5">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div class="flex flex-col gap-1.5">
                            <h2 class="text-2xl font-semibold text-ink sm:text-3xl">{{ $room->name }}</h2>
                            <p class="inline-flex items-center gap-1.5 text-sm text-muted">
                                <i data-lucide="map-pin" aria-hidden="true" class="size-4 shrink-0"></i>
                                <span>{{ $current->locationLabel() }}</span>
                            </p>
                        </div>
                        <div class="flex flex-wrap items-center justify-between gap-3 sm:justify-end">
                            <form method="POST" action="{{ $isFavorite ? route('keti.favorites.destroy', $room) : route('keti.favorites.store', $room) }}">
                                @csrf
                                @method($isFavorite ? 'DELETE' : 'PUT')
                                <button type="submit" class="flex cursor-pointer items-center gap-1.5 rounded-control border border-border bg-page px-3 py-1.5 text-xs font-semibold transition hover:border-primary/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary {{ $isFavorite ? 'text-danger' : 'text-primary' }}" aria-pressed="{{ $isFavorite ? 'true' : 'false' }}">
                                    <i data-lucide="heart" aria-hidden="true" class="size-3.5"></i>{{ $isFavorite ? 'Hapus favorit' : 'Simpan favorit' }}
                                </button>
                            </form>
                            <div class="flex flex-col gap-1 sm:items-end">
                                <span class="text-[11px] font-medium text-muted">Status saat ini</span>
                                <x-status-badge :variant="$current->isAvailable() ? 'success' : ($current->badge() === 'Tutup' ? 'neutral' : 'danger')" :pulse="$current->badge() === 'Digunakan'">{{ $current->badge() }}</x-status-badge>
                            </div>
                        </div>
                    </div>

                    <dl class="grid grid-cols-1 gap-4 rounded-control bg-page p-4 sm:grid-cols-2 sm:items-end">
                        <div class="flex flex-col gap-1">
                            <dt class="text-xs text-muted">Kapasitas</dt>
                            <dd class="inline-flex items-center gap-2 text-xl font-semibold text-ink tabular-nums">
                                <i data-lucide="users" aria-hidden="true" class="size-4 shrink-0 text-muted"></i>
                                <span>{{ $room->capacity === null ? '—' : $room->capacity.' kursi' }}</span>
                            </dd>
                        </div>
                        <div class="flex flex-col gap-1 sm:items-end sm:text-right">
                            <dt class="text-xs text-muted">{{ $current->isAvailable() ? 'Tersedia sampai' : ($current->status === \App\Enums\RoomStatus::Closed ? 'Buka kembali' : 'Selesai pukul') }}</dt>
                            <dd class="text-xl font-semibold text-ink tabular-nums">{{ $current->isAvailable() ? Wita::clock($current->freeUntil) : ($current->busyUntil ? Wita::clock($current->busyUntil) : '—') }}</dd>
                        </div>
                    </dl>

                    @if ($room->facilities->isNotEmpty())
                        <div class="flex flex-wrap gap-2">
                            @foreach ($room->facilities as $facility)
                                <span class="inline-flex items-center gap-1 rounded-full bg-primary-soft px-3 py-1 text-xs font-medium text-primary">
                                    <i data-lucide="check" aria-hidden="true" class="size-3"></i>
                                    {{ $facility->name }}
                                </span>
                            @endforeach
                        </div>
                    @endif

                    @if ($currentAgenda)
                        <div class="flex items-center gap-2.5 rounded-control border border-danger/20 bg-danger-soft/50 px-3.5 py-2.5 text-sm text-ink">
                            <span class="sicma-live-dot size-2 shrink-0 rounded-full bg-danger" aria-hidden="true"></span>
                            <p>Sedang berlangsung: <span class="font-semibold">{{ $currentAgenda['range'] }}</span> • {{ $currentAgenda['title'] }}{{ $currentAgenda['class'] ? ' • '.$currentAgenda['class'] : '' }}</p>
                        </div>
                    @elseif ($nextAgenda)
                        <div class="flex items-center gap-2.5 rounded-control border border-border bg-page px-3.5 py-2.5 text-sm text-muted">
                            <i data-lucide="clock" aria-hidden="true" class="size-4 shrink-0 text-primary"></i>
                            <p>Jadwal berikutnya: <span class="font-semibold text-ink">{{ $nextAgenda['range'] }}</span> • {{ $nextAgenda['title'] }}</p>
                        </div>
                    @else
                        <div class="flex items-center gap-2.5 rounded-control border border-border bg-page px-3.5 py-2.5 text-sm text-muted">
                            <i data-lucide="calendar-check" aria-hidden="true" class="size-4 shrink-0 text-success"></i>
                            <p>Tidak ada jadwal lain hari ini.</p>
                        </div>
                    @endif
                </x-card>

                <x-card class="flex flex-col gap-4">
                    <h2 class="text-lg font-semibold">Riwayat penggunaan</h2>
                    <div class="overflow-x-auto custom-scrollbar" role="region" aria-label="Jadwal hari ini di ruangan" tabindex="0">
                        <table class="w-full text-left text-sm">
                            <thead class="border-b border-border text-xs text-muted">
                                <tr>
                                    <th scope="col" class="py-3 pr-4 font-medium">Pengguna</th>
                                    <th scope="col" class="py-3 pr-4 font-medium">Waktu</th>
                                    <th scope="col" class="py-3 pr-4 font-medium">Kegiatan</th>
                                    <th scope="col" class="py-3 font-medium">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                @forelse ($history as $item)
                                    <tr>
                                        <td class="py-3 pr-4">Kelas {{ $item->classGroup->name }}</td>
                                        <td class="py-3 pr-4">{{ $item->actual_start_at ? Wita::clock($item->actual_start_at, false) : Wita::clock($item->start_at, false) }}–{{ $item->actual_end_at ? Wita::clock($item->actual_end_at) : 'Selesai —' }}</td>
                                        <td class="py-3 pr-4">{{ $item->purpose }}</td>
                                        <td class="py-3"><x-status-badge :variant="$item->status->badgeVariant()">{{ $item->status->label() }}</x-status-badge></td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="py-6 text-center text-sm text-muted">Belum ada catatan pemakaian hari ini.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </x-card>
            </div>

            {{-- Panel pemesanan: waktu dipilih + tombol Pesan; ubah waktu dibuka bila diperlukan. --}}
            <x-card class="flex flex-col gap-4" x-data="{ editing: @js(! $canBook && $suggestedStart === null) }">
                <h2 class="text-xl font-semibold">{{ $canBook ? 'Gunakan untuk kelasmu' : 'Waktu pilihan belum bisa dipesan' }}</h2>
                <p class="font-semibold text-primary">Kelas {{ $classGroup->name }}</p>
                @if ($suggestedStart !== null)
                    <p class="text-sm text-primary">Slot kosong terdekat dipilihkan: {{ Wita::date($start) }} • {{ Wita::range($start, $end) }}.</p>
                @endif

                <div class="flex flex-col gap-1 rounded-control bg-page p-4">
                    <span class="text-xs text-muted">{{ $isNow ? 'Mulai sekarang' : 'Mulai '.Wita::date($start) }} • Durasi {{ Wita::duration($duration) }}</span>
                    <span class="text-xl font-semibold">{{ Wita::range($start, $end) }}</span>
                </div>

                @if ($canBook)
                    <x-button class="w-full" x-on:click="$dispatch('open-booking', {{ \Illuminate\Support\Js::from($booking) }})">Pesan ruang</x-button>
                @else
                    @if ($requested->status === \App\Enums\RoomStatus::Closed)
                        <p class="text-sm text-muted">{{ $requested->busyUntil ? 'Buka kembali '.Wita::clock($requested->busyUntil).'. ' : '' }}{{ $requested->reason ?? 'Ruangan tidak dapat dipesan di luar jam operasional kampus.' }}</p>
                    @else
                        <p class="text-sm text-muted">{{ $requested->reason ?? 'Ruangan tidak tersedia untuk waktu yang dipilih.' }} Pilih jam lain atau lihat slot kosong ruangan ini.</p>
                    @endif
                    <x-button class="w-full" :href="route('keti.rooms.calendar', ['q' => $room->name, 'durasi' => $duration])">Lihat slot kosong</x-button>
                    <x-button variant="secondary" class="w-full" :href="route('keti.search')">Cari ruangan lain</x-button>
                @endif

                <button type="button" class="self-start text-sm font-semibold text-primary hover:underline" x-on:click="editing = ! editing" x-bind:aria-expanded="editing.toString()">
                    <span x-text="editing ? 'Tutup ubah waktu' : 'Ubah waktu'">Ubah waktu</span>
                </button>
                <form method="GET" action="{{ route('keti.rooms.show', $room) }}" class="flex flex-col gap-4" x-show="editing" x-cloak>
                    <p class="text-sm text-muted">Pemakaian pukul {{ config('sicma.operating_hours.start') }}–{{ config('sicma.operating_hours.end') }} WITA.</p>
                    <x-field label="Tanggal dan jam mulai (WITA)" name="mulai">
                        <x-input type="datetime-local" name="mulai" :value="$start->format('Y-m-d\TH:i')" :min="$current->start->format('Y-m-d\TH:i')" :max="$lastBookingDate->format('Y-m-d\TH:i')" required />
                    </x-field>
                    <x-field label="Durasi pemakaian" name="durasi">
                        <x-select name="durasi" :options="$durationOptions" :value="$duration" />
                    </x-field>
                    @if ($participants !== null)
                        <input type="hidden" name="orang" value="{{ $participants }}">
                    @endif
                    <x-button type="submit" variant="secondary">Cek ketersediaan</x-button>
                </form>
            </x-card>
        </div>
    </div>
@endsection

@push('dialogs')
    @if ($canBook)
        @include('keti.partials.booking-modal', ['academicAllowed' => $academicBookingAllowed, 'initial' => $booking, 'idempotencyKey' => $idempotencyKey])
    @endif
@endpush
