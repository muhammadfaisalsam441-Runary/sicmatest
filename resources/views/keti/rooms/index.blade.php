@extends('layouts.keti')

@section('title', 'Ruangan JTIK')

@section('content')
    <div class="flex flex-col gap-6">
        <div class="flex items-start justify-between">
            <x-page-header title="Ruangan JTIK" subtitle="{{ $totalRooms }} ruangan terdaftar" />
            <span class="text-xs text-muted">{{ \App\Support\Wita::stamp($checkedAt) }}</span>
        </div>

        <form method="GET" action="{{ route('keti.rooms.index') }}" class="flex items-center gap-3 rounded-card border border-border bg-surface p-3">
            <label class="relative flex-1">
                <span class="sr-only">Cari nama ruangan</span>
                <i data-lucide="search" class="pointer-events-none absolute top-1/2 left-4 size-4 -translate-y-1/2 text-muted"></i>
                <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Cari nama ruangan"
                       class="w-full rounded-control border border-muted/60 py-3 pr-4 pl-11 text-sm focus:border-primary focus:ring-2 focus:ring-primary/15 focus:outline-none">
            </label>
            <x-select name="gedung" :options="$buildings->pluck('name', 'id')->put('lainnya', 'Gedung belum didata')" :value="$filters['gedung'] ?? null" placeholder="Semua gedung" class="w-48" aria-label="Gedung" />
            <x-select name="lantai" :options="$floors->mapWithKeys(fn ($floor) => [$floor => 'Lantai '.$floor])" :value="$filters['lantai'] ?? null" placeholder="Semua lantai" class="w-40" aria-label="Lantai" />
            <x-select name="status" :options="['tersedia' => 'Tersedia', 'digunakan' => 'Digunakan', 'tutup' => 'Tutup']" :value="$filters['status'] ?? null" placeholder="Semua status" class="w-40" aria-label="Status" />
            <x-button type="submit">Terapkan</x-button>
            <x-button variant="secondary" :href="route('keti.rooms.index')">Reset</x-button>
        </form>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-col gap-1">
                <h2 class="text-lg font-semibold">Daftar ruangan</h2>
                <p class="text-sm text-muted">Status menunjukkan kondisi saat ini. Kamu tetap bisa booking untuk tanggal dan jam operasional berikutnya.</p>
            </div>
            <x-button :href="route('keti.rooms.calendar')" icon="calendar-days">Booking ruangan</x-button>
        </div>

        @if ($rooms === [])
            <x-empty-state title="Ruangan tidak ditemukan" message="Ubah kata kunci atau filter untuk melihat ruangan lain.">
                <x-button variant="soft" :href="route('keti.rooms.index')">Tampilkan semua ruangan</x-button>
            </x-empty-state>
        @else
            <div class="grid grid-cols-3 gap-3">
                @foreach ($rooms as $room)
                    <a href="{{ route('keti.rooms.show', $room['id']) }}" class="block rounded-card transition hover:ring-2 hover:ring-primary/20">
                        <x-room-card :name="$room['name']" :badge="$room['badge']" :location="$room['location']" :capacity="$room['capacity']"
                                     :time-label="$room['time_label']" :time="$room['time']" :note="$room['note']" class="h-full" />
                    </a>
                @endforeach
            </div>
        @endif
    </div>
@endsection
