@use('App\Support\BookingPayload')
@use('App\Support\Wita')
@extends('layouts.keti')

@section('title', 'Slot Kosong Ruangan')

@php
    $sksOptions = config('sicma.reservation.duration_options');
    $defaultMinutes = (int) config('sicma.reservation.default_minutes');
    $durationOptions = collect($sksOptions)
        ->when(! array_key_exists($duration, $sksOptions), fn ($options) => $options->put($duration, Wita::duration($duration)))
        ->sortKeys()->all();
    $buildingOptions = $buildings->pluck('name', 'id')->put('lainnya', 'Gedung belum didata');
    $activeFilterCount = ($duration !== $defaultMinutes ? 1 : 0)
        + (filled($filters['orang'] ?? null) ? 1 : 0)
        + (filled($filters['q'] ?? null) ? 1 : 0)
        + (filled($filters['gedung'] ?? null) ? 1 : 0);
    $durationChip = trim((string) preg_replace('/\s*\(.*\)$/', '', (string) ($durationOptions[$duration] ?? Wita::duration($duration))));
@endphp

@section('content')
    <div class="flex flex-col gap-4 sm:gap-6">
        <x-page-header title="Slot Kosong Ruangan" subtitle="Pilih waktu kosong Senin–Jumat dan pesan ruangan untuk kelasmu." />

        @if ($showingNextTeachingDay)
            <x-info-box title="Booking tetap bisa dilakukan sekarang">
                <p>Berikut slot pada hari kuliah berikutnya: {{ Wita::dayName($date) }}, {{ Wita::date($date) }}. Pemakaian mengikuti jam operasional.</p>
            </x-info-box>
        @endif

        <nav class="flex gap-2 overflow-x-auto pb-1 snap-x custom-scrollbar" aria-label="Pilih hari pemesanan">
            @foreach ($days as $day)
                <a href="{{ route('keti.rooms.calendar', [...$filters, 'tanggal' => $day->toDateString(), 'durasi' => $duration]) }}"
                   @if ($day->equalTo($date)) aria-current="date" @endif
                   @class(['flex min-h-11 min-w-24 flex-1 shrink-0 snap-start flex-col items-center justify-center gap-0.5 rounded-control border px-3 py-2 text-xs transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary sm:min-h-12 sm:min-w-28 sm:gap-1 sm:px-4 sm:py-3 sm:text-sm', 'border-primary bg-primary text-white' => $day->equalTo($date), 'border-border bg-surface text-ink hover:bg-primary-soft' => ! $day->equalTo($date)])>
                    <span class="font-semibold">{{ $day->equalTo($today) ? 'Hari ini' : Wita::dayName($day) }}</span>
                    <span>{{ Wita::date($day) }}</span>
                </a>
            @endforeach
        </nav>

        <x-card padding="p-3 sm:p-4 lg:p-6" x-data="{ filtersOpen: false }">
            <div class="flex items-center justify-between gap-2 lg:hidden">
                <div class="flex min-w-0 flex-wrap items-center gap-1.5">
                    <button type="button" x-on:click="filtersOpen = ! filtersOpen" class="inline-flex cursor-pointer items-center gap-1 rounded-full bg-page px-2.5 py-1 text-xs font-medium text-ink ring-1 ring-border">
                        <i data-lucide="clock" aria-hidden="true" class="size-3 text-primary"></i>
                        <span>{{ $durationChip }}</span>
                    </button>
                    @if (filled($filters['gedung'] ?? null))
                        <button type="button" x-on:click="filtersOpen = ! filtersOpen" class="inline-flex max-w-36 cursor-pointer items-center truncate rounded-full bg-primary-soft px-2.5 py-1 text-xs font-semibold text-primary">
                            {{ $buildingOptions[$filters['gedung']] ?? $filters['gedung'] }}
                        </button>
                    @endif
                    @if (filled($filters['orang'] ?? null))
                        <button type="button" x-on:click="filtersOpen = ! filtersOpen" class="inline-flex cursor-pointer items-center gap-1 rounded-full bg-primary-soft px-2.5 py-1 text-xs font-semibold text-primary">
                            <i data-lucide="users" aria-hidden="true" class="size-3"></i>
                            <span>≥ {{ $filters['orang'] }} org</span>
                        </button>
                    @endif
                    @if (filled($filters['q'] ?? null))
                        <button type="button" x-on:click="filtersOpen = ! filtersOpen" class="inline-flex max-w-32 cursor-pointer items-center truncate rounded-full bg-primary-soft px-2.5 py-1 text-xs font-semibold text-primary">
                            "{{ $filters['q'] }}"
                        </button>
                    @endif
                </div>
                <button type="button"
                        x-on:click="filtersOpen = ! filtersOpen"
                        aria-controls="calendar-filters-body"
                        aria-expanded="false"
                        :aria-expanded="filtersOpen.toString()"
                        class="relative inline-flex min-h-10 shrink-0 cursor-pointer items-center justify-center gap-1.5 rounded-control border border-border bg-page px-3 text-xs font-semibold text-ink transition hover:border-primary/40 hover:bg-primary-soft/50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                        :class="filtersOpen ? 'border-primary bg-primary-soft/60 text-primary' : ''">
                    <i data-lucide="sliders-horizontal" aria-hidden="true" class="size-4 shrink-0"></i>
                    <span>Filter</span>
                    @if ($activeFilterCount > 0)
                        <span class="inline-flex size-4.5 items-center justify-center rounded-full bg-primary text-[10px] font-bold text-white">{{ $activeFilterCount }}</span>
                    @endif
                </button>
            </div>

            <form id="calendar-filters-body" method="GET" action="{{ route('keti.rooms.calendar') }}"
                  class="mt-3 hidden flex-col gap-3 border-t border-border/70 pt-3 lg:mt-0 lg:block lg:border-0 lg:pt-0"
                  :class="{ 'hidden': ! filtersOpen, 'flex': filtersOpen }">
                <div class="grid grid-cols-2 gap-2.5 lg:grid-cols-3 lg:gap-4">
                    <x-field label="Tanggal" name="tanggal" class="col-span-2 sm:col-span-1">
                        <x-select name="tanggal" :options="$days->mapWithKeys(fn ($day) => [$day->toDateString() => Wita::dayName($day).', '.Wita::date($day)])" :value="$date->toDateString()" />
                    </x-field>
                    <x-field label="Durasi pemakaian" name="durasi">
                        <x-select name="durasi" :options="$durationOptions" :value="$duration" />
                    </x-field>
                    <x-field label="Jumlah orang" name="orang">
                        <x-input type="number" name="orang" min="1" max="500" :value="$filters['orang'] ?? null" placeholder="Bebas" />
                    </x-field>
                    <x-field label="Nama ruangan" name="q">
                        <x-input name="q" :value="$filters['q'] ?? null" maxlength="60" placeholder="Contoh: AE 108" />
                    </x-field>
                    <x-field label="Gedung" name="gedung">
                        <x-select name="gedung" :options="$buildingOptions" :value="$filters['gedung'] ?? null" placeholder="Semua gedung" />
                    </x-field>
                    <div class="col-span-2 flex flex-wrap items-end gap-2 lg:col-span-1 lg:gap-3">
                        <x-button type="submit" icon="calendar-days" class="min-h-11 flex-1 sm:flex-none">Tampilkan slot</x-button>
                        <x-button variant="secondary" :href="route('keti.rooms.calendar')" class="min-h-11 flex-1 sm:flex-none">Atur ulang</x-button>
                    </div>
                </div>
            </form>
        </x-card>

        @if (! $scheduleConfirmed)
            <x-info-box title="Jadwal resmi belum ditetapkan">
                <p>Ketersediaan ruang pada tanggal ini belum dapat dipastikan. Slot pemesanan akan tampil setelah jadwal resmi ditetapkan.</p>
            </x-info-box>
        @endif

        @forelse ($rows as $row)
            <x-card padding="p-4 sm:p-6" class="flex flex-col gap-4 transition hover:border-primary/40">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border pb-4">
                    <div class="flex min-w-0 items-center gap-3.5">
                        <div class="flex size-11 shrink-0 items-center justify-center rounded-control border border-primary/20 bg-primary-soft text-sm font-bold text-primary">
                            {{ $row['room']->building?->code ?? 'RM' }}
                        </div>
                        <div class="flex min-w-0 flex-col gap-0.5">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="text-lg font-bold text-ink">{{ $row['room']->name }}</h3>
                                <x-status-badge variant="success">{{ count($row['windows']) }} rentang kosong</x-status-badge>
                            </div>
                            <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted">
                                <span>{{ $row['room']->building?->name ?? 'Gedung belum didata' }}{{ $row['room']->floor ? ' · Lantai '.$row['room']->floor : '' }}</span>
                                <span>•</span>
                                <span class="inline-flex items-center gap-1 font-medium text-ink">
                                    <i data-lucide="users" class="size-3.5 text-muted"></i>
                                    {{ $row['room']->capacity === null ? 'Kapasitas belum didata' : $row['room']->capacity.' kursi' }}
                                </span>
                            </p>
                        </div>
                    </div>
                </div>

                @if ($row['room']->facilities->isNotEmpty())
                    <div class="flex flex-wrap items-center gap-1.5 pt-0.5">
                        <span class="mr-1 inline-flex items-center gap-1 text-xs font-semibold text-muted">
                            <i data-lucide="sparkles" class="size-3.5 text-muted"></i>
                            Fasilitas:
                        </span>
                        @foreach ($row['room']->facilities as $facility)
                            <span class="inline-flex items-center gap-1 rounded-control border border-border bg-page px-2.5 py-1 text-xs font-medium text-ink">
                                <i data-lucide="check" class="size-3 text-primary"></i>
                                {{ $facility->name }}
                            </span>
                        @endforeach
                    </div>
                @endif

                <div class="flex flex-col divide-y divide-border">
                    @foreach ($row['windows'] as $window)
                        @php
                            $selectId = 'mulai-'.$row['room']->id.'-'.$loop->index;
                            $location = ($row['room']->building?->name ?? 'Gedung belum didata').', Lantai '.($row['room']->floor ?? '—');
                            $payloads = collect($window['starts'])->mapWithKeys(fn ($start) => [
                                $start->format('Y-m-d H:i') => BookingPayload::make($row['room']->id, $row['room']->name, $location, $row['room']->capacity, $start, $duration, isset($filters['orang']) && filled($filters['orang']) ? (int) $filters['orang'] : null),
                            ]);
                        @endphp
                        <form method="GET" action="{{ route('keti.rooms.show', $row['room']) }}" class="grid items-end gap-3 py-3.5 first:pt-1 last:pb-0 sm:grid-cols-[auto_1fr_auto]"
                              x-data="{ start: @js($payloads->keys()->first()), payloads: @js($payloads) }">
                            <div class="flex min-w-0 flex-col justify-center rounded-control border border-border bg-page px-3.5 py-2 sm:min-w-44">
                                <span class="text-xs text-muted">Kosong pada</span>
                                <p class="text-sm font-bold text-primary">{{ Wita::range($window['start'], $window['end']) }}</p>
                            </div>
                            <div class="flex min-w-0 flex-col gap-1.5">
                                <div class="flex flex-wrap items-center justify-between gap-2 text-xs">
                                    <label for="{{ $selectId }}" class="font-medium text-ink">Pilih waktu pemakaian</label>
                                    <span class="text-muted">Durasi: {{ $duration }} menit</span>
                                </div>
                                <x-select name="mulai" :id="$selectId" :options="collect($window['starts'])->mapWithKeys(fn ($start) => [$start->format('Y-m-d H:i') => Wita::range($start, $start->addMinutes($duration))])" x-model="start" />
                            </div>
                            <input type="hidden" name="durasi" value="{{ $duration }}">
                            @if (isset($filters['orang']))
                                <input type="hidden" name="orang" value="{{ $filters['orang'] }}">
                            @endif
                            <div class="flex items-center justify-between gap-3 sm:justify-end">
                                <button type="submit" class="inline-flex min-h-11 cursor-pointer items-center px-2 text-sm font-semibold text-primary hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">Detail</button>
                                {{-- Pesan langsung: buka modal konfirmasi dengan jam yang dipilih, tanpa pindah halaman. --}}
                                <x-button x-on:click="$dispatch('open-booking', payloads[start])" :aria-label="'Pesan '.$row['room']->name.' pada '.Wita::range($window['start'], $window['end'])" class="min-h-11 flex-1 whitespace-nowrap px-6 sm:flex-none">
                                    <span>Pesan</span>
                                    <i data-lucide="arrow-right" class="size-4"></i>
                                </x-button>
                            </div>
                        </form>
                    @endforeach
                </div>
            </x-card>
        @empty
            <x-empty-state title="Tidak ada slot yang bisa dipesan" message="Belum ada waktu kosong yang dapat dipastikan untuk kebutuhan ini. Coba tanggal lain, kurangi durasi, atau ubah filter." icon="calendar-days" />
        @endforelse
    </div>
@endsection

@push('dialogs')
    @include('keti.partials.booking-modal', ['academicAllowed' => \App\Services\Room\ReservationService::allowsAcademicPurposes($date)])
@endpush
