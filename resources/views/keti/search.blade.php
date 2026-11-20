@use('App\Support\BookingPayload')
@use('App\Support\Wita')
@extends('layouts.keti')

@section('title', 'Cari Ruangan')

@php
    $sksOptions = config('sicma.reservation.duration_options');
    $defaultMinutes = (int) config('sicma.reservation.default_minutes');
    $selectedDuration = (int) ($filters['durasi'] ?? $defaultMinutes);
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
    $todayStr = now()->toDateString();
    $selectedDate = (string) ($filters['tanggal'] ?? $todayStr);
    $selectedStart = (string) ($filters['mulai'] ?? 'sekarang');
    $selectedPeople = $filters['orang'] ?? null;
    $activeFilterCount = ($selectedDate !== $todayStr ? 1 : 0)
        + ($selectedStart !== 'sekarang' ? 1 : 0)
        + ($selectedDuration !== $defaultMinutes ? 1 : 0)
        + (filled($selectedPeople) ? 1 : 0);
    $dateChip = $selectedDate === $todayStr ? 'Hari ini' : Wita::date(\Carbon\CarbonImmutable::parse($selectedDate));
    $timeChip = $selectedStart === 'sekarang' ? 'Sekarang' : $selectedStart.' WITA';
    $durationChip = trim((string) preg_replace('/\s*\(.*\)$/', '', (string) ($durationOptions[$selectedDuration] ?? Wita::duration($selectedDuration))));
    // Filter yang dibawa saat mengganti gedung/urutan di atas hasil.
    $carry = array_filter([
        'q' => $filters['q'] ?? null, 'tanggal' => $filters['tanggal'] ?? null, 'mulai' => $filters['mulai'] ?? null,
        'durasi' => $filters['durasi'] ?? null, 'orang' => $filters['orang'] ?? null,
    ], fn ($value) => $value !== null && $value !== '');
@endphp

@section('content')
    <div class="flex flex-col gap-4 sm:gap-6" x-data="{ loading: false, filtersOpen: false }">
        <x-page-header title="Cari Ruangan" subtitle="Temukan ruangan kosong yang sesuai untuk kelasmu." />

        {{-- Satu bilah pencarian: ringkas di mobile dengan panel filter lipat, lengkap sejajar di desktop. --}}
        <search>
            <form id="pencarian" method="GET" action="{{ route('keti.search') }}"
                  class="flex flex-col gap-2.5 rounded-card border border-border bg-surface p-3 sm:p-4 lg:grid lg:grid-cols-12 lg:items-end lg:gap-3" x-on:submit="loading = true">
                @foreach (array_filter(['gedung' => $filters['gedung'] ?? null, 'urutan' => $filters['urutan'] ?? null]) as $name => $value)
                    <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                @endforeach

                <div class="grid grid-cols-[1fr_auto_auto] items-center gap-2 lg:contents">
                    <label class="flex min-w-0 flex-col gap-1.5 lg:col-span-3">
                        <span class="hidden text-xs font-medium text-muted lg:block">Ruangan</span>
                        <span class="relative">
                            <i data-lucide="search" aria-hidden="true" class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-muted"></i>
                            <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Nama ruangan atau gedung"
                                   aria-label="Nama ruangan atau gedung"
                                   autocorrect="off" autocapitalize="none" spellcheck="false"
                                   class="min-h-11 w-full rounded-control border border-muted/60 py-2.5 pr-3 pl-10 text-base focus:border-primary focus:ring-2 focus:ring-primary/15 focus:outline-none sm:text-sm">
                        </span>
                    </label>
                    <button type="button"
                            x-on:click="filtersOpen = ! filtersOpen"
                            aria-controls="search-advanced-filters"
                            aria-expanded="false"
                            :aria-expanded="filtersOpen.toString()"
                            class="relative inline-flex min-h-11 shrink-0 cursor-pointer items-center justify-center gap-1.5 rounded-control border border-border bg-page px-3 text-xs font-semibold text-ink transition hover:border-primary/40 hover:bg-primary-soft/50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary lg:hidden"
                            :class="filtersOpen ? 'border-primary bg-primary-soft/60 text-primary' : ''">
                        <i data-lucide="sliders-horizontal" aria-hidden="true" class="size-4 shrink-0"></i>
                        <span>Filter</span>
                        @if ($activeFilterCount > 0)
                            <span class="inline-flex size-4.5 items-center justify-center rounded-full bg-primary text-[10px] font-bold text-white">{{ $activeFilterCount }}</span>
                        @endif
                    </button>
                    <x-button type="submit" class="min-h-11 shrink-0 px-3.5 sm:px-4 lg:order-last lg:col-span-2 lg:w-full" icon="search">
                        <span class="sr-only sm:not-sr-only">Cari</span>
                    </x-button>
                </div>

                {{-- Ringkasan kriteria aktif di mobile: ketuk untuk membuka atau menutup filter. --}}
                <div class="flex flex-wrap items-center gap-1.5 lg:hidden">
                    <button type="button" x-on:click="filtersOpen = ! filtersOpen" class="inline-flex cursor-pointer items-center gap-1 rounded-full bg-page px-2.5 py-1 text-xs font-medium text-ink ring-1 ring-border transition hover:bg-primary-soft/50">
                        <i data-lucide="calendar" aria-hidden="true" class="size-3 text-primary"></i>
                        <span>{{ $dateChip }}</span>
                    </button>
                    <button type="button" x-on:click="filtersOpen = ! filtersOpen" class="inline-flex cursor-pointer items-center gap-1 rounded-full bg-page px-2.5 py-1 text-xs font-medium text-ink ring-1 ring-border transition hover:bg-primary-soft/50">
                        <i data-lucide="clock" aria-hidden="true" class="size-3 text-primary"></i>
                        <span>{{ $timeChip }} • {{ $durationChip }}</span>
                    </button>
                    @if (filled($selectedPeople))
                        <button type="button" x-on:click="filtersOpen = ! filtersOpen" class="inline-flex cursor-pointer items-center gap-1 rounded-full bg-primary-soft px-2.5 py-1 text-xs font-semibold text-primary">
                            <i data-lucide="users" aria-hidden="true" class="size-3"></i>
                            <span>≥ {{ $selectedPeople }} org</span>
                        </button>
                    @endif
                </div>

                <div id="search-advanced-filters"
                     class="hidden grid-cols-2 items-end gap-2.5 border-t border-border/70 pt-2.5 lg:contents lg:border-0 lg:pt-0"
                     :class="{ 'hidden': ! filtersOpen, 'grid': filtersOpen }">
                    <x-field label="Tanggal" name="tanggal" class="lg:col-span-2">
                        <x-input type="date" name="tanggal" :value="$selectedDate" :min="$todayStr" :max="now()->addDays((int) config('sicma.reservation.max_days_ahead'))->toDateString()" />
                    </x-field>
                    <x-field label="Jam mulai" name="mulai" class="lg:col-span-2">
                        <x-select name="mulai" :options="$timeOptions" :value="$selectedStart" />
                    </x-field>
                    <x-field label="Durasi" name="durasi" class="lg:col-span-2">
                        <x-select name="durasi" :options="$durationOptions" :value="$selectedDuration" />
                    </x-field>
                    <x-field label="Jumlah orang" name="orang" class="lg:col-span-1">
                        <x-input type="number" name="orang" min="1" max="500" :value="$selectedPeople" placeholder="Bebas" />
                    </x-field>
                    <div class="col-span-2 lg:hidden">
                        <x-button type="submit" icon="search" class="min-h-11 w-full">Terapkan filter</x-button>
                    </div>
                </div>
            </form>
        </search>

        <div x-show="loading" x-cloak>
            <x-loading-state message="Memuat data ruangan…" />
        </div>

        <div x-show="! loading" class="flex flex-col gap-4">
            @if ($loadFailed)
                <x-error-state message="Periksa koneksi internet, lalu coba lagi." :retry="request()->fullUrl()" />
            @elseif ($rooms === [])
                <x-empty-state title="Tidak ada ruangan yang cocok" message="Coba ubah waktu, durasi, gedung, atau jumlah orang.">
                    <x-button variant="soft" href="#pencarian" x-on:click="filtersOpen = true; $nextTick(() => document.querySelector('#pencarian input[name=q]')?.focus())">Ubah filter</x-button>
                </x-empty-state>
            @else
                <div class="flex flex-col gap-2.5 sm:flex-row sm:items-end sm:justify-between sm:gap-4">
                    <div class="flex flex-col gap-0.5">
                        <h2 class="text-lg font-semibold sm:text-xl">{{ count($rooms) }} ruangan tersedia</h2>
                        <span class="text-xs text-muted">{{ $criteriaLabel }}{{ $isNow ? '' : ' • '.Wita::stamp($start) }}</span>
                    </div>
                    <form method="GET" action="{{ route('keti.search') }}" class="grid grid-cols-2 gap-2 sm:flex sm:flex-row sm:items-center" x-on:change="loading = true; $el.requestSubmit()">
                        @foreach ($carry as $name => $value)
                            <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                        @endforeach
                        <x-select name="gedung" :options="$buildingOptions" :value="$filters['gedung'] ?? null" placeholder="Semua gedung" class="w-full sm:w-48" aria-label="Gedung" />
                        <x-select name="urutan" :options="['terlama' => 'Waktu terlama', 'nama' => 'Nama ruangan', 'kapasitas' => 'Kapasitas terbesar']" :value="$filters['urutan'] ?? 'terlama'" class="w-full sm:w-48" aria-label="Urutan" />
                        <noscript><x-button type="submit" variant="secondary">Terapkan</x-button></noscript>
                    </form>
                </div>
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    @foreach ($rooms as $room)
                        <x-room-card size="large" :name="$room['name']" :badge="$room['badge']" :location="$room['location']" :capacity="$room['capacity']"
                                     :facilities="$room['facilities']" :time-label="$room['time_label']" :time="$room['time']" :remaining="$room['remaining']"
                                     style="--stagger: {{ $loop->index }}"
                                     :href="route('keti.rooms.show', array_filter(['room' => $room['id'], 'mulai' => $isNow ? null : $start->format('Y-m-d H:i'), 'durasi' => $duration, 'orang' => $filters['orang'] ?? null]))"
                                     :booking="BookingPayload::make($room['id'], $room['name'], $room['location'], $room['capacity'], $start, $duration, isset($filters['orang']) && filled($filters['orang']) ? (int) $filters['orang'] : null)" />
                    @endforeach
                </div>
            @endif
        </div>
    </div>
@endsection

@push('dialogs')
    @include('keti.partials.booking-modal', ['academicAllowed' => $academicAllowed])
@endpush
