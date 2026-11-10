@use('App\Support\BookingPayload')
@use('App\Support\Wita')
@extends('layouts.keti')

@section('title', 'Cari Ruangan')

@php
    $sksOptions = config('sicma.reservation.duration_options');
    $selectedDuration = (int) ($filters['durasi'] ?? config('sicma.reservation.default_minutes'));
    $durationOptions = collect($sksOptions)
        ->when(! array_key_exists($selectedDuration, $sksOptions), fn ($options) => $options->put($selectedDuration, Wita::duration($selectedDuration)))
        ->sortKeys()->all();
    [$openHour, $openMinute] = array_map('intval', explode(':', (string) config('sicma.operating_hours.start')));
    [$closeHour, $closeMinute] = array_map('intval', explode(':', (string) config('sicma.operating_hours.end')));
    $openMinutes = $openHour * 60 + $openMinute;
    $lastStartMinutes = ($closeHour * 60 + $closeMinute) - (int) config('sicma.reservation.min_minutes');
    $timeOptions = collect(['sekarang' => 'Sekarang • '.Wita::clock(now())])
        ->merge(collect(range($openMinutes, $lastStartMinutes, 30))->mapWithKeys(fn (int $minutes) => [
            sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60) => sprintf('%02d:%02d WITA', intdiv($minutes, 60), $minutes % 60),
        ]));
    $buildingOptions = $buildings->pluck('name', 'id')->put('lainnya', 'Gedung belum didata');
    // Filter yang dibawa saat mengganti gedung/urutan di atas hasil.
    $carry = array_filter([
        'q' => $filters['q'] ?? null, 'tanggal' => $filters['tanggal'] ?? null, 'mulai' => $filters['mulai'] ?? null,
        'durasi' => $filters['durasi'] ?? null, 'orang' => $filters['orang'] ?? null,
    ], fn ($value) => $value !== null && $value !== '');
@endphp

@section('content')
    <div class="flex flex-col gap-6" x-data="{ loading: false }">
        <x-page-header title="Cari Ruangan" subtitle="Temukan ruangan kosong yang sesuai untuk kelasmu." />

        {{-- Satu bilah pencarian: semua kriteria utama tanpa pindah tab. --}}
        <form id="pencarian" method="GET" action="{{ route('keti.search') }}" role="search"
              class="grid grid-cols-12 items-end gap-3 rounded-card border border-border bg-surface p-4" x-on:submit="loading = true">
            @foreach (array_filter(['gedung' => $filters['gedung'] ?? null, 'urutan' => $filters['urutan'] ?? null]) as $name => $value)
                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
            @endforeach
            <label class="col-span-3 flex flex-col gap-1.5">
                <span class="text-xs font-medium text-muted">Ruangan</span>
                <span class="relative">
                    <i data-lucide="search" class="pointer-events-none absolute top-1/2 left-4 size-4 -translate-y-1/2 text-muted"></i>
                    <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Nama ruangan atau gedung"
                           class="w-full rounded-control border border-muted/60 py-3 pr-4 pl-11 text-sm focus:border-primary focus:ring-2 focus:ring-primary/15 focus:outline-none">
                </span>
            </label>
            <x-field label="Tanggal" name="tanggal" class="col-span-2">
                <x-input type="date" name="tanggal" :value="$filters['tanggal'] ?? now()->toDateString()" :min="now()->toDateString()" :max="now()->addDays((int) config('sicma.reservation.max_days_ahead'))->toDateString()" />
            </x-field>
            <x-field label="Jam mulai" name="mulai" class="col-span-2">
                <x-select name="mulai" :options="$timeOptions" :value="$filters['mulai'] ?? 'sekarang'" />
            </x-field>
            <x-field label="Durasi" name="durasi" class="col-span-2">
                <x-select name="durasi" :options="$durationOptions" :value="$selectedDuration" />
            </x-field>
            <x-field label="Jumlah orang" name="orang" class="col-span-1">
                <x-input type="number" name="orang" min="1" max="500" :value="$filters['orang'] ?? null" placeholder="Bebas" />
            </x-field>
            <x-button type="submit" class="col-span-2" icon="search">Cari</x-button>
        </form>

        @include('keti.partials.search-tabs', ['active' => 'sekarang'])

        <div x-show="loading" x-cloak>
            <x-loading-state message="Memuat data ruangan…" />
        </div>

        <div x-show="! loading" class="flex flex-col gap-4">
            @if ($loadFailed)
                <x-error-state message="Periksa koneksi internet, lalu coba lagi." :retry="request()->fullUrl()" />
            @elseif ($rooms === [])
                <x-empty-state title="Tidak ada ruangan yang cocok" message="Coba ubah waktu, durasi, gedung, atau jumlah orang.">
                    <x-button variant="soft" href="#pencarian" x-on:click="$nextTick(() => document.querySelector('#pencarian input[name=q]')?.focus())">Ubah filter</x-button>
                </x-empty-state>
            @else
                <div class="flex items-end justify-between gap-4">
                    <div class="flex flex-col gap-1">
                        <h2 class="text-xl font-semibold">{{ count($rooms) }} ruangan tersedia</h2>
                        <span class="text-xs text-muted">{{ $criteriaLabel }}{{ $isNow ? '' : ' • '.Wita::stamp($start) }}</span>
                    </div>
                    <form method="GET" action="{{ route('keti.search') }}" class="flex items-center gap-2" x-on:change="loading = true; $el.requestSubmit()">
                        @foreach ($carry as $name => $value)
                            <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                        @endforeach
                        <x-select name="gedung" :options="$buildingOptions" :value="$filters['gedung'] ?? null" placeholder="Semua gedung" class="w-48" aria-label="Gedung" />
                        <x-select name="urutan" :options="['terlama' => 'Waktu terlama', 'nama' => 'Nama ruangan', 'kapasitas' => 'Kapasitas terbesar']" :value="$filters['urutan'] ?? 'terlama'" class="w-48" aria-label="Urutan" />
                        <noscript><x-button type="submit" variant="secondary">Terapkan</x-button></noscript>
                    </form>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    @foreach ($rooms as $room)
                        <x-room-card size="large" :name="$room['name']" :badge="$room['badge']" :location="$room['location']" :capacity="$room['capacity']"
                                     :facilities="$room['facilities']" :time-label="$room['time_label']" :time="$room['time']" :remaining="$room['remaining']"
                                     :href="route('keti.rooms.show', ['room' => $room['id'], 'mulai' => $isNow ? null : $start->format('Y-m-d H:i'), 'durasi' => $duration])"
                                     :booking="BookingPayload::make($room['id'], $room['name'], $room['location'], $room['capacity'], $start, $duration)" />
                    @endforeach
                </div>
            @endif
        </div>
    </div>
@endsection

@push('dialogs')
    @include('keti.partials.booking-modal', ['academicAllowed' => $academicAllowed])
@endpush
