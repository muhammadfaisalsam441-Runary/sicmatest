@extends('layouts.keti')

@section('title', 'Ruangan Favorit')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header title="Ruangan Favorit" subtitle="Buka kembali ruangan yang sering digunakan." />

        @if ($counts['all'] === 0)
            <x-empty-state title="Belum ada ruangan favorit" message="Temukan ruangan dan simpan untuk dibuka kembali." icon="heart">
                <x-button variant="soft" :href="route('keti.search')">Cari ruangan</x-button>
            </x-empty-state>
        @else
            <x-tabs :items="[
                ['label' => 'Semua', 'href' => route('keti.favorites.index'), 'active' => $status === null],
                ['label' => 'Tersedia', 'href' => route('keti.favorites.index', ['status' => 'tersedia']), 'active' => $status === 'tersedia'],
                ['label' => 'Digunakan', 'href' => route('keti.favorites.index', ['status' => 'digunakan']), 'active' => $status === 'digunakan'],
            ]" />
            <h2 class="text-lg font-semibold">{{ $status === 'tersedia' ? $counts['available'].' ruangan tersedia' : ($status === 'digunakan' ? $counts['busy'].' ruangan digunakan' : $counts['all'].' ruangan tersimpan') }}</h2>

            @if ($rooms === [])
                <x-empty-state title="Tidak ada ruangan favorit dengan status ini" message="Ruangan favorit tetap mengikuti ketersediaan saat ini." />
            @else
                <div class="grid grid-cols-3 gap-3">
                    @foreach ($rooms as $room)
                        <x-room-card :name="$room['name']" :badge="$room['badge']" :location="$room['location']" :capacity="$room['capacity']" :facilities="$room['facilities']"
                                     :time-label="$room['time_label']" :time="$room['time']" :note="$room['note']">
                            <div class="flex w-full gap-2">
                                <x-button variant="secondary" :href="route('keti.rooms.show', $room['id'])" class="flex-1">Detail</x-button>
                                @if ($room['badge'] === 'Tersedia')
                                    <x-button :href="route('keti.rooms.show', $room['id'])" class="flex-1">Pesan ruang</x-button>
                                @endif
                            </div>
                        </x-room-card>
                    @endforeach
                </div>
            @endif
            <p class="text-xs text-muted">Ruangan favorit tetap mengikuti ketersediaan saat ini. Pilih Detail ruangan untuk melihat waktu kosong dan riwayat pemakaian.</p>
        @endif
    </div>
@endsection
