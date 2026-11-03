@extends('layouts.keti')

@section('title', 'Pemesanan gagal')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header title="Pemesanan gagal" subtitle="Informasi ruangan dan pemakaian kelasmu." />
        <x-error-state title="Ruangan gagal dipesan" :message="$error['message']">
            <div class="mt-2 flex gap-3">
                <x-button :href="route('keti.search')">Cari ruangan lain</x-button>
                <x-button variant="secondary" :href="route('keti.search', ['tab' => 'filter'])">Ubah filter</x-button>
            </div>
        </x-error-state>
        <p class="text-center text-xs text-muted">Kode: {{ $error['code'] }}</p>
    </div>
@endsection
