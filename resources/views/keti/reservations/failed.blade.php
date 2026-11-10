@use('App\Support\Wita')
@extends('layouts.keti')

@section('title', 'Pemesanan gagal')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header title="Pemesanan gagal" subtitle="Informasi ruangan dan pemakaian kelasmu." />
        <x-error-state title="Ruangan gagal dipesan" :message="$error['message']">
            <div class="mt-2 flex gap-3">
                <x-button :href="route('keti.search')">Cari ruangan lain</x-button>
                <x-button variant="secondary" :href="route('keti.search', array_filter(['tanggal' => $start?->toDateString(), 'mulai' => $start?->format('H:i'), 'durasi' => $duration]))">Ubah filter</x-button>
            </div>
        </x-error-state>

        @if ($alternatives !== [])
            <x-card class="flex flex-col gap-4">
                <div class="flex flex-col gap-1">
                    <h2 class="text-lg font-semibold">Ruangan lain yang kosong di jam yang sama</h2>
                    <p class="text-sm text-muted">{{ Wita::date($start) }} • {{ Wita::range($start, $start->addMinutes($duration)) }}</p>
                </div>
                <ul class="flex flex-col divide-y divide-border">
                    @foreach ($alternatives as $alternative)
                        <li class="flex items-center justify-between gap-4 py-3">
                            <div class="flex flex-col gap-0.5">
                                <span class="font-semibold text-ink">{{ $alternative['name'] }}</span>
                                <span class="text-xs text-muted">{{ $alternative['location'] }}{{ $alternative['until'] ? ' • tersedia sampai '.Wita::clock($alternative['until']) : '' }}</span>
                            </div>
                            <x-button x-data x-on:click="$dispatch('open-booking', {{ \Illuminate\Support\Js::from($alternative['booking']) }})" :aria-label="'Pesan '.$alternative['name']">Pesan</x-button>
                        </li>
                    @endforeach
                </ul>
            </x-card>
        @endif

        <p class="text-center text-xs text-muted">Kode: {{ $error['code'] }}</p>
    </div>
@endsection

@push('dialogs')
    @if ($alternatives !== [])
        @include('keti.partials.booking-modal', ['academicAllowed' => $academicAllowed])
    @endif
@endpush
