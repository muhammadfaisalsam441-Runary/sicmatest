@use('App\Support\HomeRoute')
@use('App\Support\Nav')
@extends('layouts.public')

@section('title', 'Ruangan')

@php
    $tabQuery = fn (?string $statusValue) => array_filter(['q' => $keyword, 'gedung' => $building, 'status' => $statusValue]);
@endphp

@section('content')
    <div class="flex flex-col gap-6">
        <div class="flex flex-wrap items-center gap-3 sm:gap-4">
            <x-button variant="secondary" :href="route('home')" data-prefetch class="w-full sm:w-52">Kembali ke beranda</x-button>
            <x-status-badge variant="primary">Akses publik • Hanya lihat</x-status-badge>
        </div>

        <div class="flex flex-col items-start justify-between gap-4 sm:flex-row sm:gap-6">
            <div class="flex flex-col gap-2">
                <h1 class="text-2xl font-semibold text-ink sm:text-3xl">{{ $title }}</h1>
                <p class="text-sm text-ink">{{ count($rooms) }} ruang ditemukan • {{ $buildingLabel }}</p>
            </div>
            @include('public.partials.sim-label-button')
        </div>

        <search>
            <form method="GET" action="{{ route('public.rooms') }}" class="flex flex-col items-stretch gap-3 sm:flex-row sm:items-center">
                @if ($status)<input type="hidden" name="status" value="{{ $status }}">@endif
                <label class="relative min-w-0 flex-1">
                    <span class="sr-only">Cari nama ruangan</span>
                    <i data-lucide="search" aria-hidden="true" class="pointer-events-none absolute top-1/2 left-4 size-4 -translate-y-1/2 text-muted"></i>
                    <input type="search" name="q" value="{{ $keyword }}" placeholder="Cari nama ruangan"
                           class="min-h-11 w-full rounded-control border border-muted/60 bg-surface py-2.5 pr-4 pl-11 text-base text-ink placeholder:text-muted focus:border-primary focus:ring-2 focus:ring-primary/15 focus:outline-none sm:py-3 sm:text-sm">
                </label>
                <select name="gedung" aria-label="Filter gedung" onchange="this.form.requestSubmit()"
                        class="min-h-11 w-full rounded-control border border-muted/60 bg-surface px-4 py-2.5 text-base text-ink hover:border-primary focus:border-primary focus:ring-2 focus:ring-primary/15 focus:outline-none sm:w-64 sm:py-3 sm:text-sm">
                    <option value="">Semua gedung ({{ $summary['all'] }})</option>
                    @foreach ($buildings as $buildingOption)
                        <option value="{{ $buildingOption->id }}" @selected((string) $building === (string) $buildingOption->id)>
                            {{ $buildingOption->name }} ({{ $buildingOption->rooms_count }})
                        </option>
                    @endforeach
                    @if ($roomsWithoutBuilding > 0)
                        <option value="lainnya" @selected($building === 'lainnya')>
                            Gedung belum didata ({{ $roomsWithoutBuilding }})
                        </option>
                    @endif
                </select>
                <x-button type="submit" variant="secondary" class="w-full sm:w-32">Perbarui</x-button>
            </form>
        </search>

        <div class="flex items-center justify-between">
            <div class="flex min-w-0 flex-col gap-3">
                <span class="text-xs text-ink">Ringkasan status di semua gedung</span>
                @php
                    $tabs = [
                        ['label' => 'Semua', 'count' => $summary['all'], 'href' => route('public.rooms', $tabQuery(null)), 'active' => $status === null],
                        ['label' => 'Tersedia', 'count' => $summary['available'], 'href' => route('public.rooms', $tabQuery('tersedia')), 'active' => $status === 'tersedia'],
                        ['label' => 'Digunakan', 'count' => $summary['busy'], 'href' => route('public.rooms', $tabQuery('digunakan')), 'active' => $status === 'digunakan'],
                    ];
                    if (($summary['closed'] ?? 0) > 0) {
                        $tabs[] = ['label' => 'Tutup', 'count' => $summary['closed'], 'href' => route('public.rooms', $tabQuery('tutup')), 'active' => $status === 'tutup'];
                    }
                @endphp
                <x-tabs :items="$tabs" />
            </div>
        </div>

        @if ($rooms === [])
            <x-empty-state title="Ruangan tidak ditemukan" message="Periksa kembali nama ruangan atau tampilkan semua ruangan JTIK.">
                <x-button variant="soft" :href="route('public.rooms')">Tampilkan semua ruangan</x-button>
            </x-empty-state>
        @else
            <div class="grid grid-cols-1 gap-3 md:grid-cols-2 lg:grid-cols-3">
                @foreach ($rooms as $room)
                    <x-room-card :name="$room['name']" :badge="$room['badge']" :location="$room['location']" :capacity="$room['capacity']"
                                 :time-label="$room['time_label']" :time="$room['time']" :note="$room['note']"
                                 style="--stagger: {{ $loop->index }}" />
                @endforeach
            </div>
        @endif

        <p class="text-xs text-muted">Status mengikuti pembaruan data. Pengunjung publik hanya melihat informasi ruang.</p>

        <section class="flex flex-col items-start justify-between gap-4 rounded-card border border-border bg-surface p-5 sm:flex-row sm:items-center sm:gap-6 sm:px-6 sm:py-5">
            <p class="min-w-0 text-sm text-ink">Perlu memesan ruangan untuk kelasmu? Gunakan akun Keti yang terdaftar.
                <button type="button" x-data x-on:click="$dispatch('open-modal', 'bantuan-akun-keti')" class="cursor-pointer rounded font-semibold text-primary hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">Bantuan akun Keti</button>
            </p>
            @auth
                <x-button :href="HomeRoute::for(auth()->user())" animated class="w-full shrink-0 sm:w-auto">Buka SICMA</x-button>
            @else
                <x-button :href="Nav::url('login')" animated class="w-full shrink-0 sm:w-auto">Masuk sebagai Keti untuk memesan</x-button>
            @endauth
        </section>
    </div>
@endsection

@push('dialogs')
    @include('public.partials.dialogs')
@endpush
