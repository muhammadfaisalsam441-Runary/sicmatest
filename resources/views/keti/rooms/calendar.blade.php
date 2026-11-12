@use('App\Support\BookingPayload')
@use('App\Support\Wita')
@extends('layouts.keti')

@section('title', 'Booking Ruangan')

@php
    $sksOptions = config('sicma.reservation.duration_options');
    $durationOptions = collect($sksOptions)
        ->when(! array_key_exists($duration, $sksOptions), fn ($options) => $options->put($duration, Wita::duration($duration)))
        ->sortKeys()->all();
    $buildingOptions = $buildings->pluck('name', 'id')->put('lainnya', 'Gedung belum didata');
@endphp

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header title="Booking Ruangan" subtitle="Pilih waktu kosong dan pesan ruangan untuk kelasmu." />
        @include('keti.partials.search-tabs', ['active' => 'kalender'])
        <p class="text-sm text-muted">Kalender pemesanan menampilkan hari Senin–Jumat.</p>

        @if ($showingNextTeachingDay)
            <x-info-box title="Booking tetap bisa dilakukan sekarang">
                <p>Berikut slot pada hari kuliah berikutnya: {{ Wita::dayName($date) }}, {{ Wita::date($date) }}. Pemakaian mengikuti jam operasional.</p>
            </x-info-box>
        @endif

        <x-card>
            <form method="GET" action="{{ route('keti.rooms.calendar') }}" class="flex flex-col gap-4">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <x-field label="Tanggal" name="tanggal">
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
                    <div class="flex flex-wrap items-end gap-3">
                        <x-button type="submit" icon="calendar-days">Tampilkan slot</x-button>
                        <x-button variant="secondary" :href="route('keti.rooms.calendar')">Atur ulang</x-button>
                    </div>
                </div>
            </form>
        </x-card>

        <nav class="flex gap-2 overflow-x-auto pb-2" aria-label="Pilih hari pemesanan">
            @foreach ($days as $day)
                <a href="{{ route('keti.rooms.calendar', [...$filters, 'tanggal' => $day->toDateString(), 'durasi' => $duration]) }}"
                   @if ($day->equalTo($date)) aria-current="date" @endif
                   @class(['flex min-w-28 flex-1 flex-col items-center gap-1 rounded-control border px-4 py-3 text-sm', 'border-primary bg-primary text-white' => $day->equalTo($date), 'border-border bg-surface text-ink hover:bg-primary-soft' => ! $day->equalTo($date)])>
                    <span class="font-semibold">{{ $day->equalTo($today) ? 'Hari ini' : Wita::dayName($day) }}</span>
                    <span>{{ Wita::date($day) }}</span>
                </a>
            @endforeach
        </nav>

        @if (! $scheduleConfirmed)
            <x-info-box title="Jadwal resmi belum ditetapkan">
                <p>Ketersediaan ruang pada tanggal ini belum dapat dipastikan. Slot pemesanan akan tampil setelah jadwal resmi ditetapkan.</p>
            </x-info-box>
        @endif

        @forelse ($rows as $row)
            <x-card class="flex flex-col gap-4 p-5 sm:p-6 transition hover:border-primary/40">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border pb-4">
                    <div class="flex items-center gap-3.5">
                        <div class="flex size-11 shrink-0 items-center justify-center rounded-control border border-primary/20 bg-primary-soft text-sm font-bold text-primary">
                            {{ $row['room']->building?->code ?? 'RM' }}
                        </div>
                        <div class="flex flex-col gap-0.5">
                            <div class="flex items-center gap-2">
                                <h3 class="text-lg font-bold text-ink">{{ $row['room']->name }}</h3>
                                <x-status-badge variant="success">{{ count($row['windows']) }} rentang kosong</x-status-badge>
                            </div>
                            <p class="flex items-center gap-2 text-xs text-muted">
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
                            $location = ($row['room']->building?->name ?? 'Gedung belum didata').' • Lantai '.($row['room']->floor ?? '—');
                            $payloads = collect($window['starts'])->mapWithKeys(fn ($start) => [
                                $start->format('Y-m-d H:i') => BookingPayload::make($row['room']->id, $row['room']->name, $location, $row['room']->capacity, $start, $duration, isset($filters['orang']) && filled($filters['orang']) ? (int) $filters['orang'] : null),
                            ]);
                        @endphp
                        <form method="GET" action="{{ route('keti.rooms.show', $row['room']) }}" class="grid items-end gap-3 py-3.5 first:pt-1 last:pb-0 sm:grid-cols-[auto_1fr_auto]"
                              x-data="{ start: @js($payloads->keys()->first()), payloads: @js($payloads) }">
                            <div class="flex min-w-44 flex-col justify-center rounded-control border border-border bg-page px-3.5 py-2">
                                <span class="text-xs text-muted">Kosong pada</span>
                                <p class="text-sm font-bold text-primary">{{ Wita::range($window['start'], $window['end']) }}</p>
                            </div>
                            <div class="flex flex-col gap-1.5">
                                <div class="flex items-center justify-between text-xs">
                                    <label for="{{ $selectId }}" class="font-medium text-ink">Pilih waktu pemakaian</label>
                                    <span class="text-muted">Durasi: {{ $duration }} menit</span>
                                </div>
                                <x-select name="mulai" :id="$selectId" :options="collect($window['starts'])->mapWithKeys(fn ($start) => [$start->format('Y-m-d H:i') => Wita::range($start, $start->addMinutes($duration))])" x-model="start" />
                            </div>
                            <input type="hidden" name="durasi" value="{{ $duration }}">
                            @if (isset($filters['orang']))
                                <input type="hidden" name="orang" value="{{ $filters['orang'] }}">
                            @endif
                            <div class="flex items-center gap-3">
                                <button type="submit" class="text-sm font-semibold text-primary hover:underline">Detail</button>
                                {{-- Pesan langsung: buka modal konfirmasi dengan jam yang dipilih, tanpa pindah halaman. --}}
                                <x-button x-on:click="$dispatch('open-booking', payloads[start])" :aria-label="'Pesan '.$row['room']->name.' pada '.Wita::range($window['start'], $window['end'])" class="whitespace-nowrap px-6">
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
