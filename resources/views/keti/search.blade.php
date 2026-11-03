@use('App\Support\Wita')
@extends('layouts.keti')

@section('title', 'Cari Ruangan')

@php
    $durationOptions = collect([30, 60, 90, 120, 150, 180])
        ->filter(fn ($minutes) => $minutes >= config('sicma.reservation.min_minutes') && $minutes <= config('sicma.reservation.max_minutes'))
        ->mapWithKeys(fn ($minutes) => [$minutes => Wita::duration($minutes)]);
    [$openHour] = explode(':', config('sicma.operating_hours.start'));
    [$closeHour] = explode(':', config('sicma.operating_hours.end'));
    $timeOptions = collect(['sekarang' => 'Sekarang • '.Wita::clock(now())])
        ->merge(collect(range((int) $openHour, (int) $closeHour - 1))->flatMap(fn ($hour) => [sprintf('%02d:00', $hour) => sprintf('%02d:00 WITA', $hour), sprintf('%02d:30', $hour) => sprintf('%02d:30 WITA', $hour)]));
    $buildingOptions = $buildings->pluck('name', 'id')->put('lainnya', 'Gedung belum didata');
@endphp

@section('content')
    <div class="flex flex-col gap-6" x-data="{ loading: false }">
        <x-page-header title="Cari Ruangan" subtitle="Temukan ruangan kosong yang sesuai untuk kelasmu." />

        <form method="GET" action="{{ route('keti.search') }}" x-on:submit="loading = true" class="flex items-center gap-3 rounded-card border border-border bg-surface p-3" role="search">
            @foreach (array_filter(['tanggal' => $filters['tanggal'] ?? null, 'durasi' => $filters['durasi'] ?? null, 'gedung' => $filters['gedung'] ?? null, 'orang' => $filters['orang'] ?? null, 'urutan' => $filters['urutan'] ?? null]) as $name => $value)
                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
            @endforeach
            <label class="relative flex-1">
                <span class="sr-only">Cari nama ruangan atau gedung</span>
                <i data-lucide="search" class="pointer-events-none absolute top-1/2 left-4 size-4 -translate-y-1/2 text-muted"></i>
                <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Cari nama ruangan atau gedung"
                       class="w-full rounded-control border border-muted/60 py-3 pr-4 pl-11 text-sm focus:border-primary focus:ring-2 focus:ring-primary/15 focus:outline-none">
            </label>
            <x-select name="mulai" :options="$timeOptions" :value="$filters['mulai'] ?? 'sekarang'" class="w-64" aria-label="Waktu mulai" />
            <x-button type="submit" class="w-36">Cari</x-button>
        </form>

        @include('keti.partials.search-tabs', ['active' => $showFilter ? 'filter' : 'sekarang'])

        @if ($showFilter)
            <x-card>
                <form method="GET" action="{{ route('keti.search') }}" x-on:submit="loading = true" class="flex flex-col gap-5">
                    <h2 class="text-lg font-semibold">Atur pencarian</h2>
                    <div class="grid grid-cols-3 gap-4">
                        <x-field label="Tanggal" name="tanggal">
                            <x-input type="date" name="tanggal" :value="$filters['tanggal'] ?? now()->toDateString()" :min="now()->toDateString()" :max="now()->addDays((int) config('sicma.reservation.max_days_ahead'))->toDateString()" />
                        </x-field>
                        <x-field label="Waktu mulai" name="mulai">
                            <x-select name="mulai" :options="$timeOptions" :value="$filters['mulai'] ?? 'sekarang'" />
                        </x-field>
                        <x-field label="Durasi pemakaian" name="durasi">
                            <x-select name="durasi" :options="$durationOptions" :value="$filters['durasi'] ?? config('sicma.reservation.default_minutes')" />
                        </x-field>
                        <x-field label="Gedung" name="gedung">
                            <x-select name="gedung" :options="$buildingOptions" :value="$filters['gedung'] ?? null" placeholder="Semua gedung" />
                        </x-field>
                        <x-field label="Jumlah orang" name="orang" hint="Kosongkan bila bebas.">
                            <x-input type="number" name="orang" min="1" :value="$filters['orang'] ?? null" placeholder="Bebas" />
                        </x-field>
                        <x-field label="Urutan" name="urutan">
                            <x-select name="urutan" :options="['terlama' => 'Waktu terlama', 'nama' => 'Nama ruangan', 'kapasitas' => 'Kapasitas terbesar']" :value="$filters['urutan'] ?? 'terlama'" />
                        </x-field>
                    </div>
                    <div class="flex gap-3">
                        <x-button type="submit">Terapkan filter</x-button>
                        <x-button variant="secondary" :href="route('keti.search', ['tab' => 'filter'])">Atur ulang</x-button>
                        <x-button variant="secondary" :href="route('keti.search', request()->except('tab'))">Kembali</x-button>
                    </div>
                </form>
            </x-card>
        @endif

        <div x-show="loading" x-cloak>
            <x-loading-state message="Memuat data ruangan…" />
        </div>

        <div x-show="! loading" class="flex flex-col gap-4">
            @if ($loadFailed)
                <x-error-state message="Periksa koneksi internet, lalu coba lagi." :retry="request()->fullUrl()" />
            @elseif ($rooms === [])
                <x-empty-state title="Tidak ada ruangan yang cocok" message="Coba ubah waktu, gedung, atau jumlah orang.">
                    <x-button variant="soft" :href="route('keti.search', [...request()->except('tab'), 'tab' => 'filter'])">Ubah filter</x-button>
                </x-empty-state>
            @else
                <div class="flex items-center justify-between">
                    <h2 class="text-xl font-semibold">{{ count($rooms) }} ruangan tersedia</h2>
                    <span class="text-xs text-muted">{{ $criteriaLabel }}{{ $isNow ? '' : ' • '.Wita::stamp($start) }}</span>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    @foreach ($rooms as $room)
                        <x-room-card size="large" :name="$room['name']" :badge="$room['badge']" :location="$room['location']" :capacity="$room['capacity']"
                                     :facilities="$room['facilities']" :time-label="$room['time_label']" :time="$room['time']" :remaining="$room['remaining']"
                                     :href="route('keti.rooms.show', ['room' => $room['id'], 'mulai' => $isNow ? null : $start->format('Y-m-d H:i'), 'durasi' => $duration])" />
                    @endforeach
                </div>
            @endif
        </div>
    </div>
@endsection
