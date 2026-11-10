@use('App\Support\Wita')
@extends('layouts.keti')

@section('title', 'Detail Ruangan')

@php
    $canBook = $requested->isAvailable();
    $sksOptions = config('sicma.reservation.duration_options');
    $durationOptions = collect($sksOptions)
        ->when(! array_key_exists($duration, $sksOptions) && $duration >= config('sicma.reservation.min_minutes') && $duration <= config('sicma.reservation.max_minutes'), fn ($collection) => $collection->put($duration, Wita::duration($duration))->sortKeys())
        ->all();
    $purposeOptions = [
        'Kuliah Pengganti' => 'Kuliah Pengganti',
        'Diskusi / Kerja Kelompok' => 'Diskusi / Kerja Kelompok',
        'Praktikum Tambahan' => 'Praktikum Tambahan',
        'Seminar / Ujian' => 'Seminar / Ujian',
        'Kegiatan Organisasi / Himpunan' => 'Kegiatan Organisasi / Himpunan',
        'Lainnya' => 'Lainnya',
    ];
@endphp

@section('content')
    <div class="flex flex-col gap-6">
        <div class="flex flex-col gap-2">
            <h1 class="text-3xl font-semibold">Detail Ruangan</h1>
            <x-breadcrumb :items="[['Cari Ruangan', route('keti.search')], [$room->name]]" />
        </div>

        <div class="flex items-center gap-5">
            <x-button variant="secondary" :href="url()->previous() !== url()->current() ? url()->previous() : route('keti.search')" class="w-36">Kembali</x-button>
            <span class="text-sm text-muted">{{ Wita::longDate($current->start) }} • {{ Wita::clock($current->start) }}</span>
        </div>

        <div class="grid items-start gap-6 lg:grid-cols-[1fr_390px]">
            <div class="flex flex-col gap-6">
                <x-card class="flex flex-col gap-5">
                    <div class="flex items-start justify-between">
                        <div class="flex flex-col gap-3">
                            <h2 class="text-3xl font-medium">{{ $room->name }}</h2>
                            <p class="text-sm text-muted">{{ $current->locationLabel() }}</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <form method="POST" action="{{ $isFavorite ? route('keti.favorites.destroy', $room) : route('keti.favorites.store', $room) }}">
                                @csrf
                                @method($isFavorite ? 'DELETE' : 'PUT')
                                <button type="submit" class="flex items-center gap-1 text-sm font-medium {{ $isFavorite ? 'text-danger' : 'text-primary' }}" aria-pressed="{{ $isFavorite ? 'true' : 'false' }}">
                                    <i data-lucide="heart" class="size-4"></i>{{ $isFavorite ? 'Hapus favorit' : 'Simpan favorit' }}
                                </button>
                            </form>
                            <div class="flex flex-col items-end gap-1">
                                <span class="text-xs text-muted">Status saat ini</span>
                                <x-status-badge :variant="$current->isAvailable() ? 'success' : ($current->badge() === 'Tutup' ? 'neutral' : 'danger')">{{ $current->badge() }}</x-status-badge>
                            </div>
                        </div>
                    </div>

                    <dl class="flex items-end justify-between">
                        <div class="flex flex-col gap-1">
                            <dt class="text-xs text-muted">Kapasitas</dt>
                            <dd class="text-xl font-medium">{{ $room->capacity === null ? '—' : $room->capacity.' kursi' }}</dd>
                        </div>
                        <div class="flex flex-col items-end gap-1">
                            <dt class="text-xs text-muted">{{ $current->isAvailable() ? 'Tersedia sampai' : ($current->status === \App\Enums\RoomStatus::Closed ? 'Buka kembali' : 'Selesai pukul') }}</dt>
                            <dd class="text-xl font-medium">{{ $current->isAvailable() ? Wita::clock($current->freeUntil) : ($current->busyUntil ? Wita::clock($current->busyUntil) : '—') }}</dd>
                        </div>
                    </dl>

                    @if ($room->facilities->isNotEmpty())
                        <div class="flex flex-wrap gap-2">
                            @foreach ($room->facilities as $facility)
                                <span class="rounded-full bg-primary-soft px-3 py-1 text-xs font-medium text-primary">{{ $facility->name }}</span>
                            @endforeach
                        </div>
                    @endif

                    @if ($currentAgenda)
                        <p class="text-sm text-muted">Sedang berlangsung: {{ $currentAgenda['range'] }} • {{ $currentAgenda['title'] }}{{ $currentAgenda['class'] ? ' • '.$currentAgenda['class'] : '' }}</p>
                    @elseif ($nextAgenda)
                        <p class="text-sm text-muted">Jadwal berikutnya: {{ $nextAgenda['range'] }} • {{ $nextAgenda['title'] }}</p>
                    @else
                        <p class="text-sm text-muted">Tidak ada jadwal lain hari ini.</p>
                    @endif
                </x-card>

                <x-card class="flex flex-col gap-4">
                    <h2 class="text-lg font-semibold">Riwayat penggunaan</h2>
                    <table class="w-full text-left text-sm">
                        <thead class="border-b border-border text-xs text-muted">
                            <tr><th class="py-3 font-medium">Pengguna</th><th class="py-3 font-medium">Waktu</th><th class="py-3 font-medium">Kegiatan</th><th class="py-3 font-medium">Status</th></tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @forelse ($history as $item)
                                <tr>
                                    <td class="py-3">Kelas {{ $item->classGroup->name }}</td>
                                    <td class="py-3">{{ $item->actual_start_at ? Wita::clock($item->actual_start_at, false) : Wita::clock($item->start_at, false) }}–{{ $item->actual_end_at ? Wita::clock($item->actual_end_at) : 'Selesai —' }}</td>
                                    <td class="py-3">{{ $item->purpose }}</td>
                                    <td class="py-3"><x-status-badge :variant="$item->status->badgeVariant()">{{ $item->status->label() }}</x-status-badge></td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="py-6 text-center text-sm text-muted">Belum ada catatan pemakaian hari ini.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </x-card>
            </div>

            <x-card class="flex flex-col gap-4">
                <form method="GET" action="{{ route('keti.rooms.show', $room) }}" class="flex flex-col gap-4">
                    <h2 class="text-xl font-semibold">Rencanakan pemakaian</h2>
                    <p class="text-sm text-muted">Booking bisa dilakukan kapan saja untuk pemakaian pukul {{ config('sicma.operating_hours.start') }}–{{ config('sicma.operating_hours.end') }} WITA.</p>
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
                    <x-button variant="ghost" :href="route('keti.rooms.calendar', ['q' => $room->name, 'durasi' => $duration])">Lihat semua slot kosong</x-button>
                </form>
                <hr class="border-border">
                @if ($suggestedStart !== null)
                    <p class="text-sm text-primary">Slot kosong terdekat dipilihkan: {{ Wita::date($start) }} • {{ Wita::range($start, $end) }}.</p>
                @endif
                @if ($canBook)
                    <h2 class="text-xl font-semibold">Gunakan untuk kelasmu</h2>
                    <p class="font-semibold text-primary">Kelas {{ $classGroup->name }}</p>
                    <p class="text-sm text-muted">{{ auth()->user()->name }} • Ketua tingkat</p>
                    <hr class="border-border">
                    <p class="text-xs text-muted">{{ $isNow ? 'Mulai sekarang' : 'Mulai '.Wita::date($start) }} • Durasi {{ Wita::duration($duration) }}</p>
                    <p class="text-xl font-semibold">{{ Wita::range($start, $end) }}</p>
                    <x-button class="w-full" x-data x-on:click="$dispatch('open-modal', 'pesan-ruang')">Pesan ruang</x-button>
                @else
                    <h2 class="text-xl font-semibold">Waktu pilihan belum bisa dipesan</h2>
                    <p class="font-semibold text-primary">Kelas {{ $classGroup->name }}</p>
                    <p class="text-sm text-muted">{{ $requested->reason ?? 'Ruangan tidak tersedia untuk waktu yang dipilih.' }}</p>
                    <hr class="border-border">
                    @if ($requested->status === \App\Enums\RoomStatus::Closed)
                        <p class="text-xs text-muted">Jadwal buka</p>
                        <p class="text-xl font-semibold">{{ $requested->busyUntil ? 'Buka kembali '.Wita::clock($requested->busyUntil) : '—' }}</p>
                        <p class="text-sm text-muted">{{ $requested->reason ?? 'Ruangan tidak dapat dipesan di luar jam operasional kampus.' }}</p>
                    @else
                        <p class="text-xs text-muted">Waktu pemakaian</p>
                        <p class="text-xl font-semibold">{{ $currentAgenda['range'] ?? '—' }}</p>
                        <p class="text-sm text-muted">Pilih tanggal dan jam lain atau lihat slot kosong untuk ruangan ini.</p>
                    @endif
                    <x-button class="w-full" :href="route('keti.search')">Cari ruangan lain</x-button>
                    <x-button class="w-full" disabled>Pesan ruang</x-button>
                @endif
                <x-button variant="secondary" class="w-full" :href="route('keti.activity')">Lihat aktivitas</x-button>
            </x-card>
        </div>
    </div>
@endsection

@push('dialogs')
    @if ($canBook)
        <x-modal name="pesan-ruang" title="Pesan {{ $room->name }}">
            <form method="POST" action="{{ route('keti.reservations.store') }}" class="flex flex-col gap-4">
                @csrf
                <input type="hidden" name="room_id" value="{{ $room->id }}">
                <input type="hidden" name="start_at" value="{{ $start->format('Y-m-d H:i') }}">
                <input type="hidden" name="duration" value="{{ $duration }}">
                <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">

                <x-info-box title="Kelas {{ $classGroup->name }}">
                    <p>{{ Wita::date($start) }} • {{ Wita::range($start, $end) }}</p>
                </x-info-box>
                <x-field label="Kegiatan" name="purpose">
                    <x-select name="purpose" :options="$purposeOptions" :value="$academicBookingAllowed ? 'Kuliah Pengganti' : 'Diskusi / Kerja Kelompok'" :disabled-options="$academicBookingAllowed ? [] : config('sicma.reservation.academic_purposes')" required />
                </x-field>
                <p class="text-sm text-muted">Kuliah Pengganti dan Praktikum Tambahan hanya Senin–Jumat. Pada Sabtu/Minggu, ruangan dapat dipesan untuk kegiatan umum sesuai ketersediaan.</p>
                <div class="grid grid-cols-2 gap-3">
                    <x-button variant="secondary" x-on:click="$dispatch('close-modal', 'pesan-ruang')">Batal</x-button>
                    <x-button type="submit">Pesan ruang</x-button>
                </div>
            </form>
        </x-modal>
    @endif
@endpush
