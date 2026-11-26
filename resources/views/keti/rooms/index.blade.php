@extends('layouts.keti')

@section('title', 'Ruangan JTIK')

@php
    $buildingOptions = $buildings->pluck('name', 'id')->put('lainnya', 'Gedung belum didata');
    $floorOptions = $floors->mapWithKeys(fn ($floor) => [$floor => 'Lantai '.$floor]);
    $statusOptions = ['tersedia' => 'Tersedia', 'digunakan' => 'Digunakan', 'tutup' => 'Tutup'];
    $activeFilterCount = (filled($filters['gedung'] ?? null) ? 1 : 0)
        + (filled($filters['lantai'] ?? null) ? 1 : 0)
        + (filled($filters['status'] ?? null) ? 1 : 0);
@endphp

@section('content')
    <div class="flex flex-col gap-4 sm:gap-6">
        <x-page-header title="Ruangan JTIK" subtitle="{{ $totalRooms }} ruangan terdaftar" />

        <search x-data="{ filtersOpen: false }">
            <form method="GET" action="{{ route('keti.rooms.index') }}" class="flex flex-col gap-2.5 rounded-card border border-border bg-surface p-3 sm:p-4 lg:flex-row lg:items-center lg:gap-3">
                <div class="grid grid-cols-[1fr_auto_auto] items-center gap-2 lg:contents">
                    <label class="relative min-w-0 lg:flex-1">
                        <span class="sr-only">Cari nama ruangan</span>
                        <i data-lucide="search" aria-hidden="true" class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-muted"></i>
                        <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Cari nama ruangan"
                               autocorrect="off" autocapitalize="none" spellcheck="false"
                               class="min-h-11 w-full rounded-control border border-muted/60 py-2.5 pr-3 pl-10 text-base focus:border-primary focus:ring-2 focus:ring-primary/15 focus:outline-none sm:text-sm">
                    </label>
                    <button type="button"
                            x-on:click="filtersOpen = ! filtersOpen"
                            aria-controls="keti-rooms-filters"
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
                    <x-button type="submit" icon="search" class="min-h-11 shrink-0 px-3.5 lg:hidden">
                        <span class="sr-only">Cari</span>
                    </x-button>
                </div>

                @if ($activeFilterCount > 0)
                    <div class="flex flex-wrap items-center gap-1.5 lg:hidden">
                        @if (filled($filters['gedung'] ?? null))
                            <button type="button" x-on:click="filtersOpen = ! filtersOpen" class="inline-flex cursor-pointer items-center rounded-full bg-primary-soft px-2.5 py-1 text-xs font-semibold text-primary">
                                {{ $buildingOptions[$filters['gedung']] ?? $filters['gedung'] }}
                            </button>
                        @endif
                        @if (filled($filters['lantai'] ?? null))
                            <button type="button" x-on:click="filtersOpen = ! filtersOpen" class="inline-flex cursor-pointer items-center rounded-full bg-primary-soft px-2.5 py-1 text-xs font-semibold text-primary">
                                {{ $floorOptions[$filters['lantai']] ?? 'Lantai '.$filters['lantai'] }}
                            </button>
                        @endif
                        @if (filled($filters['status'] ?? null))
                            <button type="button" x-on:click="filtersOpen = ! filtersOpen" class="inline-flex cursor-pointer items-center rounded-full bg-primary-soft px-2.5 py-1 text-xs font-semibold text-primary">
                                {{ $statusOptions[$filters['status']] ?? $filters['status'] }}
                            </button>
                        @endif
                    </div>
                @endif

                <div id="keti-rooms-filters"
                     class="hidden grid-cols-2 gap-2.5 border-t border-border/70 pt-2.5 lg:contents lg:border-0 lg:pt-0"
                     :class="{ 'hidden': ! filtersOpen, 'grid': filtersOpen }">
                    <x-select name="gedung" :options="$buildingOptions" :value="$filters['gedung'] ?? null" placeholder="Semua gedung" class="col-span-2 w-full sm:col-span-1 lg:w-44" aria-label="Gedung" />
                    <x-select name="lantai" :options="$floorOptions" :value="$filters['lantai'] ?? null" placeholder="Semua lantai" class="w-full lg:w-36" aria-label="Lantai" />
                    <x-select name="status" :options="$statusOptions" :value="$filters['status'] ?? null" placeholder="Semua status" class="w-full lg:w-36" aria-label="Status" />
                    <div class="col-span-2 flex items-center gap-2 lg:col-auto lg:shrink-0">
                        <x-button type="submit" class="min-h-11 flex-1 lg:flex-none">Terapkan</x-button>
                        <x-button variant="secondary" :href="route('keti.rooms.index')" class="min-h-11 flex-1 lg:flex-none">Reset</x-button>
                    </div>
                </div>
            </form>
        </search>

        <div class="flex items-center justify-between gap-2">
            <h2 class="text-base font-semibold sm:text-lg">Daftar ruangan</h2>
            <x-button :href="route('keti.search')" size="sm" icon="search" class="min-h-10 shrink-0 sm:min-h-11 sm:px-5 sm:py-3 sm:text-sm">Cari kosong</x-button>
        </div>

        @if ($rooms === [])
            <x-empty-state title="Ruangan tidak ditemukan" message="Ubah kata kunci atau filter untuk melihat ruangan lain.">
                <x-button variant="soft" :href="route('keti.rooms.index')" class="min-h-11 sm:min-h-0">Tampilkan semua ruangan</x-button>
            </x-empty-state>
        @else
            <div class="grid grid-cols-1 gap-3 md:grid-cols-2 lg:grid-cols-3">
                @foreach ($rooms as $room)
                    <a href="{{ route('keti.rooms.show', $room['id']) }}" class="block rounded-card transition hover:ring-2 hover:ring-primary/20 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                        <x-room-card :name="$room['name']" :badge="$room['badge']" :location="$room['location']" :capacity="$room['capacity']" :facilities="$room['facilities'] ?? []"
                                     :time-label="$room['time_label']" :time="$room['time']" :remaining="$room['remaining'] ?? null" :note="$room['note']"
                                     :agenda-title="$room['agenda_title'] ?? null" :agenda-class="$room['agenda_class'] ?? null" :agenda-range="$room['agenda_range'] ?? null" :agenda-is-upcoming="$room['agenda_is_upcoming'] ?? false"
                                     style="--stagger: {{ $loop->index }}" class="h-full" />
                    </a>
                @endforeach
            </div>
        @endif
    </div>
@endsection
