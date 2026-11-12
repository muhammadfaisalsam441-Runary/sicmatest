@use('App\Support\HomeRoute')
@use('App\Support\Nav')
@extends('layouts.public')

@section('title', 'Ruangan')

@php
    $tabQuery = fn (?string $statusValue) => array_filter(['q' => $keyword, 'gedung' => $building, 'status' => $statusValue]);
@endphp

@section('content')
    <div class="flex flex-col gap-6">
        <div class="flex items-center gap-4">
            <x-button variant="secondary" :href="route('home')" class="w-52">Kembali ke beranda</x-button>
            <x-status-badge variant="primary">Akses publik • Hanya lihat</x-status-badge>
        </div>

        <div class="flex items-start justify-between gap-6">
            <div class="flex flex-col gap-2">
                <h1 class="text-3xl font-semibold text-ink">{{ $title }}</h1>
                <p class="text-sm text-ink">{{ count($rooms) }} ruang ditemukan • {{ $buildingLabel }}</p>
            </div>
            @include('public.partials.sim-label-button')
        </div>

        <form method="GET" action="{{ route('public.rooms') }}" class="flex items-center gap-3" role="search">
            @if ($status)<input type="hidden" name="status" value="{{ $status }}">@endif
            <label class="relative flex-1">
                <span class="sr-only">Cari nama ruangan</span>
                <i data-lucide="search" class="pointer-events-none absolute top-1/2 left-4 size-4 -translate-y-1/2 text-muted"></i>
                <input type="search" name="q" value="{{ $keyword }}" placeholder="Cari nama ruangan"
                       class="w-full rounded-control border border-muted/60 bg-surface py-3 pr-4 pl-11 text-sm text-ink placeholder:text-muted focus:border-primary focus:ring-2 focus:ring-primary/15 focus:outline-none">
            </label>
            <select name="gedung" onchange="this.form.requestSubmit()"
                    class="w-64 rounded-control border border-muted/60 bg-surface px-4 py-3 text-sm text-ink hover:border-primary focus:border-primary focus:ring-2 focus:ring-primary/15 focus:outline-none">
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
            <x-button type="submit" variant="secondary" class="w-32">Perbarui</x-button>
        </form>

        <div class="flex items-center justify-between">
            <div class="flex flex-col gap-3">
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
            <div class="grid grid-cols-3 gap-3">
                @foreach ($rooms as $room)
                    <x-room-card :name="$room['name']" :badge="$room['badge']" :location="$room['location']" :capacity="$room['capacity']"
                                 :time-label="$room['time_label']" :time="$room['time']" :note="$room['note']" />
                @endforeach
            </div>
        @endif

        <p class="text-xs text-muted">Status mengikuti pembaruan data. Pengunjung publik hanya melihat informasi ruang.</p>

        <section class="flex items-center justify-between gap-6 rounded-card border border-border bg-surface px-6 py-5">
            <p class="text-sm text-ink">Perlu memesan ruangan untuk kelasmu? Gunakan akun Keti yang terdaftar.
                <button type="button" x-data x-on:click="$dispatch('open-modal', 'bantuan-akun-keti')" class="font-semibold text-primary hover:underline">Bantuan akun Keti</button>
            </p>
            @auth
                <x-button :href="HomeRoute::for(auth()->user())" class="shrink-0 px-9">Buka SICMA</x-button>
            @else
                <x-button :href="Nav::url('login')" class="shrink-0 px-9">Masuk sebagai Keti untuk memesan</x-button>
            @endauth
        </section>
    </div>
@endsection

@push('dialogs')
    @include('public.partials.dialogs')
@endpush
